<?php

namespace Drupal\media_drop\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Media Drop general configuration form.
 */
class AdminSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['media_drop.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_drop_admin_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('media_drop.settings');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Configure the general settings for Media Drop. To manage albums and MIME type mappings, use the tabs above.') . '</p>',
    ];

    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    // Albums tab.
    $form['albums_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Albums'),
      '#group' => 'tabs',
    ];

    $form['albums_tab']['albums_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Manage albums'),
      '#url' => Url::fromRoute('media_drop.album_list'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
      '#prefix' => '<p>' . $this->t('Create and manage your drop albums.') . '</p>',
    ];

    // MIME Mappings tab.
    $form['mime_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('MIME Types'),
      '#group' => 'tabs',
    ];

    $form['mime_tab']['mime_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Manage MIME mappings'),
      '#url' => Url::fromRoute('media_drop.mime_mapping'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
      '#prefix' => '<p>' . $this->t('Configure the media types created based on the MIME types of the files.') . '</p>',
    ];

    // Upload tab.
    $form['upload_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Upload'),
      '#group' => 'tabs',
    ];

    $form['upload_tab']['max_filesize'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum file size'),
      '#default_value' => $config->get('max_filesize') ?: '50',
      '#description' => $this->t('Maximum size in MB for each file. Default: 50 MB'),
      '#size' => 10,
    ];

    $form['upload_tab']['allowed_extensions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed extensions'),
      '#default_value' => $config->get('allowed_extensions') ?: 'jpg jpeg png gif webp mp4 mov avi webm',
      '#description' => $this->t('List of allowed file extensions, separated by spaces.'),
      '#rows' => 3,
    ];

    $form['upload_tab']['enable_image_preview'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable image preview'),
      '#default_value' => $config->get('enable_image_preview') ?? TRUE,
      '#description' => $this->t('Display image thumbnails in the upload interface.'),
    ];

    // Security tab.
    $form['security_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Security'),
      '#group' => 'tabs',
    ];

    $form['security_tab']['require_user_name'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Name required for anonymous users'),
      '#default_value' => $config->get('require_user_name') ?? TRUE,
      '#description' => $this->t('Anonymous users must enter their name.'),
    ];

    $form['security_tab']['token_lifetime'] = [
      '#type' => 'select',
      '#title' => $this->t('Album token lifetime'),
      '#options' => [
        '0' => $this->t('Unlimited'),
        '2592000' => $this->t('30 days'),
        '7776000' => $this->t('90 days'),
        '15552000' => $this->t('180 days'),
        '31536000' => $this->t('1 year'),
      ],
      '#default_value' => $config->get('token_lifetime') ?? '0',
      '#description' => $this->t('After this period, album URLs will be invalid and will need to be regenerated.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $max_filesize = $form_state->getValue('max_filesize');
    if (!is_numeric($max_filesize) || $max_filesize <= 0) {
      $form_state->setErrorByName('max_filesize', $this->t('The maximum size must be a positive number.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('media_drop.settings')
      ->set('max_filesize', $form_state->getValue('max_filesize'))
      ->set('allowed_extensions', $form_state->getValue('allowed_extensions'))
      ->set('enable_image_preview', $form_state->getValue('enable_image_preview'))
      ->set('require_user_name', $form_state->getValue('require_user_name'))
      ->set('token_lifetime', $form_state->getValue('token_lifetime'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
