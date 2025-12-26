<?php

namespace Drupal\media_drop\Utilities;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\media\MediaInterface;

/**
 * Utility class for field configuration operations.
 */
class FieldConfigHelper {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a FieldConfigHelper.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Get all editable media reference fields for a node bundle.
   *
   * @param string $bundle
   *   The node bundle ID.
   *
   * @return array
   *   Array of field configurations keyed by field name.
   */
  public function getMediaFieldsOfBundle($bundle) {
    $field_configs = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'node',
        'bundle' => $bundle,
      ]);

    $media_fields = [];

    foreach ($field_configs as $field_config) {
      if ($field_config->get('field_type') === 'entity_reference') {
        $settings = $field_config->get('settings');
        if ($settings['target_type'] === 'media') {
          $media_fields[$field_config->getName()] = $field_config;
        }
      }
    }

    return $media_fields;
  }

  /**
   * Get field property by name.
   *
   * Example properties:
   * - 'label': Field label
   * - 'description': Field description
   * - 'required': Is required (boolean)
   * - 'settings': Array of settings
   * - 'default_value': Default value
   * - 'field_type': Type of field
   * - 'hidden': Is hidden (boolean)
   *
   * @param object $field_config
   *   The field config entity.
   * @param string $property
   *   The property name.
   * @param mixed $default
   *   Default value if property doesn't exist.
   *
   * @return mixed
   *   The property value.
   */
  public function getFieldProperty($field_config, $property, $default = NULL) {
    // Direct method access.
    $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $property)));
    if (method_exists($field_config, $method)) {
      return $field_config->{$method}();
    }

    // Fall back to get() method.
    return $field_config->get($property) ?? $default;
  }

  /**
   * Get all properties of a field config.
   *
   * @param object $field_config
   *   The field config entity.
   *
   * @return array
   *   Array of all properties.
   */
  public function getAllFieldProperties($field_config) {
    return [
      'name' => $field_config->getName(),
      'type' => $field_config->getType(),
      'entity_type' => $field_config->getTargetEntityTypeId(),
      'bundle' => $field_config->getTargetBundle(),
      'label' => $field_config->get('label'),
      'description' => $field_config->get('description'),
      'required' => $field_config->get('required'),
      'hidden' => $field_config->get('hidden'),
      'translatable' => $field_config->get('translatable'),
      'default_value' => $field_config->get('default_value'),
      'settings' => $field_config->get('settings'),
    ];
  }

  /**
   * Get field storage configuration.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $field_name
   *   The field name.
   *
   * @return object|null
   *   The field storage config, or NULL if not found.
   */
  public function getFieldStorage($entity_type, $field_name) {
    return $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load($entity_type . '.' . $field_name);
  }

  /**
   * Get cardinality of a field.
   *
   * @param object $field_storage
   *   The field storage config.
   *
   * @return int
   *   Cardinality (-1 = unlimited).
   */
  public function getFieldCardinality($field_storage) {
    return (int) $field_storage->get('cardinality');
  }

  /**
   * Get all allowed values for a select field.
   *
   * @param object $field_storage
   *   The field storage config.
   *
   * @return array
   *   Array of allowed values.
   */
  public function getSelectFieldOptions($field_storage) {
    $settings = $field_storage->get('settings');
    $allowed_values = $settings['allowed_values'] ?? [];

    $options = [];
    foreach ($allowed_values as $key => $value) {
      if (is_array($value)) {
        $options[$key] = $value['label'] ?? $key;
      }
      else {
        $options[$key] = $value;
      }
    }

    return $options;
  }

  /**
   * Get target entity type for a reference field.
   *
   * @param object $field_config
   *   The field config entity.
   *
   * @return string|null
   *   The target entity type.
   */
  public function getTargetType($field_config) {
    $settings = $field_config->get('settings');
    return $settings['target_type'] ?? NULL;
  }

  /**
   * Get all entities of a type (for reference field dropdowns).
   *
   * @param string $entity_type
   *   The entity type.
   * @param int $limit
   *   Maximum number of entities to load.
   *
   * @return array
   *   Array of entities keyed by ID.
   */
  public function getEntitiesForReference($entity_type, $limit = 50) {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $query = $storage->getQuery();
    $query->accessCheck(TRUE)->range(0, $limit);
    $ids = $query->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Build form widget options array for an entity reference field.
   *
   * @param string $entity_type
   *   The target entity type.
   * @param bool $include_empty
   *   Whether to include an empty option.
   * @param int $limit
   *   Maximum number of entities to load.
   *
   * @return array
   *   Array of options keyed by entity ID.
   */
  public function getEntityReferenceOptions($entity_type, $include_empty = TRUE, $limit = 50) {
    $options = [];

    if ($include_empty) {
      $options[''] = '- None -';
    }

    $entities = $this->getEntitiesForReference($entity_type, $limit);
    foreach ($entities as $entity) {
      $options[$entity->id()] = $entity->label();
    }

    return $options;
  }

  /**
   * Add media to node media reference field.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media to add.
   * @param \Drupal\node\NodeInterface $node
   *   The node to add media to.
   * @param string|null $field_name
   *   Optional specific field name. If not specified, uses first media field.
   *
   * @return bool
   *   TRUE if media was added.
   */
  public function addMediaToNode(MediaInterface $media, NodeInterface $node, $field_name = NULL) {
    $bundle = $node->bundle();

    if ($field_name) {
      // Add to specific field.
      if ($node->hasField($field_name)) {
        $current_values = $node->get($field_name)->getValue();
        $current_values[] = ['target_id' => $media->id()];
        $node->set($field_name, $current_values);
        $node->save();
        return TRUE;
      }
      return FALSE;
    }

    // Add to first available media field.
    $media_fields = $this->getMediaFieldsOfBundle($bundle);

    foreach ($media_fields as $field_name => $field_config) {
      if ($node->hasField($field_name)) {
        $current_values = $node->get($field_name)->getValue();
        $current_values[] = ['target_id' => $media->id()];
        $node->set($field_name, $current_values);
        $node->save();
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Apply field values to media.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param array $field_values
   *   Array of field names and values.
   *
   * @return bool
   *   TRUE if any values were applied.
   */
  public function applyFieldValuesToMedia(MediaInterface $media, array $field_values) {
    $applied = FALSE;

    foreach ($field_values as $field_name => $value) {
      if (!empty($value) && $media->hasField($field_name)) {
        $media->set($field_name, $value);
        $applied = TRUE;
      }
    }

    if ($applied) {
      $media->save();
    }

    return $applied;
  }

  /**
   * Check if a field is a media reference.
   *
   * @param object $field_config
   *   The field config entity.
   *
   * @return bool
   *   TRUE if the field references media.
   */
  public function isMediaReferenceField($field_config) {
    if ($field_config->getType() !== 'entity_reference') {
      return FALSE;
    }

    $settings = $field_config->get('settings');
    return ($settings['target_type'] ?? NULL) === 'media';
  }

  /**
   * Get nodes with media reference fields.
   *
   * @param string|null $bundle
   *   Optional specific bundle.
   * @param int $limit
   *   Maximum number of nodes.
   *
   * @return array
   *   Array of node entities.
   */
  public function getNodesWithMediaFields($bundle = NULL, $limit = 50) {
    $nodes = [];

    if ($bundle) {
      $bundles = [$bundle];
    }
    else {
      $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
      $bundles = array_keys($node_types);
    }

    foreach ($bundles as $bundle_id) {
      $media_fields = $this->getMediaFieldsOfBundle($bundle_id);

      if (!empty($media_fields)) {
        $storage = $this->entityTypeManager->getStorage('node');
        $query = $storage->getQuery();
        $query->condition('type', $bundle_id)
          ->accessCheck(TRUE)
          ->range(0, $limit);
        $nids = $query->execute();

        if (!empty($nids)) {
          $nodes += $storage->loadMultiple($nids);
        }
      }
    }

    return $nodes;
  }

}
