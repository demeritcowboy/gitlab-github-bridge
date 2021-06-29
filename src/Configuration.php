<?php

namespace Drupal\gitlabgithubbridge;

/**
 * Configuration represents a particular consumer's settings.
 * For example a CiviCRM extension might want a certain matrix.
 */
class Configuration {

  /**
   * @var SimpleXMLElement
   */
  private $configurationData;

  public function __construct(string $uri) {
    $this->configurationData = simplexml_load_file($uri);
    if ($this->configurationData === FALSE) {
      throw new \Exception("Unable to parse $uri");
    }
  }

  public function getConfigurationData(): SimpleXMLElement {
    return $this->configurationData;
  }

}
