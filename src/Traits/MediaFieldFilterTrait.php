<?php

namespace Drupal\media_drop\Traits;

/**
 * Trait for filtering media fields based on exclusion/inclusion rules.
 *
 * This trait provides common logic for filtering media custom fields,
 * used across BulkEditMediaAction and ManageMediaController.
 *
 * Filtering rules:
 * - Exclude base fields (system fields)
 * - Exclude specific field types (image, file, video_file, audio_file, document)
 * - Exclude fields by name pattern (field_exif_*, field_width, field_height)
 * - Include special fields even if excluded by type (field_media_image_alt_text, etc.)
 */
trait MediaFieldFilterTrait {

  /**
   * Get filterable custom fields for a media bundle.
   *
   * Returns an array of custom fields that should be available for bulk
   * operations or display, based on exclusion/inclusion rules.
   *
   * @param string $bundle
   *   The media bundle (type ID).
   *
   * @return array
   *   Array of field definitions keyed by field name.
   */
  protected function getFilterableCustomFields($bundle) {
    try {
      // Verify that 'media' entity type exists before attempting to load fields.
      $entity_type_manager = $this->getEntityTypeManager();
      $entity_type_manager->getDefinition('media');

      $field_definitions = $entity_type_manager
        ->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'media',
          'bundle' => $bundle,
        ]);

      $filterable_fields = [];

      foreach ($field_definitions as $field_name_id => $field_definition) {
        if ($this->shouldIncludeField($field_definition)) {
          $filterable_fields[$field_definition->getName()] = $field_definition;
        }
      }

      return $filterable_fields;
    }
    catch (\Exception $e) {
      // If there's an error loading field definitions (e.g., bundle doesn't exist),
      // return empty array rather than crashing.
      \Drupal::logger('media_drop')->warning(
        'Error loading filterable fields for bundle @bundle: @message',
        [
          '@bundle' => $bundle,
          '@message' => $e->getMessage(),
        ]
      );
      return [];
    }
  }

  /**
   * Determine if a field should be included in filtered results.
   *
   * @param object $field_definition
   *   The field definition object.
   *
   * @return bool
   *   TRUE if the field should be included, FALSE otherwise.
   */
  protected function shouldIncludeField($field_definition) {
    $field_type = $field_definition->getType();
    $field_name = $field_definition->getName();

    // Check if it's a custom field (not a base field).
    $is_custom = !$field_definition->getFieldStorageDefinition()->isBaseField();

    // Check if it's a special field that should always be included.
    $is_special_included = $this->isSpecialIncludedField($field_name);

    // Check if the field type is excluded.
    $is_excluded_by_type = $this->isExcludedFieldType($field_type);

    // Check if the field name is excluded.
    $is_excluded_by_name = $this->isExcludedFieldName($field_name);

    // Include if:
    // - Custom field or special field
    // - AND NOT (excluded AND not special)
    return ($is_custom || $is_special_included)
      && !(($is_excluded_by_type || $is_excluded_by_name) && !$is_special_included);
  }

  /**
   * Check if a field is in the special inclusion list.
   *
   * @param string $field_name
   *   The field machine name.
   *
   * @return bool
   *   TRUE if the field is special-included, FALSE otherwise.
   */
  protected function isSpecialIncludedField($field_name) {
    $included_special_fields = $this->getSpecialIncludedFields();
    return in_array($field_name, $included_special_fields);
  }

  /**
   * Get the list of special fields that should always be included.
   *
   * Override this method in classes using the trait to customize the list.
   *
   * @return array
   *   Array of special field machine names.
   */
  protected function getSpecialIncludedFields() {
    return [
      'field_media_image_alt_text',
      'field_media_image_title',
      'field_am_photo_description',
      'field_am_photo_author',
      'field_am_video_author',
      'field_video_auteur',
    ];
  }

  /**
   * Check if a field type is in the exclusion list.
   *
   * @param string $field_type
   *   The field type (e.g., 'image', 'file').
   *
   * @return bool
   *   TRUE if the field type is excluded, FALSE otherwise.
   */
  protected function isExcludedFieldType($field_type) {
    $excluded_types = $this->getExcludedFieldTypes();
    return in_array($field_type, $excluded_types);
  }

  /**
   * Get the list of field types to exclude.
   *
   * Override this method in classes using the trait to customize the list.
   *
   * @return array
   *   Array of excluded field types.
   */
  protected function getExcludedFieldTypes() {
    return ['image', 'file', 'video_file', 'audio_file', 'document'];
  }

  /**
   * Check if a field name is in the exclusion list.
   *
   * @param string $field_name
   *   The field machine name.
   *
   * @return bool
   *   TRUE if the field name is excluded, FALSE otherwise.
   */
  protected function isExcludedFieldName($field_name) {
    $excluded_patterns = $this->getExcludedFieldNamePatterns();

    foreach ($excluded_patterns as $pattern) {
      // Check if it's a prefix pattern (ends with underscore).
      if (substr($pattern, -1) === '_') {
        if (strpos($field_name, $pattern) === 0) {
          return TRUE;
        }
      }
      // Otherwise it's an exact match.
      elseif ($field_name === $pattern) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Get the list of field name patterns to exclude.
   *
   * Override this method in classes using the trait to customize the list.
   * Patterns ending with underscore are treated as prefixes (prefix matching).
   * Patterns without underscore are treated as exact matches.
   *
   * @return array
   *   Array of excluded field name patterns.
   */
  protected function getExcludedFieldNamePatterns() {
    return [
    // Prefix: all EXIF fields.
      'field_exif_',
    // Exact: width field.
      'field_width',
    // Exact: height field.
      'field_height',
    ];
  }

  /**
   * Get the entity type manager.
   *
   * This method must be implemented by classes using this trait.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager service.
   */
  abstract protected function getEntityTypeManager();

}
