<?php

namespace Drupal\media_drop\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\media\MediaInterface;
use Drupal\field\Entity\FieldConfig;
use Psr\Log\LoggerInterface;

/**
 * Service pour gérer les termes de taxonomie Media Directories.
 */
class TaxonomyService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a TaxonomyService.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * Récupère l'ID de la taxonomie Media Directories.
   */
  public function getMediaDirectoriesVocabulary() {
    if (!\Drupal::moduleHandler()->moduleExists('media_directories')) {
      return NULL;
    }

    $config = \Drupal::config('media_directories.settings');
    return $config->get('directory_taxonomy');
  }

  /**
   * Create or retrieve a taxonomy term for a directory.
   *
   * @param int $depot_id
   *   The ID of the depot.
   * @param string $user_folder_name
   *   The name of the user folder (e.g., "olivier.duchemin").
   * @param string|null $subfolder_name
   *   The optional subfolder name (e.g., "morning").
   *
   * @return int|null
   *   The ID of the created/found term, or NULL if Media Directories is not enabled.
   */
  public function ensureDirectoryTerm($depot_id, $user_folder_name, $subfolder_name = NULL) {
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    if (!$vocabulary_id) {
      return NULL;
    }

    // Retrieving the depot to get the parent term.
    $database = \Drupal::database();
    $depot = $database->select('media_drop_depots', 'a')
      ->fields('a')
      ->condition('id', $depot_id)
      ->execute()
      ->fetchObject();

    if (!$depot) {
      return NULL;
    }

    // Parent term = the depot directory (if set)
    $parent_tid = !empty($depot->media_directory) ? $depot->media_directory : 0;

    // 1. Create/retrieve the term for the user folder
    $user_term_id = $this->getOrCreateTerm($vocabulary_id, $user_folder_name, $parent_tid);

    // 2. If a subfolder is specified, create it under the user folder
    if (!empty($subfolder_name)) {
      return $this->getOrCreateTerm($vocabulary_id, $subfolder_name, $user_term_id);
    }

    return $user_term_id;
  }

  /**
   * Retrieve or create a taxonomy term.
   *
   * @param string $vocabulary_id
   *   The ID of the vocabulary.
   * @param string $term_name
   *   The name of the term.
   * @param int $parent_tid
   *   The ID of the parent term (0 for root).
   *
   * @return int|null
   *   The ID of the term.
   */
  protected function getOrCreateTerm($vocabulary_id, $term_name, $parent_tid = 0) {
    // Check if the term already exists.
    $query = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', $vocabulary_id)
      ->condition('name', $term_name)
      ->accessCheck(FALSE);

    if ($parent_tid > 0) {
      $query->condition('parent', $parent_tid);
    }

    $tids = $query->execute();

    if (!empty($tids)) {
      // The term already exists.
      return reset($tids);
    }

    // Create the new term.
    $term = Term::create([
      'vid' => $vocabulary_id,
      'name' => $term_name,
      'parent' => $parent_tid > 0 ? [$parent_tid] : [],
    ]);

    $term->save();

    return $term->id();
  }

  /**
   * Create the term structure for a complete depot.
   *
   * @param int $depot_id
   *   The ID of the depot.
   * @param string $depot_name
   *   The name of the depot.
   *
   * @return int|null
   *   The ID of the created depot term.
   */
  public function createDepotDirectoryStructure($depot_id, $depot_name) {
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    if (!$vocabulary_id) {
      return NULL;
    }

    // Create a term for the depot itself if it doesn't exist.
    $depot_term_id = $this->getOrCreateTerm($vocabulary_id, $depot_name, 0);

    // Update the depot with this term.
    $database = \Drupal::database();
    $database->update('media_drop_depots')
      ->fields(['media_directory' => $depot_term_id])
      ->condition('id', $depot_id)
      ->execute();

    return $depot_term_id;
  }

  /**
   * Clean up empty terms (without associated media).
   *
   * @param string $vocabulary_id
   *   The ID of the vocabulary.
   */
  public function cleanupEmptyTerms($vocabulary_id) {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabulary_id]);

    foreach ($terms as $term) {
      // Check if any media uses this term.
      $query = $this->entityTypeManager
        ->getStorage('media')
        ->getQuery()
        ->condition('directory', $term->id())
        ->accessCheck(FALSE);

      $count = $query->count()->execute();

      if ($count == 0) {
        // No media uses this term, it can be deleted.
        $term->delete();
      }
    }
  }

  /**
   * Move media files to the new directory corresponding to the taxonomy term.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param int|null $new_term_id
   *   The ID of the new directory term (NULL or 0 for root).
   *
   * @return bool
   *   TRUE if the move was successful, FALSE otherwise.
   */
  public function moveMediaFilesToDirectory($media, $new_term_id = NULL) {
    if (!$media) {
      return FALSE;
    }

    try {
      // Build the target path based on the directory term.
      $target_path = $this->getMediaURIScheme($media) . '://' .
        $this->buildDirectoryPathFromTerm($new_term_id);

      // Retrieve the media's file fields.
      $file_fields = $this->getMediaFileFields($media);

      if (empty($file_fields)) {
        $this->logger->notice('Media @mid has no file fields', ['@mid' => $media->id()]);
        return TRUE;
      }

      $moved_any = FALSE;

      foreach ($file_fields as $field_name) {
        if ($media->hasField($field_name)) {
          $field_values = $media->get($field_name)->getValue();

          foreach ($field_values as $delta => $value) {
            if (isset($value['target_id'])) {
              $file = $this->entityTypeManager->getStorage('file')->load($value['target_id']);

              if ($file) {
                $old_uri = $file->getFileUri();
                $filename = basename($old_uri);
                $new_uri = $target_path . '/' . $filename;

                // Move the physical file.
                if (\Drupal::service('file_system')->move($old_uri, $new_uri, FileSystemInterface::EXISTS_REPLACE)) {
                  // Update the file URI.
                  $file->setFileUri($new_uri);
                  $file->save();
                  $moved_any = TRUE;

                  $this->logger->info('Moved file from @old to @new', [
                    '@old' => $old_uri,
                    '@new' => $new_uri,
                  ]);
                }
                else {
                  $this->logger->warning('Failed to move file @old to @new', [
                    '@old' => $old_uri,
                    '@new' => $new_uri,
                  ]);
                }
              }
            }
          }
        }
      }

      // Return TRUE even if no files were moved (not an error).
      return $moved_any ? TRUE : TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error moving media files: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Build the physical path based on a directory term.
   *
   * @param int|null $term_id
   *   The ID of the directory term (NULL or 0 for root).
   *
   * @return string
   *   The physical path (e.g., 'public://2025-12' or 'public://').
   */
  public function buildDirectoryPathFromTerm($term_id = NULL) {
    // If no term or 0, return the root of the public scheme.
    if (!$term_id || $term_id == 0) {
      return '';
    }

    try {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);

      if (!$term) {
        return '';
      }

      // Build the path from the term and its parents.
      $path_parts = [];
      $current_term = $term;

      while ($current_term) {
        array_unshift($path_parts, $current_term->getName());

        // Retrieve the parent.
        $parent = $current_term->get('parent');
        if ($parent && !empty($parent->target_id)) {
          $current_term = $this->entityTypeManager->getStorage('taxonomy_term')
            ->load($parent->target_id);
        }
        else {
          break;
        }
      }

      return implode('/', $path_parts);
    }
    catch (\Exception $e) {
      $this->logger->error('Error building directory path: @message', [
        '@message' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Retrieves the names of fields containing files in a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return array
   *   Array of names of fields containing files.
   */
  protected function getMediaFileFields($media) {
    $file_fields = [];

    try {
      $bundle = $media->bundle();
      $field_configs = $this->entityTypeManager->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'media',
          'bundle' => $bundle,
        ]);

      foreach ($field_configs as $field_config) {
        $field_type = $field_config->getType();
        // Include image, file, video_file, and audio_file fields.
        if (in_array($field_type, ['image', 'file', 'video_file', 'audio_file'])) {
          $file_fields[] = $field_config->getName();
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting media file fields: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $file_fields;
  }

  /**
   * Returns the uri_scheme (public|private) of the file/image field of a Media.
   *
   * It is assumed that there is only one field of type file or image.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The Media entity.
   *
   * @return string|null
   *   'public', 'private' or NULL if no field found.
   */
  public function getMediaURIScheme(MediaInterface $media): ?string {
    $field_name = $this->getMediaFileFields($media);
    if (empty($field_name)) {
      return NULL;
    }
    else {
      $field_config = FieldConfig::loadByName(
        'media',
        $media->bundle(),
        $field_name
        );

      if ($field_config) {
        // 'public' or 'private'
        return $field_config->getSetting('uri_scheme') ?? 'public';
      }
    }
  }

}
