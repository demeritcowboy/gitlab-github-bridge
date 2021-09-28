<?php

namespace Drupal\gitlabgithubbridge\Cron;

use Drupal\gitlabgithubbridge\Matrix\MatrixBuilder;

/**
 * At the moment this is just an entry point for a cv script,
 * e.g. cv ev "(new \Drupal\gitlabgithubbridge\Cron\TaskManager())->run();"
 */
class TaskManager {

  /**
   * @var array
   */
  private $currentCandidate;

  /**
   * Check candidates if it's time to run and then run them.
   */
  public function run() {
    $candidates = \Civi\Api4\Activity::get(FALSE)
      ->addJoin(
        'Contact AS con',
        'INNER',
        'ActivityContact',
        ['id', '=', 'con.activity_id'],
        ['con.record_type_id:name', '=', '"Activity Source"']
      )->addJoin(
        'Email AS e',
        'LEFT',
        NULL,
        ['con.id', '=', 'e.contact_id'],
        ['e.is_primary', '=', 1]
      )->addSelect(
        'id',
        'subject',
        'Periodic_Carrot.Last_Refresh',
        'Periodic_Carrot.Last_Run',
        'Periodic_Carrot.Schedule',
        'con.CiviCarrot.Token',
        'e.email'
      )->addWhere('activity_type_id:name', '=', 'PeriodicCarrot')->execute();

    foreach ($candidates as $candidate) {
      $this->currentCandidate = $candidate;
      if ($this->shouldRefresh($candidate['Periodic_Carrot.Last_Refresh'])) {
        $matrix = (new MatrixBuilder($candidate['subject'], 'master'))->build(MatrixBuilder::PERIODIC);
        \Civi\Api4\Activity::update(FALSE)
          ->addValue('Periodic_Carrot.Schedule', $matrix)
          ->addValue('Periodic_Carrot.Last_Refresh', date('Y-m-d H:i:s'))
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
   * @param string $datetime
   * @return bool
   */
  private function shouldRefresh(string $datetime): bool {
    $yesterday = new \DateTime('-1 day');
    $d = new \DateTime($datetime);
    return ($yesterday > $d);
  }

  /**
   * Is it time to run?
   * @param string $cronspec A cron spec string, e.g. '0 0 * * *'
   * @param string $lastrun Date string in Y-m-d H:i:s format.
   * @return bool
   */
  private function shouldRun(string $cronspec, string $lastrun): bool {
    $cron = new \Cron\CronExpression($cronspec);
    return $cron->getNextRunDate($lastrun) < (new \DateTime());
  }

  private function processCandidate(array $schedule) {
    $last_run = json_decode($this->currentCandidate['Periodic_Carrot.Last_Run'], TRUE) ?? [];
    foreach ($schedule as $schedule_id => $details) {
      if ($this->shouldRun($details['cronspec'], $last_run[$schedule_id] ?? '1970-01-01 00:00:00')) {
        $last_run[$schedule_id] = date('Y-m-d H:i:s');

        // Make http request to ourselves the same as the webhook.

        $json = json_encode([
          'gitlabgithubbridge_matrix' => $details['matrix'],
          'project' => ['git_http_url' => $this->currentCandidate['subject']],
          'object_attributes' => [
            'last_commit' => ['id' => 'master'],
            'url' => '',
            'state' => 'opened',
          ],
          'object_kind' => 'merge_request',
          'event_type' => 'merge_request',
          'user' => ['email' => (string) $this->currentCandidate['email']],
        ]);

        $curl = curl_init();
        $cookie_file_path = tempnam(sys_get_temp_dir(), 'coo');
        $curl_params = [
          CURLOPT_RETURNTRANSFER => 1,
          CURLOPT_HEADER => FALSE,
          CURLOPT_URL => CIVICRM_UF_BASEURL . "/gitlabgithubbridge/{$details['testType']}",
          CURLOPT_HTTPHEADER => ['Content-type: application/json', 'HTTP_X_GITLAB_TOKEN' => $this->currentCandidate['CiviCarrot.Token']],
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
    \Civi\Api4\Activity::update(FALSE)
      ->addValue('Periodic_Carrot.Last_Run', json_encode($last_run))
      ->addWhere('id', '=', $this->currentCandidate['id'])
      ->execute();
  }

}
