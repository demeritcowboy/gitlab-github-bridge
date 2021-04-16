<?php

namespace Drupal\gitlabgithubbridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

/**
 *
 */
class WorkflowController extends ControllerBase {

  protected $config;

  /**
   * The system logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Mailer.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailer;

  /**
   * WorkflowController constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   An instance of ConfigFactory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Mail\MailManagerInterface
   *   Mailer
   */
  public function __construct(ConfigFactory $config, LoggerChannelFactoryInterface $logger_factory, MailManagerInterface $mailer) {
    $this->config = $config->get('gitlabgithubbridge.settings');
    $this->logger = $logger_factory->get('gitlabgithubbridge');
    $this->mailer = $mailer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * Process the request body from a merge request webhook on gitlab
   * @param string $type Either all, plain, or mink.
   * @param Request $reqest
   * @return JsonResponse
   *   It's always "status":"Ok"
   */
  public function run($type, Request $request): JsonResponse {
    $request_body = json_decode(file_get_contents('php://input'), TRUE);

    $email = !empty($request_body['user']['email']) ? $request_body['user']['email'] : NULL;

    if (empty($request_body)
      || $request_body['object_kind'] !== 'merge_request'
      || $request_body['event_type'] !== 'merge_request'
      || $request_body['object_attributes']['state'] !== 'opened') {
      $this->logger->info('Only open merge_request objects are allowed.');
      if (!empty($email)) {
        $this->mailer->mail('gitlabgithubbridge', 'merge_objects_only', $email, 'en', []);
      }
      // fall through to end
    }
    else {
      $json = json_encode(array(
        'ref' => 'main',
        'inputs' => array(
          'prurl' => $request_body['object_attributes']['url'],
          'repourl' => $request_body['project']['git_http_url'],
          'notifyemail' => $email,
        ),
      ));

      $curl = curl_init();
      $cookie_file_path = tempnam(sys_get_temp_dir(), 'coo');
      $curl_params = array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HEADER => FALSE,
        CURLOPT_URL => 'https://api.github.com/repos/semperit/CiviCARROT/actions/workflows/main.yml/dispatches',
        CURLOPT_HTTPHEADER => array('Content-type: application/json', 'Accept: application/vnd.github.v3+json'),
        CURLOPT_USERPWD => $this->config->get('gitlabgithubbridge.username') . ":" . $this->config->get('gitlabgithubbridge.password'),
        CURLOPT_POST => TRUE,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)',
        CURLOPT_SSL_VERIFYPEER => $this->config->get('gitlabgithubbridge.verifyssl'),
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_COOKIEFILE => $cookie_file_path,
        CURLOPT_COOKIEJAR => $cookie_file_path,
      );

      $response_str = '';

      if ($type === 'all' || $type === 'plain') {
        // This is identical to mink it's just a different workflow file.
        curl_setopt_array($curl, array_merge($curl_params, array(CURLOPT_URL => 'https://api.github.com/repos/semperit/CiviCARROT/actions/workflows/vanilla.yml/dispatches')));
        $response_str .= curl_exec($curl);
      }

      if ($type === 'all' || $type === 'mink') {
        curl_setopt_array($curl, $curl_params);
        $response_str .= curl_exec($curl);
      }

      curl_close($curl);

      if (!empty($response_str)) {
        $this->logger->error($response_str);
        if (!empty($email)) {
          $this->mailer->mail('gitlabgithubbridge', 'trigger_failure', $email, 'en', ['result' => $response_str]);
        }
      }
    }

    $response = new JsonResponse(['status' => 'Ok']);
    return $response;
  }

  /**
   * Checks access to /gitlabgithubbridge/{type} path.
   */
  public function checkAccess($type) {
    $secret = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? NULL;
    if (!empty($secret)) {
      // Look thru our list of people who have signed up for the service and
      // see if it's a real token.
      \Drupal::service('civicrm')->initialize();
      $result = \Civi\Api4\Contact::get(FALSE)
        // format is `custom_group.name`.`custom_field.name`
        ->addWhere('CiviCarrot.Token', '=', $secret)
        ->setLimit(1)->addSelect('id')
        ->execute()->first();
      if (!is_null($result)) {
        $this->logger->info('CiviCARROT token used: @token', ['@token' => $secret]);
        // @todo Do we care about stats on which $type? It would be mostly
        // for fun since if billing it would depend on resources used.
        \Civi\Api4\Activity::create(FALSE)
          ->addValue('activity_type_id:name', 'CiviCarrot')
          ->addValue('status_id:name', 'Completed')
          ->addValue('subject', 'Ate a Carrot')
          ->addValue('target_contact_id', [$result['id']])
          ->addValue('source_contact_id', $result['id'])
          ->execute();
        return AccessResult::allowed();
      }
      // fall through
    }
    $this->logger->warning('Invalid token: @token', ['@token' => $secret]);
    return AccessResult::forbidden();
  }

}
