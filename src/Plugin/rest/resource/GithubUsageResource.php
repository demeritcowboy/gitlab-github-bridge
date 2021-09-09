<?php

namespace Drupal\gitlabgithubbridge\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ModifiedResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Provides a resource to record github actions usage.
 *
 * @RestResource(
 *   id = "githubaction",
 *   label = @Translation("Github Actions Usage"),
 *   uri_paths = {
 *     "canonical" = "/githubaction/{id}",
 *     "create" = "/githubaction/{id}"
 *   }
 * )
 */
class GithubUsageResource extends ResourceBase {

  /**
   * Constructor
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $serializer_formats, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('gitlabgithubbridge')
    );
  }

  /**
   * Responds to POST requests
   *
   * @param array $data
   *   The data needed to update the civi activity.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function post($data = NULL) {
    $this->logger->info('Carrot usage: ' . print_r($data, TRUE));

    if (empty($data['id'])) {
      throw new BadRequestHttpException('No id received.');
    }

    if (empty($data['bytes']) || empty($data['seconds'])) {
      throw new BadRequestHttpException('No valid data received.');
    }

    if (!is_numeric($data['bytes']) || !is_numeric($data['seconds'])) {
      throw new BadRequestHttpException('Data received is not numeric.');
    }

    try {
      \Drupal::service('civicrm')->initialize();
      \Civi\Api4\Activity::create(FALSE)
        ->addValue('activity_type_id:name', 'CiviCarrot')
        ->addValue('status_id:name', 'Completed')
        ->addValue('subject', 'Ate a Carrot')
        ->addValue('target_contact_id', $data['id'])
        ->addValue('source_contact_id', $data['id'])
        ->addValue('Carrot_Data.Test_type', $data['type'] ?? 'mink')
        ->addValue('Carrot_Data.Bytes_used', $data['bytes'])
        // For now, just approximate the time that was used during initialization before the script even started. It's about 36 seconds.
        ->addValue('Carrot_Data.Seconds_used', $data['seconds'] + 36)
        ->addValue('Carrot_Data.run_id', $data['run_id'])
        ->addValue('Carrot_Data.Repository', empty($data['repo']) ? '' : $data['repo'])
        ->addValue('Carrot_Data.Merge_Request', empty($data['pr']) ? '' : $data['pr'])
        ->execute();

      return new ModifiedResourceResponse(['status' => 'Ok']);
    }
    catch (\Exception $e) {
      throw new HttpException(500, 'Internal Server Error: ' . $e->getMessage(), $e);
    }
  }

  /**
   * Provides predefined HTTP request methods.
   *
   * Plugins can override this method to provide additional custom request
   * methods.
   *
   * @return array
   *   The list of allowed HTTP request method strings.
   */
  protected function requestMethods() {
    return [
      'POST',
    ];
  }

}
