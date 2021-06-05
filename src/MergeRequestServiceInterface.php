<?php

namespace Drupal\gitlabgithubbridge;

/**
 * Defines an interface for the MergeRequestService.
 */
interface MergeRequestServiceInterface {

  /**
   * Create a merge request against a given git repository.
   *
   * @param string $repourl
   *   A git repository url
   */
  public function run($repourl): void;

}
