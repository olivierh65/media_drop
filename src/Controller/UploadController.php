<?php

namespace Drupal\media_drop\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\Entity\Media;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Access\AccessResult;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\media_taxonomy_service\Service\DirectoryService;
use Drupal\media_drop\Service\NotificationService;
use Psr\Log\LoggerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Controller for media upload interface.
 */
class UploadController extends ControllerBase {

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file repository service.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * The MIME type guesser.
   *
   * @var \Symfony\Component\Mime\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

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
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The taxonomy service.
   *
   * @var \Drupal\media_drop\Service\TaxonomyService
   */
  protected $taxonomyService;

  /**
   * The notification service.
   *
   * @var \Drupal\media_drop\Service\NotificationService
   */
  protected $notificationService;

  /**
   * The directory service.
   *
   * @var \Drupal\media_taxonomy_service\Service\DirectoryService
   */
  protected $directoryService;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new UploadController.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    FileRepositoryInterface $fileRepository,
    $mimeTypeGuesser,
    $fileUrlGenerator,
    RequestStack $request_stack,
    TimeInterface $time,
    ModuleHandlerInterface $module_handler,
    DirectoryService $taxonomy_service,
    NotificationService $notification_service,
    DirectoryService $directory_service,
    LoggerInterface $logger,
    MessengerInterface $messenger,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->fileRepository = $fileRepository;
    $this->mimeTypeGuesser = $mimeTypeGuesser;
    $this->fileUrlGenerator = $fileUrlGenerator;
    $this->requestStack = $request_stack;
    $this->time = $time;
    $this->moduleHandler = $module_handler;
    $this->taxonomyService = $taxonomy_service;
    $this->notificationService = $notification_service;
    $this->directoryService = $directory_service;
    $this->logger = $logger;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('file.mime_type.guesser'),
      $container->get('file_url_generator'),
      $container->get('request_stack'),
      $container->get('datetime.time'),
      $container->get('module_handler'),
      $container->get('media_drop.taxonomy_service'),
      $container->get('media_drop.notification_service'),
      $container->get('media_taxonomy_service.directory_service'),
      $container->get('logger.factory')->get('media_drop'),
      $container->get('messenger'),
    );
  }

  /**
   * Upload page for a depot.
   */
  public function uploadPage($depot_token) {
    $depot = $this->loadDepotByToken($depot_token);

    if (!$depot) {
      return [
        '#markup' => '<p>' . $this->t('Depot not found or inactive.') . '</p>',
      ];
    }

    // Perform maintenance: recreate deleted directories and cleanup missing media.
    $this->recreateDeletedDirectories($depot);
    $this->cleanupMissingMedia($depot);

    // Check upload permission early.
    if (!$this->currentUser()->hasPermission('upload media to depots')) {
      $this->logger->warning('User @uid attempted to access upload page without permission for depot @depot', [
        '@uid' => $this->currentUser()->id(),
        '@depot' => $depot_token,
      ]);
      return [
        '#markup' => '<p>' . $this->t('You do not have permission to access this page.') . '</p>',
      ];
    }

    $is_anonymous = $this->currentUser()->isAnonymous();
    $can_upload = $this->currentUser()->hasPermission('upload media to depots');
    $can_view = $this->currentUser()->hasPermission('view own uploaded media');
    $can_create_folder = $this->currentUser()->hasPermission('create depot folders');
    $can_delete = $this->currentUser()->hasPermission('delete own uploaded media');

    $allowed_extensions = $this->config('media_drop.settings')->get('allowed_extensions');
    $accepted_files = '';
    if ($allowed_extensions) {
      $extensions = array_map('trim', explode(' ', trim($allowed_extensions)));
      $extensions = array_filter($extensions, function ($ext) {
        return !empty($ext);
      });
      $accepted_files = implode(',', array_map(function ($ext) {
        return '.' . ltrim($ext, '.');
      }, $extensions));
    }
    else {
      $accepted_files = 'image/*,video/*';
    }

    // Build the page structure.
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'media-drop-upload-container',
        'class' => ['media-drop-interface'],
      ],
    ];

    // Header.
    $build['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['media-drop-header']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => $this->t('Drop your media: @depot', ['@depot' => $depot->name]),
      ],
    ];

    // User info section.
    $build['user_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['media-drop-user-info']],
    ];

    $build['user_info']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'label',
      '#attributes' => ['for' => 'user-name-input'],
      '#value' => $this->t('Your identifiant') . ' :',
    ];

    $build['user_info']['user_name'] = [
      '#type' => 'textfield',
      '#default_value' => $is_anonymous ? '' : $this->currentUser()->getAccountName(),
      '#attributes' => [
        'id' => 'user-name-input',
        'placeholder' => $this->t('Enter your name'),
        'required' => 'required',
      ],
    ];

    if (!$is_anonymous) {
      $build['user_info']['user_name']['#attributes']['readonly'] = 'readonly';
    }

    if ($is_anonymous) {
      $build['user_info']['save_button'] = [
        '#type' => 'button',
        '#value' => $this->t('Save'),
        '#attributes' => [
          'id' => 'save-user-name',
          'class' => ['button'],
        ],
      ];
    }

    // Folder section.
    if ($can_create_folder) {
      $build['folder_section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['media-drop-folder-section']],
      ];

      $build['folder_section']['label'] = [
        '#type' => 'html_tag',
        '#tag' => 'label',
        '#value' => $this->t('Organize in a sub-folder (optional)') . ' :',
      ];

      $build['folder_section']['folder_controls'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['folder-controls']],
      ];

      $build['folder_section']['folder_controls']['folder_select'] = [
        '#type' => 'select',
        '#options' => ['' => $this->t('-- Main folder --')],
        '#attributes' => ['id' => 'folder-select'],
      ];

      $build['folder_section']['folder_controls']['create_folder'] = [
        '#type' => 'button',
        '#value' => $this->t('Create folder'),
        '#attributes' => [
          'id' => 'create-folder',
          'class' => ['button'],
        ],
      ];

      $build['folder_section']['new_folder_form'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'new-folder-form',
          'style' => 'display: none;',
        ],
      ];

      $build['folder_section']['new_folder_form']['new_folder_name'] = [
        '#type' => 'textfield',
        '#attributes' => [
          'id' => 'new-folder-name',
          'placeholder' => $this->t('Folder name'),
        ],
      ];

      $build['folder_section']['new_folder_form']['confirm_folder'] = [
        '#type' => 'button',
        '#value' => $this->t('Create'),
        '#attributes' => [
          'id' => 'confirm-folder',
          'class' => ['button', 'button--primary'],
        ],
      ];

      $build['folder_section']['new_folder_form']['cancel_folder'] = [
        '#type' => 'button',
        '#value' => $this->t('Cancel'),
        '#attributes' => [
          'id' => 'cancel-folder',
          'class' => ['button'],
        ],
      ];
    }

    // Dropzone section.
    if ($can_upload) {
      $build['dropzone'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['media-drop-dropzone']],
      ];

      $build['dropzone']['form'] = [
        '#type' => 'html_tag',
        '#tag' => 'form',
        '#attributes' => [
          'action' => Url::fromRoute('media_drop.ajax_upload', ['depot_token' => $depot_token])->toString(),
          'class' => ['dropzone'],
          'id' => 'media-dropzone',
        ],
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['dz-message']],
          '#value' => $this->t('Drag and drop your photos and videos here or click to select'),
        ],
      ];
    }
    else {
      $build['no_permission'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['media-drop-no-permission']],
        'message' => [
          '#markup' => '<p>' . $this->t('You do not have permission to drop media.') . '</p>',
        ],
      ];
    }

    // Gallery section.
    if ($can_view) {
      $build['gallery'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['media-drop-gallery']],
      ];

      $build['gallery']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Your dropped media'),
      ];

      $build['gallery']['media_grid'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'media-gallery',
          'class' => ['media-grid'],
        ],
      ];
    }

    // Attach libraries.
    $build['#attached']['library'][] = 'media_drop/dropzone';
    $build['#attached']['library'][] = 'media_drop/upload_interface';

    // Pass settings to JavaScript.
    $build['#attached']['drupalSettings']['media_drop'] = [
      'depot_token' => $depot_token,
      'depot_name' => $depot->name,
      'max_file_size' => $this->config('media_drop.settings')->get('max_filesize') ?: 50,
      'accepted_files' => $accepted_files,
      'upload_url' => Url::fromRoute('media_drop.ajax_upload', ['depot_token' => $depot_token])->toString(),
      'check_duplicate_url' => Url::fromRoute('media_drop.ajax_check_duplicate', ['depot_token' => $depot_token])->toString(),
      'create_folder_url' => Url::fromRoute('media_drop.ajax_create_folder', ['depot_token' => $depot_token])->toString(),
      'list_folders_url' => Url::fromRoute('media_drop.ajax_list_folders', ['depot_token' => $depot_token])->toString(),
      'list_media_url' => Url::fromRoute('media_drop.ajax_list_media', ['depot_token' => $depot_token])->toString(),
      'delete_media_url' => Url::fromRoute('media_drop.ajax_delete_media', ['depot_token' => $depot_token, 'media_id' => '__MEDIA_ID__'])->toString(),
      'trigger_notification_url' => Url::fromRoute('media_drop.ajax_trigger_notification', ['depot_token' => $depot_token])->toString(),
      'can_upload' => $can_upload,
      'can_delete' => $can_delete,
      'can_create_folder' => $can_create_folder,
      'can_view' => $can_view,
      'is_anonymous' => $is_anonymous,
      'user_name' => $is_anonymous ? '' : $this->currentUser()->getAccountName(),
    ];

    return $build;
  }

  /**
   * Upload AJAX handler.
   */
  public function ajaxUpload($depot_token, Request $request) {
    $depot = $this->loadDepotByToken($depot_token);

    if (!$depot) {
      $this->logger->error('Depot not found for token: @token', ['@token' => $depot_token]);
      return new JsonResponse(['error' => $this->t('Depot not found.')], 404);
    }

    // Check permissions - mandatory check.
    if (!$this->currentUser()->hasPermission('upload media to depots')) {
      $this->logger->warning('User @uid denied upload permission for depot @depot', [
        '@uid' => $this->currentUser()->id(),
        '@depot' => $depot->id,
      ]);
      return new JsonResponse(['error' => $this->t('Permission denied.')], 403);
    }

    $files = $request->files->get('file');
    $user_name = $request->request->get('user_name');
    $subfolder = $request->request->get('subfolder', '');

    // For anonymous users, the name is required.
    if ($this->currentUser()->isAnonymous() && empty($user_name)) {
      return new JsonResponse(['error' => $this->t('Please enter your name.')], 400);
    }

    if (!$user_name) {
      $user_name = $this->currentUser()->getAccountName();
    }

    // Sanitize username for use as folder name.
    $safe_user_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($user_name));

    // Build destination path.
    $destination = $depot->base_directory . '/' . $safe_user_name;
    if (!empty($subfolder)) {
      $safe_subfolder = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($subfolder));
      $destination .= '/' . $safe_subfolder;
    }

    // Check if we can write to destination.
    try {
      $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to prepare directory @dest: @error', [
        '@dest' => $destination,
        '@error' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => $this->t('Cannot write to upload directory.')], 500);
    }

    $results = [];
    // Accumulate files for single notification email.
    $uploaded_files_for_notification = [];

    if (!is_array($files)) {
      $files = [$files];
    }

    foreach ($files as $file) {
      if (!$file) {
        continue;
      }

      try {
        // Validate file existence and readability.
        if (!$file->isValid()) {
          $error_msg = $file->getErrorMessage();
          $this->logger->warning('File upload error for @file: @error', [
            '@file' => $file->getClientOriginalName(),
            '@error' => $error_msg,
          ]);
          $results[] = [
            'success' => FALSE,
            'filename' => $file->getClientOriginalName(),
            'error' => $error_msg,
          ];
          continue;
        }

        // Get file information BEFORE manipulation.
        $filename = $file->getClientOriginalName();
        $destination_uri = $destination . '/' . $filename;

        // Check if file already exists with same size (duplicate check).
        // Duplicate is checked on JS side, but we keep this as a server-side fallback.
        $duplicate_check = $this->checkDuplicateFile($destination_uri, $file->getSize());
        if ($duplicate_check['exists']) {
          $this->logger->notice('Duplicate file detected: @file (size: @size)', [
            '@file' => $filename,
            '@size' => $file->getSize(),
          ]);
          $results[] = [
            'success' => FALSE,
            'filename' => $filename,
            'error' => $this->t('This file already exists in the destination folder.'),
            'is_duplicate' => TRUE,
          ];
          continue;
        }

        // Guess MIME type based on file extension.
        // Use multiple methods to maximize success chances.
        $mime_type = $file->getClientMimeType();

        // If client MIME type is unreliable, use the guesser.
        if ($mime_type === 'application/octet-stream' || empty($mime_type)) {
          $mime_type = $this->mimeTypeGuesser->guessMimeType($filename);
        }

        // If still no result, try with the temporary file.
        if ($mime_type === 'application/octet-stream' || empty($mime_type)) {
          $mime_type = $this->mimeTypeGuesser->guessMimeType($file->getRealPath());
        }

        // Determine media type based on MIME type.
        $media_type = $this->getMediaTypeForMime($mime_type, $depot);

        if (!$media_type) {
          $this->logger->notice('Unsupported MIME type @mime for file @file', [
            '@mime' => $mime_type,
            '@file' => $filename,
          ]);
          $results[] = [
            'success' => FALSE,
            'filename' => $filename,
            'error' => $this->t('Unsupported file type: @mime', ['@mime' => $mime_type]),
          ];
          continue;
        }

        // Copy uploaded file to final destination
        // using writeData() which preserves the name and MIME type.
        $data = file_get_contents($file->getRealPath());
        if ($data === FALSE) {
          $this->logger->error('Failed to read uploaded file: @file', ['@file' => $filename]);
          $results[] = [
            'success' => FALSE,
            'filename' => $filename,
            'error' => $this->t('Error reading the file.'),
          ];
          continue;
        }

        $file_entity = $this->fileRepository->writeData(
          $data,
          $destination_uri,
          FileExists::Replace,
                );

        if (!$file_entity) {
          $this->logger->error('Failed to save file to destination: @dest', ['@dest' => $destination_uri]);
          $results[] = [
            'success' => FALSE,
            'filename' => $filename,
            'error' => $this->t('Error saving the file.'),
          ];
          continue;
        }

        // Create media entity.
        $media_values = [
          'bundle' => $media_type,
          'name' => $filename,
          'uid' => $this->currentUser()->id(),
        ];

        $directory_tid = NULL;
        // Handle Media Directories taxonomy assignment.
        if ($this->moduleHandler->moduleExists('media_directories')) {
          // Auto-create the structure for user/subfolder in taxonomy.
          $safe_subfolder = !empty($subfolder) ? preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($subfolder)) : NULL;

          // This service call should ensure terms exist for user/subfolder and return the final term ID.
          // NOTE: This assumes `ensureDirectoryTerm` returns the term ID.
          // If it does not, that service needs to be modified to do so.
          $directory_tid = $this->taxonomyService->ensureDirectoryTerm(
            $depot->id,
            $safe_user_name,
            $safe_subfolder
          );

          // If no term was found/created yet (e.g. no subfolder, or auto-create is off),
          // fall back to the depot's base directory setting.
          if (!$directory_tid && !empty($depot->media_directory)) {
            $directory_tid = $depot->media_directory;
          }
        }

        if ($directory_tid) {
          // The field name 'directory' is the default for the media_directories module.
          $media_values['directory'] = ['target_id' => $directory_tid];
        }

        $media = Media::create($media_values);

        // Find the appropriate file field.
        $field_name = $this->getMediaSourceField($media_type);
        if ($field_name) {
          $media->set($field_name, $file_entity->id());
        }

        $media->save();

        // Record the upload in the tracking table.
        $session_id = $this->getSessionId();
        $this->database->insert('media_drop_uploads')
          ->fields([
            'depot_id' => $depot->id,
            'media_id' => $media->id(),
            'uid' => $this->currentUser()->id(),
            'user_name' => $user_name,
            'session_id' => $session_id,
            'subfolder' => $subfolder,
            'created' => $this->time->getRequestTime(),
          ])
          ->execute();

        // Accumulate for notification email.
        $uploaded_files_for_notification[] = [
          'filename' => $filename,
          'user_name' => $user_name,
          'media' => $media,
        ];

        $results[] = [
          'success' => TRUE,
          'filename' => $filename,
          'media_id' => $media->id(),
          'thumbnail' => $this->getMediaThumbnail($media),
        ];

        $this->logger->info('File @file uploaded successfully by user @user to depot @depot', [
          '@file' => $filename,
          '@user' => $user_name,
          '@depot' => $depot->id,
        ]);

      }
      catch (\Exception $e) {
        $filename = isset($file) ? $file->getClientOriginalName() : 'unknown';
        $this->logger->error('Exception during file upload for @file: @error', [
          '@file' => $filename,
          '@error' => $e->getMessage(),
        ]);
        $results[] = [
          'success' => FALSE,
          'filename' => $filename,
          'error' => $this->t('Upload error: @error', ['@error' => $e->getMessage()]),
        ];
      }
    }

    // Send a single notification email with all uploaded files.
    // send one mail per upload batch.
    /* if (!empty($uploaded_files_for_notification)) {
    $this->notificationService->notifyUploadBatch($depot, $uploaded_files_for_notification);
    } */

    return new JsonResponse(['results' => $results]);
  }

  /**
   * Lists subfolders for the current user.
   */
  public function listFolders($depot_token, Request $request) {
    $depot = $this->loadDepotByToken($depot_token);
    if (!$depot) {
      return new JsonResponse(['error' => $this->t('Depot not found.')], 404);
    }

    $user_name = '';
    if ($this->currentUser()->isAnonymous()) {
      $user_name = $request->query->get('user_name');
    }
    else {
      $user_name = $this->currentUser()->getAccountName();
    }

    if (empty($user_name)) {
      return new JsonResponse(['folders' => []]);
    }

    $safe_user_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($user_name));
    $user_directory = $this->fileSystem->realpath($depot->base_directory . '/' . $safe_user_name);

    $folders = [];
    if (is_dir($user_directory)) {
      $files = scandir($user_directory);
      foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && is_dir($user_directory . '/' . $file)) {
          // For simplicity, we use the sanitized name as both value and text.
          $folders[] = [
            'safe_name' => $file,
            'name' => $file,
          ];
        }
      }
    }

    return new JsonResponse(['folders' => $folders]);
  }

  /**
   * Create a subfolder.
   */
  public function createFolder($depot_token, Request $request) {
    $depot = $this->loadDepotByToken($depot_token);

    if (!$depot) {
      return new JsonResponse(['error' => $this->t('Depot not found.')], 404);
    }

    $folder_name = $request->request->get('folder_name');
    $user_name = $request->request->get('user_name');

    if (empty($folder_name)) {
      return new JsonResponse(['error' => $this->t('Folder name required.')], 400);
    }

    if ($this->currentUser()->isAnonymous() && empty($user_name)) {
      return new JsonResponse(['error' => $this->t('Please enter your name.')], 400);
    }

    if (!$user_name) {
      $user_name = $this->currentUser()->getAccountName();
    }

    $safe_user_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($user_name));
    $safe_folder_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($folder_name));

    $destination = $depot->base_directory . '/' . $safe_user_name . '/' . $safe_folder_name;

    try {
      $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      // Create taxonomy term if Media Directories is enabled.
      if ($this->moduleHandler->moduleExists('media_directories')) {
        $this->taxonomyService->ensureDirectoryTerm(
          $depot->id,
          $safe_user_name,
          $safe_folder_name
        );
      }

      return new JsonResponse([
        'success' => TRUE,
        'folder_name' => $folder_name,
        'safe_folder_name' => $safe_folder_name,
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * List media uploaded by the user.
   */
  public function listMedia($depot_token, Request $request) {
    $depot = $this->loadDepotByToken($depot_token);

    if (!$depot) {
      return new JsonResponse(['error' => $this->t('Depot not found.')], 404);
    }

    $session_id = $this->getSessionId();
    $query = $this->database->select('media_drop_uploads', 'u')
      ->fields('u')
      ->condition('depot_id', $depot->id);

    if ($this->currentUser()->isAnonymous()) {
      $query->condition('session_id', $session_id);
    }
    else {
      $query->condition('uid', $this->currentUser()->id());
    }

    $uploads = $query->execute()->fetchAll();

    $media_list = [];
    foreach ($uploads as $upload) {
      $media = Media::load($upload->media_id);
      if ($media) {
        $media_list[] = [
          'id' => $media->id(),
          'name' => $media->label(),
          'subfolder' => $upload->subfolder,
          'created' => $upload->created,
          'thumbnail' => $this->getMediaThumbnail($media),
        ];
      }
    }

    return new JsonResponse(['media' => $media_list]);
  }

  /**
   * Delete a media.
   */
  public function deleteMedia($depot_token, $media_id) {
    $depot = $this->loadDepotByToken($depot_token);

    if (!$depot) {
      return new JsonResponse(['error' => $this->t('Depot not found.')], 404);
    }

    $session_id = $this->getSessionId();

    // Check that the user owns the media.
    $query = $this->database->select('media_drop_uploads', 'u')
      ->fields('u')
      ->condition('depot_id', $depot->id)
      ->condition('media_id', $media_id);

    if ($this->currentUser()->isAnonymous()) {
      $query->condition('session_id', $session_id);
    }
    else {
      $query->condition('uid', $this->currentUser()->id());
    }

    $upload = $query->execute()->fetchObject();

    if (!$upload) {
      return new JsonResponse(['error' => $this->t('Media not found or you don\'t have permission.')], 403);
    }

    try {
      $media = Media::load($media_id);
      if ($media) {
        // Get files before deleting media.
        $files_to_delete = [];

        // Try to identify the file fields dynamically.
        $source_field = $media->getSource()->getConfiguration()['source_field'];
        if ($media->hasField($source_field) && !$media->get($source_field)->isEmpty()) {
          foreach ($media->get($source_field) as $field_item) {
            if ($field_item->entity) {
              $files_to_delete[] = $field_item->entity;
            }
          }
        }

        // Delete the media entity.
        $media->delete();

        // Delete physical files using Drupal File Repository.
        foreach ($files_to_delete as $file) {
          try {
            $media->get($source_field)->entity->delete();
            $this->logger->info('File deleted: ' . $file->getFilename());
          }
          catch (\Exception $e) {
            $this->logger->warning('Failed to delete file: ' . $file->getFilename() . ' - ' . $e->getMessage());
          }
        }
      }

      $this->database->delete('media_drop_uploads')
        ->condition('id', $upload->id)
        ->execute();

      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * Access control for upload.
   */
  public function checkUploadAccess($depot_token, AccountInterface $account) {
    $depot = $this->loadDepotByToken($depot_token);

    if (!$depot) {
      return AccessResult::forbidden('Depot not found.');
    }

    if (!$account->hasPermission('upload media to depots')) {
      return AccessResult::forbidden('Permission denied.');
    }

    return AccessResult::allowed();
  }

  /**
   * Load an depot by token.
   */
  protected function loadDepotByToken($token) {
    return $this->database->select('media_drop_depots', 'a')
      ->fields('a')
      ->condition('token', $token)
      ->condition('status', 1)
      ->execute()
      ->fetchObject();
  }

  /**
   * Get media type for a MIME type.
   */
  protected function getMediaTypeForMime($mime_type, $depot = NULL) {
    // If the depot has defined specific media types, use them as priority.
    if ($depot) {
      // For images.
      if (strpos($mime_type, 'image/') === 0 && !empty($depot->default_media_type)) {
        return $depot->default_media_type;
      }
      // For videos.
      if (strpos($mime_type, 'video/') === 0 && !empty($depot->video_media_type)) {
        return $depot->video_media_type;
      }
    }

    // Otherwise, use the default MIME mapping.
    $result = $this->database->select('media_drop_mime_mapping', 'm')
      ->fields('m', ['media_type'])
      ->condition('mime_type', $mime_type)
      ->execute()
      ->fetchField();

    return $result ?: NULL;
  }

  /**
   * Get the source field name for a media type.
   */
  protected function getMediaSourceField($media_type_id) {
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media_type_id);
    if ($media_type) {
      return $media_type->getSource()->getConfiguration()['source_field'];
    }
    return NULL;
  }

  /**
   * Get session ID.
   */
  protected function getSessionId() {
    if ($this->currentUser()->isAnonymous()) {
      $request = $this->requestStack->getCurrentRequest();
      $session = $request->getSession();
      if (!$session->has('media_drop_session_id')) {
        $session->set('media_drop_session_id', uniqid('session_', TRUE));
      }
      return $session->get('media_drop_session_id');
    }
    return 'user_' . $this->currentUser()->id();
  }

  /**
   * Get media thumbnail.
   */
  protected function getMediaThumbnail($media) {
    $thumbnail = $media->get('thumbnail')->entity;
    if ($thumbnail) {
      return $this->fileUrlGenerator->generateAbsoluteString($thumbnail->getFileUri());
    }
    return NULL;
  }

  /**
   * Trigger notification after all uploads are complete.
   */
  public function triggerNotification($depot_token, Request $request) {
    $depot = $this->loadDepotByToken($depot_token);

    if (!$depot) {
      return new JsonResponse(['error' => $this->t('Depot not found.')], 404);
    }

    $user_name = $request->request->get('user_name');
    $subfolder = $request->request->get('subfolder', '');

    if (empty($user_name)) {
      return new JsonResponse(['error' => $this->t('User name required.')], 400);
    }

    // Get the session ID.
    $session_id = $this->getSessionId();

    // Get all media uploaded by this user in this session/request.
    $safe_user_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($user_name));
    $query = $this->database->select('media_drop_uploads', 'u')
      ->fields('u')
      ->condition('depot_id', $depot->id)
      ->condition('user_name', $user_name);

    if ($this->currentUser()->isAnonymous()) {
      $query->condition('session_id', $session_id);
    }
    else {
      $query->condition('uid', $this->currentUser()->id());
    }

    // Get uploads from the last minute (to capture recent uploads).
    $query->condition('created', $this->time->getRequestTime() - 60, '>=');

    $uploads = $query->execute()->fetchAll();

    if (!empty($uploads)) {
      $uploaded_files = [];
      foreach ($uploads as $upload) {
        $media = Media::load($upload->media_id);
        if ($media) {
          $uploaded_files[] = [
            'filename' => $media->label(),
            'user_name' => $user_name,
            'media' => $media,
          ];
        }
      }

      // Send a single notification email with all uploaded files.
      if (!empty($uploaded_files)) {
        $this->notificationService->notifyUploadBatch($user_name, $depot, $uploaded_files);
      }
    }

    return new JsonResponse(['success' => TRUE]);
  }

  /**
   * Check if a file already exists with the same size.
   *
   * @param string $destination_uri
   *   The destination URI.
   * @param int $file_size
   *   The file size in bytes.
   *
   * @return array
   *   Array with 'exists' boolean and optional 'path' and 'size'.
   */

  /**
   * Check if a file already exists via AJAX.
   */
  public function checkDuplicate($depot_token, Request $request) {
    $depot = $this->loadDepotByToken($depot_token);

    if (!$depot) {
      return new JsonResponse(['error' => $this->t('Depot not found.')], 404);
    }

    $filename = $request->request->get('filename');
    $file_size = $request->request->get('file_size');
    $user_name = $request->request->get('user_name');
    $subfolder = $request->request->get('subfolder', '');

    if (empty($filename) || empty($file_size) || empty($user_name)) {
      return new JsonResponse(['error' => $this->t('Missing required parameters.')], 400);
    }

    // Build destination path (same logic as upload)
    $safe_user_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($user_name));
    $destination = $depot->base_directory . '/' . $safe_user_name;
    if (!empty($subfolder)) {
      $safe_subfolder = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($subfolder));
      $destination .= '/' . $safe_subfolder;
    }

    $destination_uri = $destination . '/' . $filename;

    // Check if file exists.
    $duplicate_check = $this->checkDuplicateFile($destination_uri, intval($file_size));

    if ($duplicate_check['exists']) {
      return new JsonResponse([
        'exists' => TRUE,
        'message' => $this->t('This file already exists (same name and size)'),
      ]);
    }

    return new JsonResponse(['exists' => FALSE]);
  }

  /**
   * Recreate directories that may have been deleted.
   *
   * Based on the media_directories taxonomy terms, recreate any directories
   * that are missing from the file system.
   *
   * @param object $depot
   *   The depot object.
   */
  protected function recreateDeletedDirectories($depot) {
    if (!$this->moduleHandler->moduleExists('media_directories')) {
      // Media directories module not enabled.
      return;
    }

    // Get the vocabulary ID from the depot's media_directory field.
    if (empty($depot->media_directory)) {
      // No vocabulary configured for this depot.
      return;
    }

    try {
      $tree = $this->directoryService
        ->getDirectoryTreeFromTermId($depot->media_directory);

      $this->directoryService
        ->ensureDirectoriesExist($tree, $depot->base_directory);
    }
    catch (\RuntimeException $e) {
      $this->messenger->addError(
        $this->t('An error occurred while creating directories.')
      );
    }
  }

  /**
   * Clean up media entities that have been deleted or moved.
   *
   * Remove media entities from the database if their source files no longer
   * exist in the depot's directory structure.
   *
   * @param object $depot
   *   The depot object.
   */
  protected function cleanupMissingMedia($depot) {
    // Get all media associated with this depot.
    $query = $this->database->select('media_drop_uploads', 'u')
      ->fields('u', ['media_id', 'id'])
      ->condition('depot_id', $depot->id);

    $uploads = $query->execute()->fetchAll();

    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $deletedCount = 0;

    foreach ($uploads as $upload) {
      $media = $mediaStorage->load($upload->media_id);

      if (!$media) {
        // Media entity doesn't exist, remove from tracking.
        $this->database->delete('media_drop_uploads')
          ->condition('id', $upload->id)
          ->execute();
        continue;
      }

      // Get the source file field.
      try {
        $source_field = $media->getSource()->getConfiguration()['source_field'];

        if (!$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
          // No file attached, delete the media.
          $media->delete();
          $this->database->delete('media_drop_uploads')
            ->condition('id', $upload->id)
            ->execute();
          $deletedCount++;
          continue;
        }

        // Check if the file still exists.
        $file_exists = FALSE;
        foreach ($media->get($source_field) as $field_item) {
          if ($field_item->entity) {
            $file_uri = $field_item->entity->getFileUri();
            $file_path = $this->fileSystem->realpath($file_uri);

            if (file_exists($file_path)) {
              $file_exists = TRUE;
              break;
            }
          }
        }

        // If file doesn't exist, delete the media and tracking record.
        if (!$file_exists) {
          $media->delete();
          $this->database->delete('media_drop_uploads')
            ->condition('id', $upload->id)
            ->execute();
          $deletedCount++;
        }
      }
      catch (\Exception $e) {
        $this->logger->warning(
          'Error checking media @media_id: @error',
          ['@media_id' => $upload->media_id, '@error' => $e->getMessage()]
              );
      }
    }

    if ($deletedCount > 0) {
      $this->logger->info(
        'Cleaned up @count missing media entries for depot @depot',
        ['@count' => $deletedCount, '@depot' => $depot->id]
      );
    }
  }

  /**
   *
   */
  protected function checkDuplicateFile($destination_uri, $file_size) {
    // Check if file exists at destination.
    if (file_exists($destination_uri)) {
      $existing_size = filesize($destination_uri);
      // Consider it a duplicate if sizes match (same content is likely).
      if ($existing_size === $file_size) {
        return [
          'exists' => TRUE,
          'path' => $destination_uri,
          'size' => $existing_size,
        ];
      }
    }
    return ['exists' => FALSE];
  }

}
