<?php

namespace Drupal\media_drop\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
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
use Drupal\media_drop\Service\TaxonomyService;
use Drupal\media_drop\Service\NotificationService;
use Psr\Log\LoggerInterface;

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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

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
    TaxonomyService $taxonomy_service,
    NotificationService $notification_service,
    LoggerInterface $logger,
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
    $this->logger = $logger;
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
      $container->get('logger.factory')->get('media_drop')
    );
  }

  /**
   * Upload page for an album.
   */
  public function uploadPage($album_token) {
    $album = $this->loadAlbumByToken($album_token);

    if (!$album) {
      return [
        '#markup' => '<p>' . $this->t('Album not found or inactive.') . '</p>',
      ];
    }

    // Check upload permission early.
    if (!$this->currentUser()->hasPermission('upload media to albums')) {
      $this->logger->warning('User @uid attempted to access upload page without permission for album @album', [
        '@uid' => $this->currentUser()->id(),
        '@album' => $album_token,
      ]);
      return [
        '#markup' => '<p>' . $this->t('You do not have permission to access this page.') . '</p>',
      ];
    }

    $is_anonymous = $this->currentUser()->isAnonymous();
    $can_upload = $this->currentUser()->hasPermission('upload media to albums');
    $can_view = $this->currentUser()->hasPermission('view own uploaded media');
    $can_create_folder = $this->currentUser()->hasPermission('create album folders');
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
        '#value' => $this->t('Drop your media: @album', ['@album' => $album->name]),
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
          'action' => Url::fromRoute('media_drop.ajax_upload', ['album_token' => $album_token])->toString(),
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
      'album_token' => $album_token,
      'album_name' => $album->name,
      'max_file_size' => $this->config('media_drop.settings')->get('max_filesize') ?: 50,
      'accepted_files' => $accepted_files,
      'upload_url' => Url::fromRoute('media_drop.ajax_upload', ['album_token' => $album_token])->toString(),
      'create_folder_url' => Url::fromRoute('media_drop.ajax_create_folder', ['album_token' => $album_token])->toString(),
      'list_folders_url' => Url::fromRoute('media_drop.ajax_list_folders', ['album_token' => $album_token])->toString(),
      'list_media_url' => Url::fromRoute('media_drop.ajax_list_media', ['album_token' => $album_token])->toString(),
      'delete_media_url' => Url::fromRoute('media_drop.ajax_delete_media', ['album_token' => $album_token, 'media_id' => '__MEDIA_ID__'])->toString(),
      'trigger_notification_url' => Url::fromRoute('media_drop.ajax_trigger_notification', ['album_token' => $album_token])->toString(),
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
  public function ajaxUpload($album_token, Request $request) {
    $album = $this->loadAlbumByToken($album_token);

    if (!$album) {
      $this->logger->error('Album not found for token: @token', ['@token' => $album_token]);
      return new JsonResponse(['error' => $this->t('Album not found.')], 404);
    }

    // Check permissions - mandatory check.
    if (!$this->currentUser()->hasPermission('upload media to albums')) {
      $this->logger->warning('User @uid denied upload permission for album @album', [
        '@uid' => $this->currentUser()->id(),
        '@album' => $album->id,
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
    $destination = $album->base_directory . '/' . $safe_user_name;
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
        $media_type = $this->getMediaTypeForMime($mime_type, $album);

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
          FileSystemInterface::EXISTS_RENAME
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
          // If a subfolder was chosen and the album is set to auto-create the structure.
          if ($album->auto_create_structure) {
            $safe_subfolder = !empty($subfolder) ? preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($subfolder)) : NULL;

            // This service call should ensure terms exist for user/subfolder and return the final term ID.
            // NOTE: This assumes `ensureDirectoryTerm` returns the term ID.
            // If it does not, that service needs to be modified to do so.
            $directory_tid = $this->taxonomyService->ensureDirectoryTerm(
              $album->id,
              $safe_user_name,
              $safe_subfolder
            );
          }

          // If no term was found/created yet (e.g. no subfolder, or auto-create is off),
          // fall back to the album's base directory setting.
          if (!$directory_tid && !empty($album->media_directory)) {
            $directory_tid = $album->media_directory;
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
            'album_id' => $album->id,
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

        $this->logger->info('File @file uploaded successfully by user @user to album @album', [
          '@file' => $filename,
          '@user' => $user_name,
          '@album' => $album->id,
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
    $this->notificationService->notifyUploadBatch($album, $uploaded_files_for_notification);
    } */

    return new JsonResponse(['results' => $results]);
  }

  /**
   * Lists subfolders for the current user.
   */
  public function listFolders($album_token, Request $request) {
    $album = $this->loadAlbumByToken($album_token);
    if (!$album) {
      return new JsonResponse(['error' => $this->t('Album not found.')], 404);
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
    $user_directory = $this->fileSystem->realpath($album->base_directory . '/' . $safe_user_name);

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
  public function createFolder($album_token, Request $request) {
    $album = $this->loadAlbumByToken($album_token);

    if (!$album) {
      return new JsonResponse(['error' => $this->t('Album not found.')], 404);
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

    $destination = $album->base_directory . '/' . $safe_user_name . '/' . $safe_folder_name;

    try {
      $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      // Create taxonomy term if Media Directories is enabled.
      if ($this->moduleHandler->moduleExists('media_directories')) {
        $this->taxonomyService->ensureDirectoryTerm(
          $album->id,
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
  public function listMedia($album_token, Request $request) {
    $album = $this->loadAlbumByToken($album_token);

    if (!$album) {
      return new JsonResponse(['error' => $this->t('Album not found.')], 404);
    }

    $session_id = $this->getSessionId();
    $query = $this->database->select('media_drop_uploads', 'u')
      ->fields('u')
      ->condition('album_id', $album->id);

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
  public function deleteMedia($album_token, $media_id) {
    $album = $this->loadAlbumByToken($album_token);

    if (!$album) {
      return new JsonResponse(['error' => $this->t('Album not found.')], 404);
    }

    $session_id = $this->getSessionId();

    // Check that the user owns the media.
    $query = $this->database->select('media_drop_uploads', 'u')
      ->fields('u')
      ->condition('album_id', $album->id)
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
  public function checkUploadAccess($album_token, AccountInterface $account) {
    $album = $this->loadAlbumByToken($album_token);

    if (!$album) {
      return AccessResult::forbidden('Album not found.');
    }

    if (!$account->hasPermission('upload media to albums')) {
      return AccessResult::forbidden('Permission denied.');
    }

    return AccessResult::allowed();
  }

  /**
   * Load an album by token.
   */
  protected function loadAlbumByToken($token) {
    return $this->database->select('media_drop_albums', 'a')
      ->fields('a')
      ->condition('token', $token)
      ->condition('status', 1)
      ->execute()
      ->fetchObject();
  }

  /**
   * Get media type for a MIME type.
   */
  protected function getMediaTypeForMime($mime_type, $album = NULL) {
    // If the album has defined specific media types, use them as priority.
    if ($album) {
      // For images.
      if (strpos($mime_type, 'image/') === 0 && !empty($album->default_media_type)) {
        return $album->default_media_type;
      }
      // For videos.
      if (strpos($mime_type, 'video/') === 0 && !empty($album->video_media_type)) {
        return $album->video_media_type;
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
  public function triggerNotification($album_token, Request $request) {
    $album = $this->loadAlbumByToken($album_token);

    if (!$album) {
      return new JsonResponse(['error' => $this->t('Album not found.')], 404);
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
      ->condition('album_id', $album->id)
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
        $this->notificationService->notifyUploadBatch($user_name, $album, $uploaded_files);
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
