<?php

namespace Drupal\gitlabgithubbridge;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Merge Request Service.
 * Responds to merge request webform and hands off to a github action.
 */
class MergeRequestService implements MergeRequestServiceInterface {

  protected $config;

  /**
   * The system logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * MergeRequestService constructor.
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
   * Start a github action to create a merge request against the given repo.
   * @param string $repourl The git repository url.
   */
  public function run($repourl): void {
    if (empty($repourl)) {
      $this->logger->error('Missing repo url in webform submission?');
      return;
    }

    $json = json_encode([
      'ref' => 'main',
      'inputs' => [
        'repourl' => $repourl,
      ],
    ]);

    $curl = curl_init();
    $cookie_file_path = tempnam(sys_get_temp_dir(), 'coo');
    $curl_params = [
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_HEADER => FALSE,
      CURLOPT_URL => 'https://api.github.com/repos/semperit/CiviCARROT/actions/workflows/create_merge_request.yml/dispatches',
      CURLOPT_HTTPHEADER => ['Content-type: application/json', 'Accept: application/vnd.github.v3+json'],
      CURLOPT_USERPWD => $this->config->get('gitlabgithubbridge.username') . ":" . $this->config->get('gitlabgithubbridge.password'),
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $json,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)',
      CURLOPT_SSL_VERIFYPEER => $this->config->get('gitlabgithubbridge.verifyssl'),
      CURLOPT_FOLLOWLOCATION => 1,
      CURLOPT_COOKIEFILE => $cookie_file_path,
      CURLOPT_COOKIEJAR => $cookie_file_path,
    ];

    $response_str = '';

    $exec_result = curl_exec($curl);
    if ($exec_result === FALSE) {
      $this->logger->debug("curlerr: " . curl_error($curl));
      $this->logger->debug(print_r(curl_getinfo($curl), TRUE));
    }
    else {
      $response_str .= $exec_result;
    }

    curl_close($curl);

    if (!empty($response_str)) {
      $this->logger->error($response_str);
    }
  }

}
