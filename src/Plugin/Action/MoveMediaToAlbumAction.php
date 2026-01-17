<?php

namespace Drupal\media_drop\Plugin\Action;

use Drupal\media_album_av_common\Service\DirectoryService;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Moves media entities to an album node with optional directory and field values.
 *
 * @Action(
 *   id = "media_drop_move_to_album",
 *   label = @Translation("Move to Album"),
 *   type = "media",
 *   category = @Translation("Media Drop"),
 *   confirm = TRUE
 * )
 */
class MoveMediaToAlbumAction extends BaseAlbumAction {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, DirectoryService $taxonomy_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $taxonomy_service);

    $this->move = TRUE;
  }

  /**
   *
   */
  public function executeMultiple(array $entities) {
    // Récupérer le service de stockage temporaire privé.
    $tempstore_factory = \Drupal::service('tempstore.private');

    // Créer ou récupérer une collection "media_drop".
    $tempstore = $tempstore_factory->get('media_drop');

    $order = $tempstore->get('ordered_media_ids');

    // Check if entities have been ordered.
    if ($order) {
      $insert_order = [];
      foreach ($entities as $entity) {
        $key = array_search($entity->id(), $order);
        if ($key !== FALSE) {
          $insert_order[$key] = $entity;
          \Drupal::logger('media_drop')->notice('Processing media @mid at order position @pos', [
            '@mid' => $entity->id(),
            '@pos' => $key,
          ]);
        }
        else {
          \Drupal::logger('media_drop')->notice('Processing media @mid with no specific order', [
            '@mid' => $entity->id(),
          ]);
        }
      }

      foreach ($insert_order as $entity) {
        $this->execute($entity);
      }
    }
    else {
      // No specific order - process as is.
      foreach ($entities as $entity) {
        $this->execute($entity);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity) {
      return;
    }

    if ($entity->getEntityTypeId() !== 'media') {
      return;
    }

    if (!$this->albumNode) {
      $this->albumNode = $this->entityTypeManager
        ->getStorage('node')
        ->load($this->configuration['album_id']);
    }

    if (!$this->albumNode) {
      \Drupal::messenger()->addError(
        $this->t('album node not found.')
      );
      return;
    }

    // Check if media is already in the album - skip if it is.
    $existing_media = $this->getMediaIdsInAlbum($this->albumNode);
    $media_id = $entity->id();
    if (isset($existing_media[$media_id])) {
      \Drupal::logger('media_drop')->info('Skipping media @mid - already in album', [
        '@mid' => $media_id,
      ]);
      return;
    }

    // Move to directory if configured (including ROOT which is NULL in database, 0 internally).
    if (isset($this->configuration['directory_tid']) && $this->configuration['directory_tid'] !== NULL && $this->configuration['directory_tid'] !== '') {

      if ($entity->hasField('directory')) {
        // Set directory field. For ROOT (0 or -1), convert to NULL for database storage.
        if ($this->configuration['directory_tid'] == 0 || $this->configuration['directory_tid'] == -1) {
          // ROOT directory - set as NULL in database.
          $entity->set('directory', NULL);
        }
        else {
          // Regular directory - set the target_id.
          $entity->set('directory', $this->configuration['directory_tid']);
        }
      }
      else {
        \Drupal::logger('media_drop')->warning('execute() - Media @mid does not have directory field', [
          '@mid' => $entity->id(),
        ]);
      }

      // Move the physical files to the corresponding directory.
      $this->taxonomyService->moveMediaFilesToDirectory($entity, $this->configuration['directory_tid'], TRUE);
    }
    else {
      \Drupal::logger('media_drop')->notice('execute() - No directory_tid configured (value: @val)', [
        '@val' => var_export($this->configuration['directory_tid'], TRUE),
      ]);
    }

    // Add media to album node fields.
    $media_field_found = FALSE;

    try {
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'node')
        ->condition('bundle', $this->albumNode->bundle());

      $field_ids = $query->execute();
      $field_configs = [];

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error loading field configs in execute: @message', [
          '@message' => $e->getMessage(),
        ]);
      $field_configs = [];
    }

    foreach ($field_configs as $field_config) {
      if ($field_config->get('field_type') === 'entity_reference') {
        $field_name = $field_config->getName();

        if ($field_config->getSetting('target_type') === 'media') {
          $media_field_found = TRUE;
          $target_bundles = $field_config->getSetting('handler_settings')['target_bundles'] ?? [];

          if (empty($target_bundles) || in_array($entity->bundle(), $target_bundles)) {
            $this->addMediaToField($this->albumNode, $field_name, $entity);
            break;
          }
        }
      }
    }

    if (!$media_field_found) {
      \Drupal::messenger()->addWarning(
        $this->t('No media reference field found on album "@album" that accepts media type "@type".', [
          '@album' => $this->albumNode->label(),
          '@type' => $entity->bundle(),
        ])
      );
    }

    // Apply field values to media entity.
    if (!empty($this->configuration['album_field_values'])) {
      $this->applyFieldValuesToMedia($entity);
    }

    $entity->save();

    if ($media_field_found) {
      $this->albumNode->save();
    }
  }

}
