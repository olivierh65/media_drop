<?php

namespace Drupal\media_drop\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media_drop\Traits\MediaFieldFilterTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk edit media with common field values grouping.
 *
 * @Action(
 *   id = "media_drop_bulk_edit",
 *   label = @Translation("Edit media (grouped)"),
 *   type = "media",
 *   category = @Translation("Media Drop"),
 *   confirm = TRUE
 * )
 */
class BulkEditMediaAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  use MediaFieldFilterTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Media entities to edit.
   *
   * @var array
   */
  protected $mediaEntities = [];

  /**
   * Constructs a BulkEditMediaAction object.
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
  public function defaultConfiguration() {
    return [
      'field_values' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // =========================================================================
    // STEP 1: Retrieve selected media IDs from VBO tempstore
    // =========================================================================
    $build_info = $form_state->getBuildInfo();
    $view_id = $build_info['args'][0];
    $display_id = $build_info['args'][1];

    $temp_store_name = "views_bulk_operations_{$view_id}_{$display_id}";
    $user_id = (string) \Drupal::currentUser()->id();

    $view_tempstore = \Drupal::service('tempstore.private')->get($temp_store_name);
    $temp_store_data = $view_tempstore->get($user_id);

    if ($temp_store_data && isset($temp_store_data['list'])) {
      $media_ids = array_map(function ($item) {
        return is_array($item) ? $item[0] : $item;
      }, $temp_store_data['list']);

      if (!empty($media_ids)) {
        $this->mediaEntities = \Drupal::entityTypeManager()
          ->getStorage('media')
          ->loadMultiple($media_ids);
      }
    }

    // =========================================================================
    // STEP 2: Build the configuration form
    // =========================================================================
    $common_fields = $this->analyzeCommonFields();

    $form['info'] = [
      '#markup' => '<div class="messages messages--status">' .
      $this->t('The fields below show values common to selected media. Media with the same values are grouped together.') .
      '</div>',
    ];

    $form['summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Selection summary (@count media)', ['@count' => count($this->mediaEntities)]),
      '#open' => TRUE,
    ];

    $form['summary']['list'] = [
      '#markup' => $this->buildSummaryList($common_fields),
    ];

    $form['fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Modify fields'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    if (!empty($this->mediaEntities)) {
      $first_media = reset($this->mediaEntities);
      $bundle = $first_media->bundle();

      // Use the trait method to get filterable fields.
      $field_definitions = $this->getFilterableCustomFields($bundle);

      foreach ($field_definitions as $field_name => $field_definition) {
        $field_label = $field_definition->getLabel();

        $common_value = $this->getCommonFieldValue($field_name);
        $value_counts = $this->getFieldValueCounts($field_name);

        $form['fields'][$field_name] = [
          '#type' => 'details',
          '#title' => $field_label,
          '#open' => FALSE,
        ];

        $form['fields'][$field_name]['info'] = [
          '#markup' => $this->buildFieldValueSummary($value_counts),
        ];

        $form['fields'][$field_name]['actions'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['field-actions']],
        ];

        $form['fields'][$field_name]['actions']['modify'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Modify this field'),
          '#default_value' => FALSE,
        ];

        $form['fields'][$field_name]['actions']['clear'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Clear this field'),
          '#default_value' => FALSE,
        ];

        $form['fields'][$field_name]['value'] = $this->buildFieldWidget($field_definition, $common_value);

        $form['fields'][$field_name]['value']['#states'] = [
          'visible' => [
            ':input[name="fields[' . $field_name . '][actions][modify]"]' => ['checked' => TRUE],
            ':input[name="fields[' . $field_name . '][actions][clear]"]' => ['checked' => FALSE],
          ],
          'required' => [
            ':input[name="fields[' . $field_name . '][actions][modify]"]' => ['checked' => TRUE],
          ],
        ];
      }
    }

    return $form;
  }

  /**
   * Analyze common fields among media.
   */
  protected function analyzeCommonFields() {
    $analysis = [];

    if (empty($this->mediaEntities)) {
      return $analysis;
    }

    $by_bundle = [];
    foreach ($this->mediaEntities as $media) {
      $bundle = $media->bundle();
      if (!isset($by_bundle[$bundle])) {
        $by_bundle[$bundle] = [];
      }
      $by_bundle[$bundle][] = $media;
    }

    $analysis['bundles'] = $by_bundle;

    return $analysis;
  }

  /**
   * Build a summary of selected media.
   */
  protected function buildSummaryList($common_fields) {
    $output = '<ul>';

    foreach ($common_fields['bundles'] as $bundle => $medias) {
      $bundle_label = $this->entityTypeManager
        ->getStorage('media_type')
        ->load($bundle)
        ->label();

      $output .= '<li><strong>' . $bundle_label . '</strong> : ' . count($medias) . ' media</li>';
    }

    $output .= '</ul>';

    return $output;
  }

  /**
   * Get common value for a field (or NULL if different).
   */
  protected function getCommonFieldValue($field_name) {
    $values = [];

    foreach ($this->mediaEntities as $media) {
      if ($media->hasField($field_name) && !$media->get($field_name)->isEmpty()) {
        $values[] = serialize($media->get($field_name)->getValue());
      }
    }

    $unique_values = array_unique($values);

    if (count($unique_values) === 1) {
      return unserialize(reset($unique_values));
    }

    return NULL;
  }

  /**
   * Count different values for a field.
   */
  protected function getFieldValueCounts($field_name) {
    $counts = [];

    foreach ($this->mediaEntities as $media) {
      if ($media->hasField($field_name)) {
        if ($media->get($field_name)->isEmpty()) {
          $key = '(empty)';
        }
        else {
          $value = $media->get($field_name)->getString();
          $key = mb_strlen($value) > 50 ? mb_substr($value, 0, 50) . '...' : $value;
        }

        if (!isset($counts[$key])) {
          $counts[$key] = 0;
        }
        $counts[$key]++;
      }
    }

    return $counts;
  }

  /**
   * Build a summary of field values.
   */
  protected function buildFieldValueSummary($value_counts) {
    if (empty($value_counts)) {
      return '<em>' . $this->t('No values') . '</em>';
    }

    if (count($value_counts) === 1) {
      $value = key($value_counts);
      return '<div class="messages messages--status">' .
        $this->t('Common value: <strong>@value</strong> (@count media)', [
          '@value' => $value,
          '@count' => reset($value_counts),
        ]) . '</div>';
    }

    $output = '<div class="messages messages--warning">' .
      $this->t('Multiple values:') . '<ul>';

    foreach ($value_counts as $value => $count) {
      $output .= '<li><strong>' . $value . '</strong> : ' . $count . ' media</li>';
    }

    $output .= '</ul></div>';

    return $output;
  }

  /**
   * Build a form widget for a field.
   */
  protected function buildFieldWidget($field_definition, $default_value) {
    $field_type = $field_definition->getType();
    $field_name = $field_definition->getName();

    switch ($field_type) {
      case 'string':
      case 'string_long':
        return [
          '#type' => 'textfield',
          '#title' => $this->t('New value'),
          '#default_value' => $default_value ? $default_value[0]['value'] : '',
        ];

      case 'text':
      case 'text_long':
      case 'text_with_summary':
        return [
          '#type' => 'textarea',
          '#title' => $this->t('New value'),
          '#default_value' => $default_value ? $default_value[0]['value'] : '',
        ];

      case 'boolean':
        return [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable'),
          '#default_value' => $default_value ? $default_value[0]['value'] : FALSE,
        ];

      case 'entity_reference':
        $settings = $field_definition->getSettings();
        $target_type = $settings['target_type'];

        if ($target_type === 'taxonomy_term') {
          $vocabularies = $settings['handler_settings']['target_bundles'] ?? [];

          return [
            '#type' => 'entity_autocomplete',
            '#title' => $this->t('New value'),
            '#target_type' => $target_type,
            '#selection_settings' => ['target_bundles' => $vocabularies],
            '#default_value' => $default_value && isset($default_value[0]['target_id'])
              ? $this->entityTypeManager->getStorage($target_type)->load($default_value[0]['target_id'])
              : NULL,
          ];
        }
        break;

      default:
        return [
          '#type' => 'textfield',
          '#title' => $this->t('New value'),
          '#description' => $this->t('Field type: @type', ['@type' => $field_type]),
          '#default_value' => '',
        ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['field_values'] = [];
    $this->configuration['clear_fields'] = [];

    $fields = $form_state->getValue('fields');

    foreach ($fields as $field_name => $field_data) {
      $actions = $field_data['actions'] ?? [];

      if (!empty($actions['clear'])) {
        $this->configuration['clear_fields'][] = $field_name;
      }
      elseif (!empty($actions['modify'])) {
        $this->configuration['field_values'][$field_name] = $field_data['value'];
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

    $field_values = $this->configuration['field_values'] ?? [];
    foreach ($field_values as $field_name => $value) {
      if ($entity->hasField($field_name)) {
        $entity->set($field_name, $value);
      }
    }

    $clear_fields = $this->configuration['clear_fields'] ?? [];
    foreach ($clear_fields as $field_name) {
      if ($entity->hasField($field_name)) {
        $entity->set($field_name, NULL);
      }
    }

    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    $this->mediaEntities = $entities;

    $field_values = $this->configuration['field_values'] ?? [];
    $clear_fields = $this->configuration['clear_fields'] ?? [];

    foreach ($entities as $entity) {
      foreach ($field_values as $field_name => $value) {
        if ($entity->hasField($field_name)) {
          $entity->set($field_name, $value);
        }
      }

      foreach ($clear_fields as $field_name) {
        if ($entity->hasField($field_name)) {
          $entity->set($field_name, NULL);
        }
      }

      $entity->save();
    }
  }

  /**
   * Apply modifications to media.
   */
  public function applyChanges() {
    $field_values = $this->configuration['field_values'];

    foreach ($this->mediaEntities as $media) {
      foreach ($field_values as $field_name => $value) {
        if ($media->hasField($field_name)) {
          $media->set($field_name, $value);
        }
      }

      $media->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\media\MediaInterface $object */
    $access = $object->access('update', $account, TRUE);

    return $return_as_object ? $access : $access->isAllowed();
  }

}
