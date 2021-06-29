<?php

/**
 * @file
 * Contains \Drupal\gitlabgithubbridge\Form\SettingsForm.
 */

namespace Drupal\gitlabgithubbridge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure gitlabgithubbridge settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gitlabgithubbridge_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['gitlabgithubbridge.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gitlabgithubbridge.settings');

    $form['gitlabgithubbridge_username'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Github username'),
      '#default_value' => $config->get('gitlabgithubbridge.username'),
    );

    $form['gitlabgithubbridge_password'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Github access token'),
      '#default_value' => $config->get('gitlabgithubbridge.password'),
    );

    $form['gitlabgithubbridge_verifyssl'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Verify SSL'),
      '#default_value' => $config->get('gitlabgithubbridge.verifyssl'),
      '#description' => $this->t('Useful to turn off for local testing'),
      '#return_value' => 1,
    );

    $form['gitlabgithubbridge_cmsver'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Default Drupal version'),
      '#default_value' => (empty($config->get('gitlabgithubbridge.cmsver')) ? \Drupal::VERSION : $config->get('gitlabgithubbridge.cmsver')),
      '#description' => $this->t('Version to use for the matrix if not specified by the extension. Note the default CiviCRM version is always the release candidate version if not specified by the extension.'),
    );

    $form['gitlabgithubbridge_phpver'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Default php version'),
      '#default_value' => (empty($config->get('gitlabgithubbridge.phpver')) ? phpversion() : $config->get('gitlabgithubbridge.phpver')),
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('gitlabgithubbridge.settings')
      ->set('gitlabgithubbridge.username', $form_state->getValue('gitlabgithubbridge_username'))
      ->set('gitlabgithubbridge.password', $form_state->getValue('gitlabgithubbridge_password'))
      ->set('gitlabgithubbridge.verifyssl', $form_state->getValue('gitlabgithubbridge_verifyssl'))
      ->set('gitlabgithubbridge.cmsver', $form_state->getValue('gitlabgithubbridge_cmsver'))
      ->set('gitlabgithubbridge.phpver', $form_state->getValue('gitlabgithubbridge_phpver'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
