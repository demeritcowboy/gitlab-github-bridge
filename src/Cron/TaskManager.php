<?php

namespace Drupal\gitlabgithubbridge\Cron;

use Drupal\gitlabgithubbridge\Matrix\MatrixBuilder;

/**
 * At the moment this is just an entry point for a cv script,
 * e.g. cv ev "(new \Drupal\gitlabgithubbridge\Cron\TaskManager())->run();"
 */
class TaskManager {

  /**
   * @var array $currentCandidate
   */
  private $currentCandidate;

  /**
   * Check candidates if it's time to run and then run them.
   */
  public function run() {
    $candidates = \Civi\Api4\Activity::get(FALSE)
      ->addSelect('id', 'subject', 'Periodic_Carrot.Last_Update', 'Periodic_Carrot.Schedule')
      ->addWhere('activity_type_id:name', '=', 'PeriodicCarrot')
      ->execute();

    foreach ($candidates as $candidate) {
      $this->currentCandidate = $candidate;
      if ($this->shouldRefresh($candidate['Periodic_Carrot.Last_Update'])) {
        $matrix = (new MatrixBuilder($candidate['subject'], 'master'))->build(MatrixBuilder::PERIODIC);
        \Civi\Api4\Activity::update(FALSE)
          ->addValue('Periodic_Carrot.Schedule', $matrix)
          ->addValue('Periodic_Carrot.Last_Update', date('Y-m-d H:i:s'))
          ->addWhere('id', '=', $candidate['id'])
          ->execute();
      }
      $matrix = json_decode($matrix, TRUE);
      if (!empty($matrix[MatrixBuilder::PERIODIC])) {
        $this->processCandidate($matrix[MatrixBuilder::PERIODIC]);
      }
    }
  }

  /**
   * Is the given datetime older than 1 day ago?
   * @param string
   * @return bool
   */
  private function shouldRefresh(string $datetime): bool {
    $yesterday = new DateTime('-1 day');
    $d = new DateTime($datetime);
    return ($yesterday > $d);
  }

  private function processCandidate(array $schedule) {
    foreach ($schedule as $cron_spec => $details) {
      // @todo use cron parsing library to parse and figure out if it's time.
      if (FALSE) {
        // @todo now make http request to ourselves the same as the webhook?
// this doesn't currently work because that endpoint is for singlePR's and it then looks up the matrix from the PR url, which doesn't exist here.
// but if the request_body were to contain a matrix, then we could have that endpoint check for that first and use it, otherwise do what it currently does

        // If we're running as a drush script, need to pass the -l option, but
        // within cv it just returns '/' and there is no option to pass. We
        // could use CIVICRM_UF_BASEURL.
        //$url = \Drupal::urlGenerator()->generateFromRoute('<front>', [], ['absolute' => TRUE]);

        $json = json_encode([
        ]);

        $curl = curl_init();
        $cookie_file_path = tempnam(sys_get_temp_dir(), 'coo');
        $curl_params = [
          CURLOPT_RETURNTRANSFER => 1,
          CURLOPT_HEADER => FALSE,
          CURLOPT_URL => CIVICRM_UF_BASEURL . "/gitlabgithubbridge/{$details['testType']}",
          CURLOPT_HTTPHEADER => ['Content-type: application/json'],
          CURLOPT_POST => TRUE,
          CURLOPT_POSTFIELDS => $json,
          CURLOPT_CONNECTTIMEOUT => 10,
          CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)',
          CURLOPT_SSL_VERIFYPEER => TRUE,
          CURLOPT_FOLLOWLOCATION => 1,
          CURLOPT_COOKIEFILE => $cookie_file_path,
          CURLOPT_COOKIEJAR => $cookie_file_path,
        ];

        $response_str = '';
        $exec_result = curl_exec($curl);
        if ($exec_result === FALSE) {
          \Drupal::logger('gitlabgithubbridge')->debug("curlerr: " . curl_error($curl));
          \Drupal::logger('gitlabgithubbridge')->debug(print_r(curl_getinfo($curl), TRUE));
        }
        else {
          $response_str .= $exec_result;
        }
        curl_close($curl);
        if (!empty($response_str)) {
          \Drupal::logger('gitlabgithubbridge')->error($response_str);
        }
      }
    }
  }

}
