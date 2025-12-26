<?php

namespace Drupal\media_drop\Examples;

/**
 * Practical examples of accessing field_config and building VBO actions.
 *
 * These are code snippets you can copy and adapt to your needs.
 */
class FieldConfigExamples {

  /**
   * Example 1: List all media reference fields in a specific node bundle.
   *
   * Use this when you need to know which fields accept media in a node type.
   *
   * @code
   * $helper = new FieldConfigHelper($entity_type_manager);
   * $media_fields = $helper->getMediaFieldsOfBundle('article');
   *
   * foreach ($media_fields as $field_name => $field_config) {
   *   echo "Field: " . $field_name;
   *   echo " - Label: " . $field_config->get('label');
   *   echo " - Required: " . ($field_config->get('required') ? 'Yes' : 'No');
   * }
   * @endcode
   */
  public static function example1() {
    // See implementation at getMediaFieldsOfBundle()
  }

  /**
   * Example 2: Load a specific field_config and access all its properties.
   *
   * @code
   * $entity_type_manager = \Drupal::entityTypeManager();
   *
   * // Method A: Load by ID (entity_type.bundle.field_name)
   * $field_config = $entity_type_manager
   *   ->getStorage('field_config')
   *   ->load('node.article.field_cover_image');
   *
   * if ($field_config) {
   *   // Access various properties
   *   echo $field_config->getName();                    // "field_cover_image"
   *   echo $field_config->getType();                    // "entity_reference"
   *   echo $field_config->getTargetEntityTypeId();      // "node"
   *   echo $field_config->getTargetBundle();            // "article"
   *   echo $field_config->get('label');                // "Cover Image"
   *   echo $field_config->get('description');          // "Select a cover image"
   *   echo $field_config->get('required');             // 1 or 0
   *   echo $field_config->get('hidden');               // 0 or 1
   *
   *   // For entity_reference, get the target type
   *   $settings = $field_config->get('settings');
   *   echo $settings['target_type'];                   // "media"
   * }
   * @endcode
   */
  public static function example2() {
    $entity_type_manager = \Drupal::entityTypeManager();

    $field_config = $entity_type_manager
      ->getStorage('field_config')
      ->load('node.article.field_cover_image');

    if ($field_config) {
      $all_properties = [
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

      return $all_properties;
    }

    return NULL;
  }

  /**
   * Example 3: Find all fields in a bundle that are media references.
   *
   * @code
   * $entity_type_manager = \Drupal::entityTypeManager();
   *
   * // Load all field configs for a bundle
   * $field_configs = $entity_type_manager
   *   ->getStorage('field_config')
   *   ->loadByProperties([
   *     'entity_type' => 'node',
   *     'bundle' => 'article',
   *   ]);
   *
   * foreach ($field_configs as $field_config) {
   *   // Filter for media references only
   *   if ($field_config->getType() === 'entity_reference') {
   *     $settings = $field_config->get('settings');
   *     if ($settings['target_type'] === 'media') {
   *       // This is a media reference field!
   *       echo "Media field: " . $field_config->get('label');
   *     }
   *   }
   * }
   * @endcode
   */
  public static function example3() {
    $entity_type_manager = \Drupal::entityTypeManager();

    $field_configs = $entity_type_manager
      ->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'node',
        'bundle' => 'article',
      ]);

    $media_fields = [];

    foreach ($field_configs as $field_config) {
      if ($field_config->getType() === 'entity_reference') {
        $settings = $field_config->get('settings');
        if ($settings['target_type'] === 'media') {
          $media_fields[$field_config->getName()] = $field_config;
        }
      }
    }

