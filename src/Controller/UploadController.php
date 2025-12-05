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
use Drupal\Core\Access\AccessResult;

/**
 * Controller for the media drop interface.
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
   * Constructs a new UploadController.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, FileRepositoryInterface $fileRepository, $mimeTypeGuesser, $fileUrlGenerator) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->fileRepository = $fileRepository;
    $this->mimeTypeGuesser = $mimeTypeGuesser;
    $this->fileUrlGenerator = $fileUrlGenerator;
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
      $container->get('file_url_generator')
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

    // Load necessary libraries.
    $build['#attached']['library'][] = 'media_drop/dropzone';
    $build['#attached']['library'][] = 'media_drop/upload_interface';
    // Pass data to JavaScript.
    $build['#attached']['drupalSettings']['media_drop'] = [
      'album_token' => $album_token,
      'album_name' => $album->name,
      'upload_url' => Url::fromRoute('media_drop.ajax_upload', ['album_token' => $album_token])->toString(),
      'create_folder_url' => Url::fromRoute('media_drop.ajax_create_folder', ['album_token' => $album_token])->toString(),
      'list_media_url' => Url::fromRoute('media_drop.ajax_list_media', ['album_token' => $album_token])->toString(),
      'delete_media_url' => Url::fromRoute('media_drop.ajax_delete_media', ['album_token' => $album_token, 'media_id' => '__MEDIA_ID__'])->toString(),
      'can_upload' => $this->currentUser()->hasPermission('upload media to albums'),
      'can_delete' => $this->currentUser()->hasPermission('delete own uploaded media'),
      'can_create_folder' => $this->currentUser()->hasPermission('create album folders'),
      'can_view' => $this->currentUser()->hasPermission('view own uploaded media'),
      'is_anonymous' => $this->currentUser()->isAnonymous(),
      'user_name' => $this->currentUser()->isAnonymous() ? '' : $this->currentUser()->getAccountName(),
    ];

    $build['content'] = [
      '#theme' => 'media_drop_upload_page',
      '#album_name' => $album->name,
    ];

    return $build;
  }

  /**
   * Upload AJAX handler.
   */
  public function ajaxUpload($album_token, Request $request) {
    $album = $this->loadAlbumByToken($album_token);

    if (!$album) {
      return new JsonResponse(['error' => $this->t('Album not found.')], 404);
    }

    // Check permissions.
    if (!$this->currentUser()->hasPermission('upload media to albums')) {
      return new JsonResponse(['error' => $this->t('Permission denied.')], 403);
    }

    $files = $request->files->get('file');
    $user_name = $request->request->get('user_name');
    $subfolder = $request->request->get('subfolder', '');

    // For anonymous users, the name is required.
    if ($this->currentUser()->isAnonymous() && empty($user_name)) {
      return new JsonResponse(['error' => $this->t('Please provide your name.')], 400);
    }

    if (!$user_name) {
      $user_name = $this->currentUser()->getAccountName();
    }

    // Sanitize the username for use as a folder name.
    $safe_user_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($user_name));

    // Build the destination path.
    $destination = $album->base_directory . '/' . $safe_user_name;
    if (!empty($subfolder)) {
      $safe_subfolder = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($subfolder));
      $destination .= '/' . $safe_subfolder;
    }

    // Create the directory if it does not exist.
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $results = [];

    if (!is_array($files)) {
      $files = [$files];
    }

    foreach ($files as $file) {
      if (!$file) {
        continue;
      }

      try {
        // Get file information BEFORE manipulation.
        $filename = $file->getClientOriginalName();
        $destination_uri = $destination . '/' . $filename;

        // Guess the MIME type based on the file extension.
        // We use several methods to maximize the chances of success.
        $mime_type = $file->getClientMimeType();

        // If the client's MIME type is not reliable, use the guesser.
        if ($mime_type === 'application/octet-stream' || empty($mime_type)) {
          $mime_type = $this->mimeTypeGuesser->guessMimeType($filename);
        }

        // If still no result, try with the temporary file.
        if ($mime_type === 'application/octet-stream' || empty($mime_type)) {
          $mime_type = $this->mimeTypeGuesser->guessMimeType($file->getRealPath());
        }

        // Determine the media type based on the MIME type.
        $media_type = $this->getMediaTypeForMime($mime_type, $album);

        if (!$media_type) {
          $results[] = [
            'success' => FALSE,
            'filename' => $filename,
            'error' => $this->t('Unsupported file type: @mime', ['@mime' => $mime_type]),
          ];
          continue;
        }

        // Copy the uploaded file to the final destination
        // using writeData() which preserves the name and MIME type.
        $data = file_get_contents($file->getRealPath());
        $file_entity = $this->fileRepository->writeData(
          $data,
          $destination_uri,
          FileSystemInterface::EXISTS_RENAME
        );

        if (!$file_entity) {
          $results[] = [
            'success' => FALSE,
            'filename' => $filename,
            'error' => $this->t('Error saving the file.'),
          ];
          continue;
        }

        // Create the media entity.
        $media_values = [
          'bundle' => $media_type,
          'name' => $filename,
          'uid' => $this->currentUser()->id(),
        ];

        // If the album has a media directories directory defined, use it.
        if (!empty($album->media_directory)) {
          // Check if media_directories is enabled.
          if (\Drupal::moduleHandler()->moduleExists('media_directories')) {
            // Get the taxonomy field name from the config.
            $config = \Drupal::config('media_directories.settings');
            $vocabulary_id = $config->get('directory_taxonomy');

            if ($vocabulary_id) {
              // The field is usually 'directory' in media_directories.
              $media_values['directory'] = ['target_id' => $album->media_directory];
            }
          }
        }

        $media = Media::create($media_values);

        // Find the appropriate file field.
        $field_name = $this->getMediaSourceField($media_type);
        if ($field_name) {
          $media->set($field_name, $file_entity->id());
        }

        $media->save();

        // Save the upload in the tracking table.
        $session_id = $this->getSessionId();
        $this->database->insert('media_drop_uploads')
          ->fields([
            'album_id' => $album->id,
            'media_id' => $media->id(),
            'uid' => $this->currentUser()->id(),
            'user_name' => $user_name,
            'session_id' => $session_id,
            'subfolder' => $subfolder,
            'created' => \Drupal::time()->getRequestTime(),
          ])
          ->execute();

        $results[] = [
          'success' => TRUE,
          'filename' => $filename,
          'media_id' => $media->id(),
          'thumbnail' => $this->getMediaThumbnail($media),
        ];

      }
      catch (\Exception $e) {
        $results[] = [
          'success' => FALSE,
          'filename' => $file->getClientOriginalName(),
          'error' => $e->getMessage(),
        ];
      }
    }

    return new JsonResponse(['results' => $results]);
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
      return new JsonResponse(['error' => $this->t('Please provide your name.')], 400);
    }

    if (!$user_name) {
      $user_name = $this->currentUser()->getAccountName();
    }

    $safe_user_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($user_name));
    $safe_folder_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower($folder_name));

    $destination = $album->base_directory . '/' . $safe_user_name . '/' . $safe_folder_name;

    try {
      $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

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
   * List the user's media.
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
   * Delete a media item.
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
      return new JsonResponse(['error' => $this->t('Media not found or you do not have permission.')], 403);
    }

    try {
      $media = Media::load($media_id);
      if ($media) {
        $media->delete();
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
   * Access check for uploading.
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
   * Get the media type for a MIME type.
   */
  protected function getMediaTypeForMime($mime_type, $album = NULL) {
    // If the album has defined specific media types, use them first.
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
   * Get the session ID.
   */
  protected function getSessionId() {
    if ($this->currentUser()->isAnonymous()) {
      $session = \Drupal::request()->getSession();
      if (!$session->has('media_drop_session_id')) {
        $session->set('media_drop_session_id', uniqid('session_', TRUE));
      }
      return $session->get('media_drop_session_id');
    }
    return 'user_' . $this->currentUser()->id();
  }

  /**
   * Get a media item's thumbnail.
   */
  protected function getMediaThumbnail($media) {
    $thumbnail = $media->get('thumbnail')->entity;
    if ($thumbnail) {
      return $this->fileUrlGenerator->generateAbsoluteString($thumbnail->getFileUri());
    }
    return NULL;
  }

}
