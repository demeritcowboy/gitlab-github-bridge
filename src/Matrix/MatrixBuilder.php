<?php

namespace Drupal\gitlabgithubbridge\Matrix;

class MatrixBuilder {

  const CIVICARROT_CIVI_DEV = 1;
  const CIVICARROT_CIVI_RELEASECANDIDATE = 2;
  const CIVICARROT_CIVI_LATEST = 3;
  const CIVICARROT_DRUPAL_LATEST = 4;
  const CIVICARROT_DRUPAL_PRIOR = 5;
  const CIVICARROT_PHP_SENSIBLE = 6;

  /**
   * @var array
   * Cache of packagist data.
   */
  private static $packagist = [];

  /**
   * @var string
   * The url of the gitlab repo
   */
  private $repourl;

  /**
   * @var string
   * The latest git commit for the PR
   */
  private $commit;

  /**
   * constructor
   * @param string $repourl
   * @param string $commit
   */
  public function __construct(string $repourl, string $commit) {
    $this->repourl = $repourl;
    $this->commit = $commit;
  }

  /**
   * Determine the desired testing matrix based on the values in civicarrot.json
   * @return string A JSON string suitable for github actions matrix
   */
  public function build(): string {
    $repourl = $this->removeDotGit($this->repourl);
    // git.drupalcode.org will reject requests that look like stock php scripts
    $streamopts = ['http' => ['user_agent' => 'CiviCARROT (civicarrot@gmail.com)']];
    $context = stream_context_create($streamopts);
    $carrotjson = file_get_contents("{$repourl}/-/raw/{$this->commit}/tests/civicarrot.json", FALSE, $context);
    //$carrotjson = '{"singlePR":{"include":[{"php-versions":"7.3","drupal":"~9.1.1","civicrm":"5.40.x-dev"},{"php-versions":"7.4","drupal":"~9.2.4","civicrm":"dev-master"}]}}';
    $matrix = json_decode($carrotjson, TRUE);
    $matrix = $this->fillMatrix($matrix['singlePR'] ?? []);
    return $this->replaceCarrotVars(json_encode($matrix));
  }

  /**
   * If some parameters are missing put in some defaults.
   * It's a little trickier if they're using "include", so just assume they
   * are specifying everything in that case.
   * @param array $matrix
   * @return array
   */
  private function fillMatrix(array $matrix): array {
    if (!isset($matrix['include'])) {
      if (empty($matrix['php-versions'])) {
        $matrix['php-versions'] = ['CIVICARROT_PHP_SENSIBLE'];
      }
      if (empty($matrix['drupal'])) {
        $matrix['drupal'] = ['CIVICARROT_DRUPAL_LATEST'];
      }
      if (empty($matrix['civicrm'])) {
        $matrix['civicrm'] = ['CIVICARROT_CIVI_RELEASECANDIDATE'];
      }
    }
    return $matrix;
  }

  /**
   * Replace some placeholder vars with actual strings.
   * @param string $s
   * @return string
   */
  private function replaceCarrotVars(string $s): string {
    // Note we try to avoid network calls if there's no replacement needed.
    if (strpos($s, 'CIVICARROT_DRUPAL_LATEST') !== FALSE) {
      $s = str_replace('CIVICARROT_DRUPAL_LATEST', $this->getDrupalVersion(self::CIVICARROT_DRUPAL_LATEST), $s);
    }
    if (strpos($s, 'CIVICARROT_DRUPAL_PRIOR') !== FALSE) {
      $s = str_replace('CIVICARROT_DRUPAL_PRIOR', $this->getDrupalVersion(self::CIVICARROT_DRUPAL_PRIOR), $s);
    }
    if (strpos($s, 'CIVICARROT_CIVI_DEV') !== FALSE) {
      $s = str_replace('CIVICARROT_CIVI_DEV', $this->getCiviVersion(self::CIVICARROT_CIVI_DEV), $s);
    }
    if (strpos($s, 'CIVICARROT_CIVI_RELEASECANDIDATE') !== FALSE) {
      $s = str_replace('CIVICARROT_CIVI_RELEASECANDIDATE', $this->getCiviVersion(self::CIVICARROT_CIVI_RELEASECANDIDATE), $s);
    }
    if (strpos($s, 'CIVICARROT_CIVI_LATEST') !== FALSE) {
      $s = str_replace('CIVICARROT_CIVI_LATEST', $this->getCiviVersion(self::CIVICARROT_CIVI_LATEST), $s);
    }
    if (strpos($s, 'CIVICARROT_PHP_SENSIBLE') !== FALSE) {
      $s = str_replace('CIVICARROT_PHP_SENSIBLE', $this->getPhpVersion(), $s);
    }
    return $s;
  }

