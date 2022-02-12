<?php

namespace Drupal\gitlabgithubbridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for a custom landing page for github action runs.
 * The built-in page doesn't let you see what the job is unless you drill down
 * on each one.
 */
class ActionSummaryController extends ControllerBase {

  protected $config;

  /**
   * The system logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * WorkflowController constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   An instance of ConfigFactory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ConfigFactory $config, LoggerChannelFactoryInterface $logger_factory) {
    $this->config = $config->get('gitlabgithubbridge.settings');
    $this->logger = $logger_factory->get('gitlabgithubbridge');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory')
    );
  }

  /**
   * Look up github action runs and display a page with some drilled-down info
   * @return array
   */
  public function run(): array {
    $curl = curl_init();
    $cookie_file_path = tempnam(sys_get_temp_dir(), 'coo');
    $curl_params = [
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_HEADER => FALSE,
      CURLOPT_URL => 'https://api.github.com/repos/SemperIT/CiviCARROT/actions/runs?per_page=10',
      CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json'],
      CURLOPT_USERPWD => $this->config->get('gitlabgithubbridge.username') . ":" . $this->config->get('gitlabgithubbridge.password'),
      CURLOPT_POST => FALSE,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)',
      CURLOPT_SSL_VERIFYPEER => $this->config->get('gitlabgithubbridge.verifyssl'),
      CURLOPT_FOLLOWLOCATION => 1,
      CURLOPT_COOKIEFILE => $cookie_file_path,
      CURLOPT_COOKIEJAR => $cookie_file_path,
    ];
    curl_setopt_array($curl, $curl_params);

    $response = '';
    $exec_result = curl_exec($curl);
    if ($exec_result === FALSE) {
      $this->logger->debug("curlerr: " . curl_error($curl));
      $this->logger->debug(print_r(curl_getinfo($curl), TRUE));
    }
    else {
      $response = json_decode($exec_result, TRUE);
      if (empty($response)) {
        $this->logger->debug($exec_result);
        curl_close($curl);
        return ['#markup' => 'Error'];
      }
    }

    $success = \Drupal\Core\Render\Markup::create('<svg width="16" height="16" style="color: green; fill:currentColor;" class="octicon octicon-check-circle-fill" viewBox="0 0 16 16" version="1.1" aria-hidden="true"><path fill-rule="evenodd" d="M8 16A8 8 0 108 0a8 8 0 000 16zm3.78-9.72a.75.75 0 00-1.06-1.06L6.75 9.19 5.28 7.72a.75.75 0 00-1.06 1.06l2 2a.75.75 0 001.06 0l4.5-4.5z"></path></svg>');
    $failure = \Drupal\Core\Render\Markup::create('<svg width="16" height="16" style="color: red; fill:currentColor;" class="octicon octicon-x-circle-fill" viewBox="0 0 16 16" version="1.1" aria-hidden="true"><path fill-rule="evenodd" d="M2.343 13.657A8 8 0 1113.657 2.343 8 8 0 012.343 13.657zM6.03 4.97a.75.75 0 00-1.06 1.06L6.94 8 4.97 9.97a.75.75 0 101.06 1.06L8 9.06l1.97 1.97a.75.75 0 101.06-1.06L9.06 8l1.97-1.97a.75.75 0 10-1.06-1.06L8 6.94 6.03 4.97z"></path></svg>');

    $rows = [];
    foreach ($response['workflow_runs'] ?? [] as $counter => $run) {
      $data = ['name' => $run['name']];
      curl_setopt($curl, CURLOPT_URL, $run['jobs_url']);
      $exec_result = curl_exec($curl);
      if ($exec_result === FALSE) {
        $this->logger->debug("curlerr: " . curl_error($curl));
        $this->logger->debug(print_r(curl_getinfo($curl), TRUE));
      }
      else {
        $response = json_decode($exec_result, TRUE);
        if (empty($response)) {
          $this->logger->debug($exec_result);
        }
        else {
          foreach ($response['jobs'] ?? [] as $job) {
            $data['status'] = ($job['conclusion'] === 'success' ? $success : ($job['conclusion'] === 'failure' ? $failure : ''));
            $parameters = explode('|', $job['name']);
            $data['repo'] = str_replace('.git', '', basename(trim($parameters[0])));
            $data['cms'] = trim($parameters[1]);
            $data['civi'] = trim(str_replace('CiviCRM', '', $parameters[2]));
            $data['start'] = (new \DateTime($job['started_at']))->format('Y-m-d H:i');
            $data['duration'] = empty($job['completed_at']) ? '' : $this->getDiffInMinutes($job['started_at'], $job['completed_at']);
            $link = \Drupal\Core\Render\Markup::create('<a href="' . (new \Laminas\Escaper\Escaper('utf-8'))->escapeHtmlAttr($job['html_url']) . '">View Logs</a>');
            $data['url'] = $link;
            $rows[] = $data;
          }
        }
      }
    }

    $header = [
      'Type',
      'Status',
      'Repo',
      'CMS',
      'CiviCRM',
      'Start (GMT)',
      'Minutes',
      'View Logs',
    ];
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#cache' => ['max-age' => 10],
    ];
  }

  /**
   * @param string $d1
   * @param string $d2
   * @return string
   */
  private function getDiffInMinutes(string $d1, string $d2): string {
    $d1 = new \DateTime($d1);
    $d2 = new \DateTime($d2);
    $diff = $d1->diff($d2);
    return $diff->format('%i');
  }

}
