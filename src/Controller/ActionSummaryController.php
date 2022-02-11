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
            $data['description'] = $job['name'];
            $data['start'] = $job['started_at'];
            $data['end'] = $job['completed_at'];
            // must be passed by reference
            $link = ['#markup' => '<a href="' . (new \Laminas\Escaper\Escaper('utf-8'))->escapeHtmlAttr($job['html_url']) . '">View Logs</a>'];
            $data['url'] = \Drupal::service('renderer')->renderPlain($link);
            $rows[] = $data;
          }
        }
      }
    }

    $header = [
      'Type',
      'Parameters',
      'Start',
      'End',
      'View Logs',
    ];
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#cache' => ['max-age' => 10],
    ];
  }

}
