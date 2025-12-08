<?php

namespace Drupal\media_drop\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\media_drop\Traits\MediaFieldFilterTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A custom field that displays filtered media information.
 *
 * This field shows all filterable custom fields for each media dynamically,
 * adapting to the media type and displaying appropriate values.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("media_drop_media_info")
 */
class MediaInfoField extends FieldPluginBase {

  use MediaFieldFilterTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a MediaInfoField object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * Get the entity type manager.
   */
  protected function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function usesGroupBy() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do nothing -- to override the parent query.
    // This field doesn't add to the query.
    $this->addAdditionalFields(['mid', 'bundle']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Get the media entity from the result row.
    $media_id = $values->{$this->aliases['mid']};
    $bundle = $values->{$this->aliases['bundle']};

    if (!$media_id || !$bundle) {
      return '';
    }

    // Load the media entity.
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);

    if (!$media) {
      return '';
    }

    // Get filterable fields for this media's bundle.
    $filterable_fields = $this->getFilterableCustomFields($bundle);

    if (empty($filterable_fields)) {
      return '';
    }

    // Build the output with field values.
    $output = [];

    foreach ($filterable_fields as $field_name => $field_definition) {
      if (!$media->hasField($field_name)) {
        continue;
      }

      $field = $media->get($field_name);

      if ($field->isEmpty()) {
        continue;
      }

      $field_label = $field_definition->getLabel();
      $field_type = $field_definition->getType();
      $value = $this->getFieldValue($media, $field_name, $field_type);

      if ($value) {
        $output[] = [
          'label' => $field_label,
          'value' => $value,
        ];
      }
    }

    if (empty($output)) {
      return '';
    }

    // Format the output.
    return $this->formatOutput($output);
  }

  /**
   * Format the output for display.
   *
   * @param array $output
   *   Array of field data with 'label' and 'value' keys.
   *
   * @return array
   *   Render array with markup.
   */
  protected function formatOutput(array $output) {
    $html = '<dl class="media-info-list">';

    foreach ($output as $item) {
      $label = is_string($item['label']) ? $item['label'] : (string) $item['label'];
      $value = is_string($item['value']) ? $item['value'] : (string) $item['value'];

      $html .= '<dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</dt>';
      $html .= '<dd>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</dd>';
    }

    $html .= '</dl>';

    return [
      '#markup' => $html,
    ];
  }

  /**
   * Get the display value for a field.
   *
   * @param object $media
   *   The media entity.
   * @param string $field_name
   *   The field machine name.
   * @param string $field_type
   *   The field type.
   *
   * @return string|null
   *   The formatted field value, or NULL if empty.
   */
  protected function getFieldValue($media, $field_name, $field_type) {
    $field = $media->get($field_name);

    if ($field->isEmpty()) {
      return NULL;
    }

    switch ($field_type) {
      case 'string':
      case 'string_long':
        $value = $field->value;
        return $value ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : NULL;

      case 'text':
      case 'text_long':
      case 'text_with_summary':
        $value = $field->value;
        if ($value) {
          // Truncate long text.
          return htmlspecialchars(
            mb_strlen($value) > 100 ? mb_substr($value, 0, 100) . '...' : $value,
            ENT_QUOTES,
            'UTF-8'
          );
        }
        return NULL;

      case 'boolean':
        return $field->value ? $this->t('Yes') : $this->t('No');

      case 'entity_reference':
        // Get the referenced entity and display its label.
        $entity = $field->entity;
        return $entity ? $entity->label() : NULL;

      case 'timestamp':
        $timestamp = $field->value;
        if ($timestamp) {
          return \Drupal::service('date.formatter')
            ->format($timestamp, 'short');
        }
        return NULL;

      case 'decimal':
      case 'integer':
        $value = $field->value;
        return $value !== NULL ? (string) $value : NULL;

      default:
        // Fallback: try to get a string representation.
        try {
          return $field->getString();
        }
        catch (\Exception $e) {
          return NULL;
        }
    }
  }

}