    return $media_fields;
  }

  /**
   * Example 4: Get field cardinality (how many values can be stored).
   *
   * @code
   * $entity_type_manager = \Drupal::entityTypeManager();
   *
   * // Load field storage config
   * $field_storage = $entity_type_manager
   *   ->getStorage('field_storage_config')
   *   ->load('node.field_gallery_images');
   *
   * if ($field_storage) {
   *   $cardinality = $field_storage->get('cardinality');
   *   // Returns:
   *   // -1 = unlimited
   *   // 0 = field doesn't exist / disabled
   *   // 1 = single value
   *   // 2+ = multiple values (specific number)
   *
   *   if ($cardinality === -1) {
   *     echo "This field can hold unlimited media";
   *   } elseif ($cardinality === 1) {
   *     echo "This field holds exactly one media";
   *   } else {
   *     echo "This field holds up to " . $cardinality . " media";
   *   }
   * }
   * @endcode
   */
  public static function example4() {
    $entity_type_manager = \Drupal::entityTypeManager();

    $field_storage = $entity_type_manager
      ->getStorage('field_storage_config')
      ->load('node.field_gallery_images');

    if ($field_storage) {
      $cardinality = $field_storage->get('cardinality');
      return $cardinality;
    }

    return NULL;
  }

  /**
   * Example 5: Build form options from field handler settings.
   *
   * @code
   * // For media reference fields, you can get handler-specific options
   * $field_config = $entity_type_manager
   *   ->getStorage('field_config')
   *   ->load('node.article.field_media');
   *
   * $settings = $field_config->get('settings');
   * $handler = $settings['handler']; // e.g., "default:media"
   * $handler_settings = $settings['handler_settings']; // Bundle restrictions
   *
   * // Example: Get allowed media bundles
   * if ($handler === 'default:media') {
   *   $media_bundles = $handler_settings['target_bundles'] ?? [];
   *   // Returns: ['image', 'video', 'document']
   * }
   * @endcode
   */
  public static function example5() {
    $entity_type_manager = \Drupal::entityTypeManager();

    $field_config = $entity_type_manager
      ->getStorage('field_config')
      ->load('node.article.field_media');

    if ($field_config) {
      $settings = $field_config->get('settings');
      $handler_settings = $settings['handler_settings'] ?? [];

      return $handler_settings;
    }

    return NULL;
  }

  /**
   * Example 6: Create form elements dynamically based on field type.
   *
   * @code
   * $entity_type_manager = \Drupal::entityTypeManager();
   * $field_config = $entity_type_manager
   *   ->getStorage('field_config')
   *   ->load('media.field_caption');
   *
   * $field_type = $field_config->getType();
   * $label = $field_config->get('label');
   * $required = $field_config->get('required');
   *
   * // Build different form elements based on type
   * switch ($field_type) {
   *   case 'string':
   *     $widget = [
   *       '#type' => 'textfield',
   *       '#title' => $label,
   *       '#required' => $required,
   *     ];
   *     break;
   *
   *   case 'text_long':
   *     $widget = [
   *       '#type' => 'textarea',
   *       '#title' => $label,
   *       '#required' => $required,
   *     ];
   *     break;
   *
   *   case 'integer':
   *     $widget = [
   *       '#type' => 'number',
   *       '#title' => $label,
   *       '#required' => $required,
   *     ];
   *     break;
   *
   *   case 'entity_reference':
   *     // Load options for dropdown
   *     $settings = $field_config->get('settings');
   *     $target_type = $settings['target_type'];
   *     // ... build entity reference widget
   *     break;
   * }
   *
   * return $widget;
   * @endcode
   */
  public static function example6() {
    // See implementation in AddMediaToNodeAction::buildFieldWidget()
  }

  /**
   * Example 7: Get all editable fields of a media type.
   *
   * @code
   * $entity_type_manager = \Drupal::entityTypeManager();
   *
   * // Get all fields for media.image bundle
   * $field_configs = $entity_type_manager
   *   ->getStorage('field_config')
   *   ->loadByProperties([
   *     'entity_type' => 'media',
   *     'bundle' => 'image',
   *   ]);
   *
   * $editable_fields = [];
   *
   * foreach ($field_configs as $field_config) {
   *   // Exclude hidden fields
   *   if (!$field_config->get('hidden')) {
   *     $editable_fields[$field_config->getName()] = [
   *       'label' => $field_config->get('label'),
   *       'type' => $field_config->getType(),
   *       'required' => $field_config->get('required'),
   *       'description' => $field_config->get('description'),
   *     ];
   *   }
   * }
   *
   * return $editable_fields;
   * @endcode
   */
  public static function example7() {
    $entity_type_manager = \Drupal::entityTypeManager();

    $field_configs = $entity_type_manager
      ->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'media',
        'bundle' => 'image',
      ]);

    $editable_fields = [];

    foreach ($field_configs as $field_config) {
      if (!$field_config->get('hidden')) {
        $editable_fields[$field_config->getName()] = [
          'label' => $field_config->get('label'),
          'type' => $field_config->getType(),
          'required' => $field_config->get('required'),
          'description' => $field_config->get('description'),
        ];
      }
    }

    return $editable_fields;
  }

  /**
   * Example 8: Complete VBO action snippet.
   *
   * Shows how to implement execute() method in your action.
   *
   * @code
   * // In your Action Plugin class
   * public function execute($media = NULL) {
   *   if (!$media) {
   *     return;
   *   }
   *
   *   // Get configuration from buildConfigurationForm()
   *   $node_id = $this->configuration['node_id'];
   *   $field_values = $this->configuration['field_values'] ?? [];
   *
   *   // Load the target node
   *   $node = $this->entityTypeManager
   *     ->getStorage('node')
   *     ->load($node_id);
   *
   *   if (!$node) {
   *     return;
   *   }
   *
   *   // Add media to node
   *   $bundle = $node->bundle();
   *   $field_configs = $this->entityTypeManager
   *     ->getStorage('field_config')
   *     ->loadByProperties([
   *       'entity_type' => 'node',
   *       'bundle' => $bundle,
   *     ]);
   *
   *   foreach ($field_configs as $field_config) {
   *     if ($field_config->getType() === 'entity_reference') {
   *       $settings = $field_config->get('settings');
   *       if ($settings['target_type'] === 'media') {
   *         $field_name = $field_config->getName();
   *         if ($node->hasField($field_name)) {
   *           // Add media
   *           $values = $node->get($field_name)->getValue();
   *           $values[] = ['target_id' => $media->id()];
   *           $node->set($field_name, $values);
   *         }
   *       }
   *     }
   *   }
   *
   *   // Apply field values to media
   *   foreach ($field_values as $field_name => $value) {
   *     if (!empty($value) && $media->hasField($field_name)) {
   *       $media->set($field_name, $value);
   *     }
   *   }
   *
   *   // Save both entities
   *   $media->save();
   *   $node->save();
   * }
   * @endcode
   */
  public static function example8() {
    // See implementation in AddMediaToNodeAction::execute()
  }

  /**
   * Example 9: Using the FieldConfigHelper utility.
   *
   * @code
   * $helper = new FieldConfigHelper($entity_type_manager);
   *
   * // Get media fields for a node bundle
   * $media_fields = $helper->getMediaFieldsOfBundle('article');
   *
   * // Get all properties of a field
   * $field_config = $media_fields['field_gallery'];
   * $all_props = $helper->getAllFieldProperties($field_config);
   *
   * // Get target type for entity reference
   * $target_type = $helper->getTargetType($field_config); // "media"
   *
   * // Get cardinality
   * $field_storage = $helper->getFieldStorage('media', 'field_caption');
   * $cardinality = $helper->getFieldCardinality($field_storage);
   *
   * // Get allowed values for select field
   * $options = $helper->getSelectFieldOptions($field_storage);
   *
   * // Add media to node
   * $media = $this->mediaStorage->load(123);
   * $node = $this->nodeStorage->load(456);
   * $helper->addMediaToNode($media, $node, 'field_gallery');
   *
   * // Apply field values to media
   * $values = [
   *   'field_caption' => 'My caption',
   *   'field_alt_text' => 'Alt text',
   * ];
   * $helper->applyFieldValuesToMedia($media, $values);
   * @endcode
   */
  public static function example9() {
    // See implementation in FieldConfigHelper class.
  }

}
