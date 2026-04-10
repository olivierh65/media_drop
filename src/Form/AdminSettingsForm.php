<?php

namespace Drupal\media_drop\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Media Drop general configuration form.
 */
class AdminSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an AdminSettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

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
      '#markup' => '<p>' . $this->t('Configure the general settings for Media Drop. To manage depots and MIME type mappings, use the tabs above.') . '</p>',
    ];

    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    // Depots tab.
    $form['depots_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Depots'),
      '#group' => 'tabs',
    ];

    $form['depots_tab']['depots_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Manage depots'),
      '#url' => Url::fromRoute('media_drop.depot_list'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
      '#prefix' => '<p>' . $this->t('Create and manage your drop depots.') . '</p>',
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

    // Directories tab.
    $form['directories_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Directories'),
      '#group' => 'tabs',
    ];

    $form['directories_tab']['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure the taxonomy used for organizing media into directories.') . '</p>',
    ];

    // Get available vocabularies.
    $vocabularies = $this->getVocabularies();

    $form['directories_tab']['media_directory_vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Media Directory Vocabulary'),
      '#description' => $this->t('Select the taxonomy vocabulary to use for organizing media into directories.'),
      '#options' => ['' => '- ' . $this->t('None') . ' -'] + $vocabularies,
      '#default_value' => $this->getDefaultMediaDirectoryVocabulary($config, $vocabularies),
      '#required' => FALSE,
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
      '#title' => $this->t('Depot token lifetime'),
      '#options' => [
        '0' => $this->t('Unlimited'),
        '2592000' => $this->t('30 days'),
        '7776000' => $this->t('90 days'),
        '15552000' => $this->t('180 days'),
        '31536000' => $this->t('1 year'),
      ],
      '#default_value' => $config->get('token_lifetime') ?? '0',
      '#description' => $this->t('After this period, depot URLs will be invalid and will need to be regenerated.'),
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
      ->set('media_directory_vocabulary', $form_state->getValue('media_directory_vocabulary'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get available vocabularies.
   *
   * @return array
   *   Array of vocabulary options.
   */
  private function getVocabularies() {
    $options = [];
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();

    foreach ($vocabularies as $vocab) {
      $options[$vocab->id()] = $vocab->label();
    }

    return $options;
  }

  /**
   * Get the default media directory vocabulary.
   *
   * Tries to use the value from media_album_av settings, then falls back to
   * 'media_album_av_folders' if it exists, otherwise returns empty string.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The media_drop.settings config.
   * @param array $available_vocabularies
   *   The list of available vocabularies.
   *
   * @return string
   *   The vocabulary ID to use as default.
   */
  private function getDefaultMediaDirectoryVocabulary($config, $available_vocabularies) {
    // First, check if a value is already saved in media_drop settings.
    $saved_value = $config->get('media_directory_vocabulary');
    if (!empty($saved_value) && array_key_exists($saved_value, $available_vocabularies)) {
      return $saved_value;
    }

    // Try to get the value from media_album_av settings.
    $media_album_av_config = \Drupal::config('media_album_av.settings');
    $media_album_av_directory = $media_album_av_config->get('prefered_media_directory');
    if (!empty($media_album_av_directory) && array_key_exists($media_album_av_directory, $available_vocabularies)) {
      return $media_album_av_directory;
    }

    // Fall back to 'media_album_av_folders' if it exists.
    if (array_key_exists('media_album_av_folders', $available_vocabularies)) {
      return 'media_album_av_folders';
    }

    // No suitable default found.
    return '';
  }

}