  /**
   * Get a drupal version.
   * @param int $stage The enum corresponding to how cutting-edge a version
   *   you want.
   * @return string
   */
  private function getDrupalVersion(int $stage): string {
    $version = $this->getLatestFromPackagist('drupal/core');
    if (empty($version)) {
      $version = '^9';
    }
    elseif ($stage === self::CIVICARROT_DRUPAL_PRIOR) {
      $version = explode('.', $version);
      if ($version[1] === '0') {
        // e.g. if latest was 10.0.0 then this would be ^9.
        $version = '^' . ($version[0] - 1);
      }
      else {
        // e.g. if latest was 9.2.4 then this would be ~9.1.1 (composer autoadjusts the last digit).
        $version = "~{$version[0]}." . ($version[1] - 1) . '.1';
      }
    }
    // otherwise if self::CIVICARROT_DRUPAL_LATEST or something else then just leave as-is
    return $version;
  }

  /**
   * Get a civi version.
   * @param int $stage The enum corresponding to how cutting-edge a version
   *   you want.
   * @return string
   */
  private function getCiviVersion(int $stage): string {
    if ($stage === self::CIVICARROT_CIVI_DEV) {
      // don't even need to make network call
      return 'dev-master';
    }
    $version = $this->getLatestFromPackagist('civicrm/civicrm-core');
    if (empty($version)) {
      $version = 'dev-master';
    }
    elseif ($stage === self::CIVICARROT_CIVI_RELEASECANDIDATE) {
      $version = explode('.', $version);
      $version = "{$version[0]}." . ($version[1] + 1) . '.x-dev';
    }
    // otherwise if self::CIVICARROT_CIVI_LATEST or something else then just leave as-is
    return $version;
  }

  /**
   * Get metadata about a package from packagist.org
   * Cache it since we might call it for the same package a few times.
   * @param string $package e.g. drupal/core
   * @return string
   */
  private function getLatestFromPackagist(string $package): string {
    if (empty(self::$packagist[$package])) {
      $streamopts = ['http' => ['user_agent' => 'CiviCARROT (civicarrot@gmail.com)']];
      $context = stream_context_create($streamopts);
      $json = file_get_contents("https://repo.packagist.org/p2/{$package}.json", FALSE, $context);
      $info = json_decode($json, TRUE);
      self::$packagist[$package] = $info['packages'][$package][0] ?? [];
    }
    return self::$packagist[$package]['version'] ?? '';
  }

  /**
   * Get a php version.
   * At the moment only CIVICARROT_PHP_SENSIBLE is supported.
   * @return string
   */
  private function getPhpVersion(): string {
    // Just use the version this site is running, since it runs drupal+civi
    // and so is likely to be a reasonable choice.
    $php = explode('.', phpversion());
    return "{$php[0]}.{$php[1]}";
  }

  /**
   * Removes .git on the end if present
   * @param string $s
   * @return string
   */
  private function removeDotGit(string $s): string {
    if (substr($s, -4, 4) === '.git') {
      return substr($s, 0, -4);
    }
    return $s;
  }

}
