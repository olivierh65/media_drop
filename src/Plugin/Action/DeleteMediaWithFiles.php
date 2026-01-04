<?php

namespace Drupal\media_drop\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;

/**
 * Delete media and its file(s).
 *
 * @Action(
 *   id = "media_drop_delete_media_with_files",
 *   label = @Translation("Delete media and its files"),
 *   type = "media"
 * )
 */
class DeleteMediaWithFiles extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof MediaInterface) {
      return;
    }

    // Supprimer les fichiers liés.
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if (in_array($definition->getType(), ['file', 'image'], TRUE)) {
        foreach ($entity->get($field_name)->referencedEntities() as $file) {
          $file->delete();
        }
      }
    }

    // Supprimer le media.
    $entity->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object instanceof MediaInterface
      ? $object->access('delete', $account, $return_as_object)
      : FALSE;
  }

}
