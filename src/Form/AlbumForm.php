<?php

namespace Drupal\media_drop\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\media_drop\Service\TaxonomyService;
use Drupal\media_taxonomy_service\Service\DirectoryService;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Form for creating/editing albums.
 */
class AlbumForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The extension list module service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $extensionListModule;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The taxonomy service.
   *
   * @var \Drupal\media_drop\Service\TaxonomyService
   */
  protected $taxonomyService;

  /**
   * The directory service.
   *
   * @var \Drupal\media_taxonomy_service\Service\DirectoryService
   */
  protected $directoryService;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new AlbumForm.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleExtensionList $extension_list_module,
    RequestStack $request_stack,
    TimeInterface $time,
    TaxonomyService $taxonomy_service,
    DirectoryService $directory_service,
    ModuleHandlerInterface $module_handler,
    RendererInterface $renderer,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->extensionListModule = $extension_list_module;
    $this->requestStack = $request_stack;
    $this->time = $time;
    $this->taxonomyService = $taxonomy_service;
    $this->directoryService = $directory_service;
    $this->moduleHandler = $module_handler;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
    $container->get('database'),
    $container->get('entity_type.manager'),
    $container->get('config.factory'),
    $container->get('extension.list.module'),
    $container->get('request_stack'),
    $container->get('datetime.time'),
    $container->get('media_drop.taxonomy_service'),
    $container->get('media_taxonomy_service.directory_service'),
    $container->get('module_handler'),
    $container->get('renderer')
    );

  }

  /**
   * Get the entity type manager service.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  protected function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_drop_album_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $album_id = NULL) {
    $album = NULL;

    if ($album_id) {
      $album = $this->database->select('media_drop_albums', 'a')
        ->fields('a')
        ->condition('id', $album_id)
        ->execute()
        ->fetchObject();

      if (!$album) {
        $this->messenger()->addError($this->t('Album not found.'));
        return $form;
      }
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Album name'),
      '#default_value' => $album ? $album->name : '',
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Example: Birthday 2025, Sophie & Pierre\'s wedding'),
    ];

    // Get media types that accept image or video files.
    $media_types = $this->getMediaTypesWithFileFields();
    $image_media_types = $media_types['image'];
    $video_media_types = $media_types['video'];

    $form['media_types'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Media types'),
      '#description' => $this->t('Select the media types to create for uploaded files. If not specified, the system will use the default MIME mapping.'),
      '#tree' => TRUE,
      '#attributes' => ['id' => 'media-drop-album-form'],
    ];

    $form['media_types']['default_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Media type for images'),
      '#options' => ['' => $this->t('- Use default MIME mapping -')] + $image_media_types,
      '#default_value' => $album ? $album->default_media_type : '',
      '#description' => $this->t('Drupal media type that will be created for image files (JPEG, PNG, etc.)'),
      '#media_type_context' => 'default',
      '#ajax' => [
        'callback' => [$this, 'ajaxUpdateFileFieldPathsWarning'],
        'wrapper' => 'media-types-default-wrapper',
        'event' => 'change',
      ],
    ];

    // Container for warning, checkbox and video select (updated via AJAX).
    $form['media_types']['default_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'media-types-default-wrapper'],
    ];

    // Check if selected image media type has filefield_paths enabled.
    $selected_image_type =
    $form_state->getValue(['media_types', 'default_media_type'])
    ?? ($album ? $album->default_media_type : '');

    $form['media_types']['default_wrapper']['warning_container'] = [
      '#type' => 'container',
      '#access' => ($selected_image_type && $this->hasFileFieldPathsEnabled($selected_image_type)),
      '#states' => [
        'visible' => [
          ':input[name="media_types[default_wrapper][disable_filefield_paths_default]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['media_types']['default_wrapper']['warning_container']['default_media_type_warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $this->t('The selected media type has filefield_paths enabled, which will manage file paths automatically. Directory selection below will be ignored.') . '</div>',
    ];
    $form['media_types']['default_wrapper']['disable_filefield_paths_default'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable filefield_paths for this media type'),
      '#description' => $this->t('Uncheck filefield_paths on the media field to allow manual directory selection.'),
      '#default_value' => FALSE,
      '#access' => ($selected_image_type && $this->hasFileFieldPathsEnabled($selected_image_type)),
      '#media_type_context' => 'default',
      '#ajax' => [
        'callback' => [$this, 'ajaxDisableFileFieldPaths'],
        'wrapper' => 'media-types-default-wrapper',
        'effect' => 'fade',
        'event' => 'change',
      ],
    ];

    $form['media_types']['video_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Media type for videos'),
      '#options' => ['' => $this->t('- Use default MIME mapping -')] + $video_media_types,
      '#default_value' => $album ? $album->video_media_type : '',
      '#description' => $this->t('Drupal media type that will be created for video files (MP4, MOV, etc.)'),
      '#media_type_context' => 'video',
      '#ajax' => [
        'callback' => [$this, 'ajaxUpdateFileFieldPathsWarning'],
        'wrapper' => 'media-types-video-wrapper',
        'event' => 'change',
      ],
    ];

    // Container for warning and checkbox (updated via AJAX).
    $form['media_types']['video_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'media-types-video-wrapper'],
    ];

    // Check if selected video media type has filefield_paths enabled.
    $selected_video_type =
    $form_state->getValue(['media_types', 'video_media_type'])
    ?? ($album ? $album->video_media_type : '');

    $form['media_types']['video_wrapper']['warning_container'] = [
      '#type' => 'container',
      '#access' => ($selected_video_type && $this->hasFileFieldPathsEnabled($selected_video_type)),
      '#states' => [
        'visible' => [
          ':input[name="media_types[video_wrapper][disable_filefield_paths_video]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['media_types']['video_wrapper']['warning_container']['video_media_type_warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $this->t('The selected media type has filefield_paths enabled, which will manage file paths automatically. Directory selection below will be ignored.') . '</div>',
    ];
    $form['media_types']['video_wrapper']['disable_filefield_paths_video'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable filefield_paths for this media type'),
      '#description' => $this->t('Uncheck filefield_paths on the media field to allow manual directory selection.'),
      '#default_value' => FALSE,
      '#access' => ($selected_video_type && $this->hasFileFieldPathsEnabled($selected_video_type)),
      '#media_type_context' => 'video',
      '#ajax' => [
        'callback' => [$this, 'ajaxDisableFileFieldPaths'],
        'wrapper' => 'media-types-video-wrapper',
        'effect' => 'fade',
        'event' => 'change',
      ],
    ];

    $form['directories'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Directories'),
      '#tree' => TRUE,
      '#attributes' => ['id' => 'media-drop-directories'],
    ];

    // Container for directories content (updated via AJAX).
    $form['directories']['content'] = $this->buildDirectoriesContent($form_state, $album);

    // Get Media Directories vocabulary ID.
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    // Storage scheme selection only (public/private).
    $base_dir_value = $album ? $album->base_directory : 'public://';
    $current_scheme = strpos($base_dir_value, 'private://') === 0 ? 'private' : 'public';

    $form['directories']['storage_scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Storage location'),
      '#options' => [
        'public' => $this->t('Public files (public://) - visible to all users'),
        'private' => $this->t('Private files (private://) - requires download permission'),
      ],
      '#default_value' => $current_scheme,
      '#required' => TRUE,
      '#description' => $this->t('Choose where to store the media files.'),
    ];

    // CASE 1: media_directories is ENABLED.
    if ($vocabulary_id) {
      // Get tree data for jstree.
      $selected_tid = $album && !empty($album->media_directory) ? $album->media_directory : NULL;
      $tree_data = $this->directoryService->getDirectoryTreeData($vocabulary_id, $selected_tid);

      // Container for jstree directory selector.
      $form['directories']['jstree_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'media-drop-jstree-wrapper',
          'style' => $this->bothHaveFFP($form_state, $album) ? 'display:none;' : '',
        ],
      ];

      // Hidden field to store selected term ID.
      $form['directories']['jstree_wrapper']['selected_term'] = [
        '#type' => 'hidden',
        '#attributes' => [
          'id' => 'media-drop-selected-term',
        ],
        '#default_value' => $selected_tid,
      ];

      // Title for directory tree section.
      $form['directories']['jstree_wrapper']['media_directory_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Directory Structure'),
        '#attributes' => [
          'class' => ['media-directory-title'],
        ],
      ];

      // Description for directory tree section.
      $form['directories']['jstree_wrapper']['media_directory_description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Select the base directory where the media files will be saved (username folders will be created inside). You can right-click on the tree to create new directories.'),
        '#attributes' => [
          'class' => ['media-directory-description', 'form-text'],
        ],
      ];

      // Jstree container for directory selection with unified taxonomy styling.
      $form['directories']['jstree_wrapper']['media_directory'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'media-drop-directory-tree',
          'class' => ['taxonomy-inline-tree-container', 'storage-tree-container'],
          'data-vocabulary-id' => $vocabulary_id,
          'data-selected-term' => $selected_tid ?: '',
        ],
      ];

      // Pass tree data to JavaScript via drupalSettings.
      $form['directories']['jstree_wrapper']['#attached']['drupalSettings']['mediaDrop'] = [
        'directoryTree' => $tree_data,
        'vocabularyId' => $vocabulary_id,
      ];
    }
    // CASE 2: media_directories is DISABLED.
    else {
      // Base directory path (required when media_directories is disabled).
      $base_path_value = $album ? str_replace(['public://', 'private://'], '', $album->base_directory) : 'media-drop';
      $form['directories']['base_directory_path'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Base directory path'),
        '#default_value' => $base_path_value,
        '#required' => TRUE,
        '#maxlength' => 255,
        '#description' => $this->t('Example: media-drop<br>This is the base directory for all uploads. Do not include the scheme (public:// or private://).'),
      ];

      // Container for jstree directory selector.
      $form['directories']['jstree_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'media-drop-jstree-wrapper',
          'style' => 'display:none;',
        ],
      ];

      // If filefield_paths is enabled, show token field for dynamic path construction.
      if ($this->extensionListModule->getPath('filefield_paths')) {
        $form['directories']['filefield_paths_tokens'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Path tokens (filefield_paths)'),
          '#default_value' => $album ? $this->extractTokensFromPath($album->base_directory) : '',
          '#maxlength' => 255,
          '#description' => $this->t('Optional: Use filefield_paths tokens to create dynamic subdirectories. Example: [date:custom:Y]-[date:custom:m] or [user:name]. Leave empty to use the base path as-is.'),
        ];
      }
    }

    if ($album) {
      $url = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost() . '/media-drop/' . $album->token;

      $form['current_url'] = [
        '#type' => 'item',
        '#title' => $this->t('Drop URL'),
        '#markup' => '<div class="media-drop-url"><strong>' . $url . '</strong><br><small>' . $this->t('Share this URL with participants so they can drop their media.') . '</small></div>',
      ];

      $form['regenerate_token'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Regenerate token (will change the URL)'),
        '#default_value' => FALSE,
        '#description' => $this->t('Check this box to generate a new URL. The old URL will no longer work.'),
      ];
    }

    // Notifications section.
    $form['notifications'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Notifications'),
      '#description' => $this->t('Configure email notifications for uploads to this album. <strong>Note:</strong> Access permissions are managed through Media Drop permissions in the Roles & Permissions settings, not here.'),
      '#tree' => TRUE,
    ];

    $form['notifications']['enable_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable email notifications'),
      '#default_value' => $album ? (int) $album->enable_notifications : FALSE,
      '#description' => $this->t('Send email notifications when media is uploaded to this album.'),
    ];

    // Load available roles.
    $roles = $this->loadRoles();
    $form['notifications']['notification_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Notify users with these roles'),
      '#options' => $roles,
      '#default_value' => $album && !empty($album->notification_roles) ? explode(',', $album->notification_roles) : [],
      '#description' => $this->t('Select which user roles should receive email notifications about uploads to this album. This only controls who gets notified, not who can access the album.'),
      '#states' => [
        'visible' => [
          ':input[name="notifications[enable_notifications]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['notifications']['notification_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Additional notification email'),
      '#default_value' => $album && !empty($album->notification_email) ? $album->notification_email : '',
      '#description' => $this->t('Optional: Send notifications to this email address in addition to users with selected roles.'),
      '#states' => [
        'visible' => [
          ':input[name="notifications[enable_notifications]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Album active'),
      '#default_value' => $album ? $album->status : 1,
      '#description' => $this->t('If unchecked, the album will no longer be accessible for drops.'),
    ];

    $form['album_id'] = [
      '#type' => 'hidden',
      '#value' => $album_id,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $album ? $this->t('Update') : $this->t('Create'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('media_drop.album_list'),
      '#attributes' => ['class' => ['button']],
    ];

    // Attach jstree library and custom JS from media_taxonomy_service.
    $form['#attached']['library'][] = 'media_taxonomy_service/directory_selector';
    $form['#attached']['library'][] = 'media_taxonomy_service/taxonomy-manager';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate storage scheme is always required.
    $scheme = $form_state->getValue(['directories', 'storage_scheme']);

    if (empty($scheme)) {
      $form_state->setErrorByName('directories][storage_scheme', $this->t('Please select a storage location.'));
    }

    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    // CASE: media_directories is DISABLED.
    if (!$vocabulary_id) {
      $base_path = $form_state->getValue(['directories', 'base_directory_path']);

      if (empty($base_path) || empty(trim($base_path))) {
        $form_state->setErrorByName('directories][base_directory_path', $this->t('The directory path cannot be empty.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // $album_id may be NULL for new albums.
    $album_id = $form_state->getValue('album_id');
    $scheme = $form_state->getValue(['directories', 'storage_scheme']);
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    $media_directory_value = '';
    $auto_create_structure = 0;

    // CASE 1: media_directories is ENABLED.
    if ($vocabulary_id) {
      // Use the selected term from jstree (creation is done via context menu).
      $media_directory_value = $form_state->getValue(['directories', 'jstree_wrapper', 'selected_term']);

      // Build base_directory from the selected term's path hierarchy.
      $base_directory = $scheme . '://';

      if ($media_directory_value && $media_directory_value !== 0) {
        // Load the selected term to build its path.
        $selected_term = $this->entityTypeManager->getStorage('taxonomy_term')->load($media_directory_value);
        if ($selected_term) {
          // Build path from term hierarchy.
          $term_path = $this->directoryService->buildTermPath($selected_term);
          $base_directory .= $term_path;
        }
      }
    }
    // CASE 2: media_directories is DISABLED.
    else {
      // Use base_directory_path and optional filefield_paths tokens.
      $base_path = rtrim($form_state->getValue(['directories', 'base_directory_path']), '/');
      $base_directory = $scheme . '://' . ltrim($base_path, '/');

      // If filefield_paths is enabled, append tokens to path.
      if ($this->extensionListModule->getPath('filefield_paths')) {
        $tokens = $form_state->getValue(['directories', 'filefield_paths_tokens']);
        if (!empty($tokens)) {
          // Append tokens as a subdirectory path.
          $base_directory .= '/' . rtrim($tokens, '/');
        }
      }
    }

    $values = [
      'name' => $form_state->getValue('name'),
      'base_directory' => $base_directory,
      'media_directory' => $media_directory_value,
      'default_media_type' => $form_state->getValue(['media_types', 'default_media_type']),
      'video_media_type' => $form_state->getValue(['media_types', 'video_media_type']),
      'auto_create_structure' => $auto_create_structure,
      'enable_notifications' => $form_state->getValue(['notifications', 'enable_notifications']) ? 1 : 0,
      'notification_roles' => implode(',', array_filter($form_state->getValue(['notifications', 'notification_roles']))),
      'notification_email' => $form_state->getValue(['notifications', 'notification_email']) ?: '',
      'status' => $form_state->getValue('status') ? 1 : 0,
      'updated' => $this->time->getRequestTime(),
    ];

    // Check if checkboxes are checked and disable filefield_paths if needed.
    $this->disableFileFieldPathsIfNeeded($form_state);

    if ($album_id) {
      // Update.
      if ($form_state->getValue('regenerate_token')) {
        $values['token'] = Crypt::randomBytesBase64(32);
      }

      $this->database->update('media_drop_albums')
        ->fields($values)
        ->condition('id', $album_id)
        ->execute();

      $this->messenger()->addStatus($this->t('The album has been updated.'));
    }
    else {
      // Create.
      $values['token'] = Crypt::randomBytesBase64(32);
      $values['created'] = $this->time->getRequestTime();

      $this->database->insert('media_drop_albums')
        ->fields($values)
        ->execute();

      $this->messenger()->addStatus($this->t('The album has been created.'));
    }

    $form_state->setRedirect('media_drop.album_list');
  }

  /**
   * Get media types that accept image or video files.
   */
  protected function getMediaTypesWithFileFields() {
    $image_types = [];
    $video_types = [];

    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();

    foreach ($media_types as $media_type_id => $media_type) {
      $source = $media_type->getSource();
      $source_field = $source->getConfiguration()['source_field'];

      // Get field definition.
      $field_definitions = $this->entityTypeManager
        ->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'media',
          'bundle' => $media_type_id,
          'field_name' => $source_field,
        ]);

      if (!empty($field_definitions)) {
        $field_definition = reset($field_definitions);
        $field_type = $field_definition->getType();

        // Check if it's an image or file field.
        if ($field_type === 'image') {
          $image_types[$media_type_id] = $media_type->label() . ' (' . $this->t('Images') . ')';
        }
        elseif ($field_type === 'file') {
          // Check allowed extensions to determine if it's for video.
          $settings = $field_definition->getSettings();
          $extensions = $settings['file_extensions'] ?? '';

          // If contains video extensions.
          if (preg_match('/(mp4|mov|avi|webm|mkv|flv)/i', $extensions)) {
            $video_types[$media_type_id] = $media_type->label() . ' (' . $this->t('Videos') . ')';
          }
          // If contains image extensions.
          if (preg_match('/(jpg|jpeg|png|gif|webp|bmp)/i', $extensions)) {
            $image_types[$media_type_id] = $media_type->label() . ' (' . $this->t('Files') . ')';
          }
          // If no specific extensions, add to both.
          if (empty($extensions) || $extensions === '*') {
            $image_types[$media_type_id] = $media_type->label();
            $video_types[$media_type_id] = $media_type->label();
          }
        }
      }
    }

    return [
      'image' => $image_types,
      'video' => $video_types,
    ];
  }

  /**
   * Check if a media type has filefield_paths enabled on its file field.
   *
   * @param string $media_type_id
   *   The media type ID.
   *
   * @return bool
   *   TRUE if filefield_paths is enabled on the media type's file field.
   */
  protected function hasFileFieldPathsEnabled($media_type_id) {
    if (empty($media_type_id)) {
      return FALSE;
    }

    // Get the media type.
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media_type_id);
    if (!$media_type) {
      return FALSE;
    }

    // Get the source field name for this media type.
    $source_field = $media_type->getSource()->getConfiguration()['source_field'] ?? NULL;
    if (!$source_field) {
      return FALSE;
    }

    // Find the field config for this media type and field.
    $field_configs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'media',
        'bundle' => $media_type_id,
        'field_name' => $source_field,
      ]);

    foreach ($field_configs as $field_config) {
      $settings = $field_config->getThirdPartySettings('filefield_paths');
      if (!empty($settings) && !empty($settings['enabled'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Get Media Directories taxonomy ID.
   */
  protected function getMediaDirectoriesVocabulary() {
    $config = $this->configFactory->get('media_directories.settings');
    $vocabulary_id = $config->get('directory_taxonomy');

    return $vocabulary_id ?: NULL;
  }

  /**
   * Load available roles.
   */
  protected function loadRoles() {
    $roles = [];
    $role_storage = $this->entityTypeManager->getStorage('user_role');
    $role_entities = $role_storage->loadMultiple();

    foreach ($role_entities as $role) {
      // Skip anonymous and authenticated roles if desired.
      if (!in_array($role->id(), ['anonymous', 'authenticated'])) {
        $roles[$role->id()] = $role->label();
      }
    }

    return $roles;
  }

  /**
   * Extract tokens from a stored base_directory path.
   *
   * Attempts to parse out filefield_paths tokens from a full path.
   * For example: "public://media-drop/[date:custom:Y]-[date:custom:m]"
   * would extract "[date:custom:Y]-[date:custom:m]"
   *
   * @param string $base_directory
   *   The full base_directory value (with scheme).
   *
   * @return string
   *   The tokens part if found, empty string otherwise.
   */
  protected function extractTokensFromPath($base_directory) {
    // Remove scheme (e.g., "public://").
    $path = preg_replace('#^[a-z]+://#i', '', $base_directory);

    // Look for patterns like [something:something] or [something:something:something].
    if (preg_match('#(\[.*?\])#', $path, $matches)) {
      // Return everything from the first bracket onwards.
      $start_pos = strpos($path, $matches[1]);
      return substr($path, $start_pos);
    }

    return '';
  }

  /**
   * Check if both selected media types have filefield_paths enabled.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param object|null $album
   *   The album object (if editing).
   *
   * @return bool
   *   TRUE if both image and video media types have filefield_paths enabled.
   */
  protected function bothHaveFFP(FormStateInterface $form_state, $album = NULL) {
    $selected_image_type = $form_state->getValue(['media_types', 'default_media_type'])
      ?? ($album ? $album->default_media_type : '')
      ?? '';

    $selected_video_type = $form_state->getValue(['media_types', 'video_media_type'])
      ?? ($album ? $album->video_media_type : '')
      ?? '';

    return ($selected_image_type && $this->hasFileFieldPathsEnabled($selected_image_type)) &&
      ($selected_video_type && $this->hasFileFieldPathsEnabled($selected_video_type));
  }

  /**
   * Helper method to build directories content render array.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param object|null $album
   *   The album object (if editing).
   *
   * @return array
   *   The directories content render array.
   */
  protected function buildDirectoriesContent(FormStateInterface $form_state, $album = NULL) {
    // Always wrap content in the container with ID 'media-drop-directories-content'.
    $directories_content = [
      '#type' => 'container',
      '#attributes' => ['id' => 'media-drop-directories-content'],
    ];

    // Check if both media types have FFP enabled - blocking message.
    if ($this->bothHaveFFP($form_state, $album)) {
      $directories_content['message'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('<strong>Directory selection disabled:</strong> One or more selected media types have filefield_paths enabled. File paths are managed automatically by filefield_paths configuration. To enable manual directory selection, disable filefield_paths on those media types above.') . '</div>',
      ];
      return $directories_content;
    }

    // Get media types and disable states with proper fallback.
    // Try form_state first, then album (if editing), then default to empty string.
    $image_type = $form_state->getValue(['media_types', 'default_media_type'])
      ?? ($album ? $album->default_media_type : '')
      ?? '';

    $video_type = $form_state->getValue(['media_types', 'video_media_type'])
      ?? ($album ? $album->video_media_type : '')
      ?? '';

    $image_ffp_disable = $form_state->getValue(['media_types', 'default_wrapper', 'disable_filefield_paths_default'])
      ?? FALSE;

    $video_ffp_disable = $form_state->getValue(['media_types', 'video_wrapper', 'disable_filefield_paths_video'])
      ?? FALSE;

    $image_has_ffp = $image_type ? ($this->hasFileFieldPathsEnabled($image_type) && !$image_ffp_disable) : FALSE;
    $video_has_ffp = $video_type ? ($this->hasFileFieldPathsEnabled($video_type) && !$video_ffp_disable) : FALSE;
    $media_directories_enabled = $this->moduleHandler->moduleExists('media_directories');

    if ($image_has_ffp || $video_has_ffp) {
      if ($media_directories_enabled) {
        // Build a detailed message about which media types have filefield_paths enabled.
        $details = [];
        if ($image_has_ffp) {
          $details[] = $this->t('Images follow filefield_paths rules');
        }
        if ($video_has_ffp) {
          $details[] = $this->t('Videos follow filefield_paths rules');
        }

        $directories_content['message'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--status">' .
          '<strong>' . $this->t('Directory structure managed:') . '</strong><br>' .
          $this->t('The following media types use filefield_paths for file organization:') .
          '<ul><li>' . implode('</li><li>', $details) . '</li></ul>' .
          $this->t('The media browser allows navigation within these directory structures.') .
          '</div>',
        ];
      }
      else {
        $directories_content['message'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--warning">' .
          '<strong>' . $this->t('Directory selection disabled:') . '</strong> ' .
          $this->t('One or more selected media types have filefield_paths enabled. Install and enable the media_directories module to enable directory navigation.') .
          '</div>',
        ];
      }
    }

    return $directories_content;
  }

  /**
   * AJAX callback to update filefield_paths warnings and directories section.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function ajaxUpdateFileFieldPathsWarning(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    $form_state->setRebuild(TRUE);

    $trigger = $form_state->getTriggeringElement();
    if (!$trigger || empty($trigger['#media_type_context'])) {
      return $response;
    }

    // Default | video.
    $context = $trigger['#media_type_context'];

    // Nom du wrapper dans le form array.
    $wrapper_key = $context . '_wrapper';

    // ID DOM du wrapper.
    $wrapper_id = 'media-types-' . $context . '-wrapper';

    // 🔹 1er ReplaceCommand : on retourne le wrapper du FORM
    $response->addCommand(new ReplaceCommand(
    '#' . $wrapper_id,
    $form['media_types'][$wrapper_key]
    ));

    // Update the directories section using helper method.
    $directories_content = $this->buildDirectoriesContent($form_state);
    $response->addCommand(new ReplaceCommand(
      '#media-drop-directories-content',
      $directories_content
    ));

    // Update the jstree wrapper visibility.
    $jstree_wrapper = $form['directories']['jstree_wrapper'];
    $response->addCommand(new ReplaceCommand(
      '#media-drop-jstree-wrapper',
      $jstree_wrapper
    ));

    return $response;
  }

  /**
   * AJAX callback when disabling filefield_paths on a media type.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function ajaxDisableFileFieldPaths(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    $form_state->setRebuild(TRUE);

    $trigger = $form_state->getTriggeringElement();
    if (!$trigger || empty($trigger['#media_type_context'])) {
      return $response;
    }

    // Default | video.
    $context = $trigger['#media_type_context'];

    // Nom du wrapper dans le form array.
    $wrapper_key = $context . '_wrapper';

    // ID DOM du wrapper.
    $wrapper_id = 'media-types-' . $context . '-wrapper';

    // Return the rebuilt wrapper from the form.
    $response->addCommand(new ReplaceCommand(
      '#' . $wrapper_id,
      $form['media_types'][$wrapper_key]
    ));

    // Update directories section using helper method.
    $directories_content = $this->buildDirectoriesContent($form_state);
    $response->addCommand(new ReplaceCommand(
      '#media-drop-directories-content',
      $directories_content
    ));

    // Update the jstree wrapper visibility.
    $jstree_wrapper = $form['directories']['jstree_wrapper'];
    $response->addCommand(new ReplaceCommand(
      '#media-drop-jstree-wrapper',
      $jstree_wrapper
    ));

    return $response;
  }

  /**
   * Disable filefield_paths on media type fields if checkboxes are checked.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function disableFileFieldPathsIfNeeded(FormStateInterface $form_state) {
    // Check if default media type disable checkbox is checked.
    $image_ffp_disable = $form_state->getValue(['media_types', 'default_wrapper', 'disable_filefield_paths_default']);
    $image_media_type = $form_state->getValue(['media_types', 'default_media_type']);

    if ($image_ffp_disable && $image_media_type && $this->hasFileFieldPathsEnabled($image_media_type)) {
      $this->disableFileFieldPathsForMediaType($image_media_type);
    }

    // Check if video media type disable checkbox is checked.
    $video_ffp_disable = $form_state->getValue(['media_types', 'video_wrapper', 'disable_filefield_paths_video']);
    $video_media_type = $form_state->getValue(['media_types', 'video_media_type']);

    if ($video_ffp_disable && $video_media_type && $this->hasFileFieldPathsEnabled($video_media_type)) {
      $this->disableFileFieldPathsForMediaType($video_media_type);
    }
  }

  /**
   * Disable filefield_paths on a specific media type's source field.
   *
   * @param string $media_type_id
   *   The media type ID.
   */
  protected function disableFileFieldPathsForMediaType($media_type_id) {
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media_type_id);
    if (!$media_type) {
      return;
    }

    // Get the source field name.
    $source_field = $media_type->getSource()->getConfiguration()['source_field'] ?? NULL;
    if (!$source_field) {
      return;
    }

    // Find and update the field config.
    $field_configs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'media',
        'bundle' => $media_type_id,
        'field_name' => $source_field,
      ]);

    foreach ($field_configs as $field_config) {
      // Remove filefield_paths third party settings.
      $field_config->unsetThirdPartySetting('filefield_paths', 'enabled');
      $field_config->unsetThirdPartySetting('filefield_paths', 'filefield_paths');
      $field_config->save();

      $this->messenger()->addStatus(
        $this->t('Filefield_paths disabled on @media_type media type (@field_name field).',
          ['@media_type' => $media_type->label(), '@field_name' => $source_field]
        )
      );
    }
  }

}
