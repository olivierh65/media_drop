<?php

namespace Drupal\media_drop\Traits;

use Drupal\views\ViewExecutable;

/**
 *
 */
trait MediaViewsCleanupTrait {

  /**
   *
   */
  protected function filterMediaResults(ViewExecutable $view) {
    $filtered_results = [];
    $file_system = \Drupal::service('file_system');

    foreach ($view->result as $row) {
      $media = $row->_entity ?? NULL;
      if (!$media) {
        $filtered_results[] = $row;
        continue;
      }

      try {
        $source_field = $media->getSource()->getConfiguration()['source_field'];
        if ($media->hasField($source_field) && !$media->get($source_field)->isEmpty()) {
          $file_entity = $media->get($source_field)->entity;
          if ($file_entity) {
            $file_path = $file_system->realpath($file_entity->getFileUri());
            if ($file_path && file_exists($file_path)) {
              $filtered_results[] = $row;
              continue;
            }
          }
        }
      }
      catch (\Exception $e) {
        $filtered_results[] = $row;
      }
    }
    $view->result = $filtered_results;
    // Important pour la pagination : recalculer le total si nécessaire.
    $view->total_rows = count($filtered_results);
  }

}
