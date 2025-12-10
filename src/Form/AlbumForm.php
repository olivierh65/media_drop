<?php

namespace Drupal\media_drop\Form;

use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\media_drop\Service\TaxonomyService;

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
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->extensionListModule = $extension_list_module;
    $this->requestStack = $request_stack;
    $this->time = $time;
    $this->taxonomyService = $taxonomy_service;
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
      $container->get('media_drop.taxonomy_service')
    );

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
    ];

    $form['media_types']['default_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Media type for images'),
      '#options' => ['' => $this->t('- Use default MIME mapping -')] + $image_media_types,
      '#default_value' => $album ? $album->default_media_type : '',
      '#description' => $this->t('Drupal media type that will be created for image files (JPEG, PNG, etc.)'),
    ];

    $form['media_types']['video_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Media type for videos'),
      '#options' => ['' => $this->t('- Use default MIME mapping -')] + $video_media_types,
      '#default_value' => $album ? $album->video_media_type : '',
      '#description' => $this->t('Drupal media type that will be created for video files (MP4, MOV, etc.)'),
    ];

    $form['directories'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Directories'),
      '#tree' => TRUE,
    ];

    $form['directories']['base_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage directory'),
      '#default_value' => $album ? $album->base_directory : 'public://media-drop/',
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Example: public://media-drop/birthday2025<br>Media will be saved in subfolders by user.'),
    ];

    $form['directories']['media_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Directory in Media Browser'),
      '#default_value' => $album ? $album->media_directory : '',
      '#maxlength' => 255,
      '#description' => $this->t('Optional path in the Media Browser to organize media (e.g: /albums/birthday2025).<br>Leave empty to use root.'),
      '#access' => !$this->extensionListModule->getPath('media_directories'),
    ];

    // If media_directories module is enabled, propose the taxonomy.
    if ($this->extensionListModule->getPath('media_directories')) {
      $vocabulary_id = $this->getMediaDirectoriesVocabulary();

      if ($vocabulary_id) {
        $terms = $this->getTermOptions($vocabulary_id);

        $form['directories']['media_directory_term'] = [
          '#type' => 'select',
          '#title' => $this->t('Media Directories'),
          '#options' => ['' => $this->t('- Root -')] + $terms,
          '#default_value' => $album ? $album->media_directory : '',
          '#description' => $this->t('Select the Media Directories taxonomy term where uploaded media will be classified.<br>This taxonomy is used by the Media Directories module to organize media.'),
        ];

        $form['directories']['create_new_term'] = [
          '#type' => 'details',
          '#title' => $this->t('Create a new directory'),
          '#open' => FALSE,
          '#tree' => TRUE,
        ];

        $form['directories']['create_new_term']['new_term_name'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Name of new directory'),
          '#description' => $this->t('If you want to create a new directory in Media Directories, enter its name here.'),
        ];

        $form['directories']['create_new_term']['parent_term'] = [
          '#type' => 'select',
          '#title' => $this->t('Parent directory'),
          '#options' => [0 => $this->t('- Root -')] + $terms,
          '#description' => $this->t('Under which directory to create the new directory.'),
        ];

        $form['directories']['auto_create_structure'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Automatically create structure'),
          '#default_value' => TRUE,
          '#description' => $this->t('If checked, user folders and subfolders will be automatically added to the Media Directories taxonomy during uploads.'),
        ];
      }
      else {
        $form['directories']['media_directory_warning'] = [
          '#markup' => '<div class="messages messages--warning">' .
          $this->t('The Media Directories module is enabled but no taxonomy is configured. Please configure Media Directories first.') .
          '</div>',
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
      '#title' => $this->t('Notify roles'),
      '#options' => $roles,
      '#default_value' => $album && !empty($album->notification_roles) ? explode(',', $album->notification_roles) : [],
      '#description' => $this->t('Select which roles should receive email notifications about uploads to this album.'),
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $base_directory = $form_state->getValue(['directories', 'base_directory']);

    // Check that the directory starts with a valid scheme.
    // @todo handle the case where $base_directory is NULL.
    if (!empty($base_directory) && !preg_match('/^(public|private):\/\//', $base_directory)) {
      $form_state->setErrorByName('directories][base_directory', $this->t('The directory must start with public:// or private://'));
    }

    // If media_directories is not enabled, validate the text field.
    if (!$this->extensionListModule->getPath('media_directories')) {
      $media_directory = $form_state->getValue(['directories', 'media_directory']);
      if (!empty($media_directory)) {
        $media_directory = trim($media_directory);
        if (substr($media_directory, 0, 1) === '/') {
          $media_directory = substr($media_directory, 1);
        }
        if (substr($media_directory, -1) === '/') {
          $media_directory = substr($media_directory, 0, -1);
        }
        $form_state->setValue(['directories', 'media_directory'], $media_directory);
      }
    }
    else {
      // Validate that if a new term is requested, a name is provided.
      $new_term_name = $form_state->getValue(['directories', 'create_new_term', 'new_term_name']);
      if (!empty($new_term_name) && empty(trim($new_term_name))) {
        $form_state->setErrorByName('directories][create_new_term][new_term_name',
        $this->t('The directory name cannot be empty.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $album_id = $form_state->getValue('album_id');

    // Handle creation of a new term if requested (media_directories enabled)
    $media_directory_value = '';
    if ($this->extensionListModule->getPath('media_directories')) {
      $new_term_name = $form_state->getValue(['directories', 'create_new_term', 'new_term_name']);

      if (!empty($new_term_name)) {
        // Create the new term.
        $vocabulary_id = $this->getMediaDirectoriesVocabulary();
        $parent_tid = $form_state->getValue(['directories', 'create_new_term', 'parent_term']);

        $term = Term::create([
          'vid' => $vocabulary_id,
          'name' => $new_term_name,
          'parent' => $parent_tid ? [$parent_tid] : [],
        ]);
        $term->save();

        $media_directory_value = $term->id();
        $this->messenger()->addStatus($this->t('The directory "@name" has been created.', ['@name' => $new_term_name]));
      }
      else {
        // Use the selected term.
        $media_directory_value = $form_state->getValue(['directories', 'media_directory_term']);
      }
    }
    else {
      // Use the text field if media_directories is not enabled.
      $media_directory_value = $form_state->getValue(['directories', 'media_directory']);
    }

    $values = [
      'name' => $form_state->getValue('name'),
      'base_directory' => rtrim($form_state->getValue(['directories', 'base_directory']), '/'),
      'media_directory' => $media_directory_value,
      'default_media_type' => $form_state->getValue(['media_types', 'default_media_type']),
      'video_media_type' => $form_state->getValue(['media_types', 'video_media_type']),
      'auto_create_structure' => $this->extensionListModule->getPath('media_directories') && $form_state->getValue(['directories', 'auto_create_structure']) ? 1 : 0,
      'enable_notifications' => $form_state->getValue(['notifications', 'enable_notifications']) ? 1 : 0,
      'notification_roles' => implode(',', array_filter($form_state->getValue(['notifications', 'notification_roles']))),
      'notification_email' => $form_state->getValue(['notifications', 'notification_email']) ?: '',
      'status' => $form_state->getValue('status') ? 1 : 0,
      'updated' => $this->time->getRequestTime(),
    ];

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

      // Retrieve the ID of the newly created album.
      $new_album_id = $this->database->select('media_drop_albums', 'a')
        ->fields('a', ['id'])
        ->condition('token', $values['token'])
        ->execute()
        ->fetchField();

      // Automatically create directory structure if media_directories is enabled.
      if ($new_album_id && $this->extensionListModule->getPath('media_directories')) {
        try {
          // Create the parent album term.
          $album_term_id = $this->taxonomyService->createAlbumDirectoryStructure(
          $new_album_id,
          $values['name']
          );

          // Update the album with the parent term ID.
          if ($album_term_id) {
            $this->database->update('media_drop_albums')
              ->fields(['media_directory' => $album_term_id])
              ->condition('id', $new_album_id)
              ->execute();

            $this->messenger()->addStatus($this->t('The album and "Directories" structure have been created automatically.'));
          }
        }
        catch (\Exception $e) {
          $this->messenger()->addWarning($this->t('The album has been created but the directory structure could not be created: @error', [
            '@error' => $e->getMessage(),
          ]));
        }
      }
      else {
        $this->messenger()->addStatus($this->t('The album has been created.'));
      }
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
   * Get Media Directories taxonomy ID.
   */
  protected function getMediaDirectoriesVocabulary() {
    $config = $this->configFactory->get('media_directories.settings');
    $vocabulary_id = $config->get('directory_taxonomy');

    return $vocabulary_id ?: NULL;
  }

  /**
   * Get term options for a vocabulary with hierarchy.
   */
  protected function getTermOptions($vocabulary_id, $parent = 0, $depth = 0) {
    $options = [];

    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, $parent, 1, TRUE);

    foreach ($terms as $term) {
      $prefix = str_repeat('--', $depth);
      $options[$term->id()] = $prefix . ' ' . $term->getName();

      // Recursively get children.
      $children = $this->getTermOptions($vocabulary_id, $term->id(), $depth + 1);
      if (!empty($children)) {
        $options += $children;
      }
    }

    return $options;
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

}
