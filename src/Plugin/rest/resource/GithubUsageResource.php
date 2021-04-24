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

    // There's two sets of fields - the one without a suffix is for mink
    // tests, the _plain one is for data coming from regular unit tests.
    $field_suffix = '';
    if (($data['type'] ?? NULL) == 'plain') {
      $field_suffix = '_plain';
    }

    try {
      \Drupal::service('civicrm')->initialize();
      \Civi\Api4\Activity::update(FALSE)
        ->addValue('id', $data['id'])
        ->addValue("Carrot_Data.Bytes_used{$field_suffix}", $data['bytes'])
        ->addValue("Carrot_Data.Seconds_used{$field_suffix}", $data['seconds'])
        ->addValue("Carrot_Data.run_id{$field_suffix}", $data['run_id'])
        // These next two will get updated twice if you've chosen to run both
        // types, but it's the same info for both so don't care.
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
