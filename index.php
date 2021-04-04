<?php
require_once 'civicarrot.config.php';

header('Content-Type: text/plain');

// TODO: more better
$fp = fopen('../sites/default/files/civicrm/ConfigAndLog/civicarrot.log', 'a');

// Check secret token
$secret = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? NULL;
if (empty($secret)) {
  fwrite($fp, 'Invalid token.');
  fclose($fp);
  exit;
}
// For real we would check it against the database here.

$request_body = json_decode(file_get_contents('php://input'), TRUE);

if ($request_body['object_kind'] !== 'merge_request'
  || $request_body['event_type'] !== 'merge_request'
  || $request_body['object_attributes']['state'] !== 'opened') {
  fwrite($fp, 'Only open merge_request objects are allowed.');
  fwrite($fp, print_r($request_body, true));
}
else {
  global $CIVICARROT_USERNAME, $CIVICARROT_TOKEN;

  switch ($_GET['type'] ?? 'mink') {
    case 'plain':
      $result = `curl -u {$CIVICARROT_USERNAME}:{$CIVICARROT_TOKEN} -X POST -H "Accept: application/vnd.github.v3+json" https://api.github.com/repos/semperit/CiviCARROT/actions/workflows/vanilla.yml/dispatches -d '{"ref":"main","inputs":{"prurl":"{$request_body['object_attributes']['url']}","repourl":"{$request_body['project']['git_http_url']}"}}'`;
      break;
    case 'mink':
    default:
      $result = `curl -u {$CIVICARROT_USERNAME}:{$CIVICARROT_TOKEN} -X POST -H "Accept: application/vnd.github.v3+json" https://api.github.com/repos/semperit/CiviCARROT/actions/workflows/main.yml/dispatches -d '{"ref":"main","inputs":{"prurl":"{$request_body['object_attributes']['url']}","repourl":"{$request_body['project']['git_http_url']}"}}'`;
      break;
  }

  if (empty($result)) {
//    fwrite($fp, 'OK');
  }
  else {
    // TODO: Email to account on file. Or $request_body['user']['email'] might work.
    fwrite($fp, print_r($result, true));
  }
}
fclose($fp);
