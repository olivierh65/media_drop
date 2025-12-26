<?php

namespace Drupal\media_drop\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds selected media to a node with optional field values.
 *
 * @Action(
 *   id = "media_drop_add_to_node",
 *   label = @Translation("Add to node with field values"),
 *   type = "media",
 *   category = @Translation("Media Drop"),
 *   confirm = TRUE
 * )
 */
class AddMediaToNodeAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an AddMediaToNodeAction object.
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
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'node_id' => NULL,
      'directory_tid' => NULL,
      'field_values' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['step'] = [
      '#type' => 'hidden',
      '#value' => $form_state->get('step') ?? 1,
    ];

    $step = $form_state->get('step') ?? 1;

    if ($step === 1) {
      $form = $this->buildStepOne($form, $form_state);
    }
    elseif ($step === 2) {
      $form = $this->buildStepTwo($form, $form_state);
    }

    return $form;
  }

  /**
   * Build step 1: Select node and directory.
   */
  private function buildStepOne(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Select a node that has media reference fields and optionally a destination directory.') . '</p>',
    ];

    // Load nodes with media fields.
    $nodes = $this->getNodesWithMediaFields();
    $node_options = ['' => $this->t('- Select a node -')];
    foreach ($nodes as $node) {
      $node_options[$node->id()] = $node->getTitle() . ' (' . $node->bundle() . ')';
    }

    $form['node_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Select node'),
      '#options' => $node_options,
      '#required' => TRUE,
      '#default_value' => $this->configuration['node_id'] ?? '',
    ];

    // Optional: directory selection.
    if (\Drupal::moduleHandler()->moduleExists('media_directories')) {
      $config = \Drupal::config('media_directories.settings');
      $vocabulary_id = $config->get('directory_taxonomy');

      if ($vocabulary_id) {
        $terms = $this->entityTypeManager
          ->getStorage('taxonomy_term')
          ->loadTree($vocabulary_id, 0, NULL, TRUE);

        $options = [0 => $this->t('- No directory change -')];
        foreach ($terms as $term) {
          $prefix = str_repeat('--', $term->depth);
          $options[$term->id()] = $prefix . ' ' . $term->getName();
        }

        $form['directory_tid'] = [
          '#type' => 'select',
          '#title' => $this->t('Move media to directory (optional)'),
          '#options' => $options,
          '#default_value' => $this->configuration['directory_tid'] ?? 0,
        ];
      }
    }

    $form['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#submit' => ['::submitStepOne'],
    ];

    return $form;
  }

  /**
   * Build step 2: Configure field values.
   */
  private function buildStepTwo(array $form, FormStateInterface $form_state) {
    $node_id = $this->configuration['node_id'];

    if (!$node_id) {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' . $this->t('No node selected.') . '</div>',
      ];
      return $form;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($node_id);

    if (!$node) {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' . $this->t('Node not found.') . '</div>',
      ];
      return $form;
    }

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure field values to apply to all selected media.') . '</p>',
    ];

    // Get editable fields.
    $editable_fields = $this->getEditableMediaFields($node);

    if (empty($editable_fields)) {
      $form['info'] = [
        '#markup' => '<div class="messages messages--status">' .
        $this->t('No editable media fields found in this node.') .
        '</div>',
      ];
    }
    else {
      $form['field_values'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Media field values'),
        '#tree' => TRUE,
      ];

      foreach ($editable_fields as $field_name => $field_config) {
        $field_widget = $this->buildFieldWidget($field_name, $field_config, $node);
        if ($field_widget) {
          $form['field_values'][$field_name] = $field_widget;
        }
      }
    }

    $form['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::submitStepBack'],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * Get nodes that have media reference fields.
   *
   * @return array
   *   Array of node entities.
   */
  private function getNodesWithMediaFields() {
    $nodes = [];

    // Get all node types.
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($node_types as $node_type) {
      $bundle = $node_type->id();

      // Get field definitions for this bundle.
      $fields = $this->entityTypeManager
        ->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'node',
          'bundle' => $bundle,
        ]);

      // Check if any field references media.
      foreach ($fields as $field) {
        if ($field->get('field_type') === 'entity_reference') {
          $settings = $field->get('settings');
          if ($settings['target_type'] === 'media') {
            // This bundle has media fields, load some nodes.
            $node_storage = $this->entityTypeManager->getStorage('node');
            $query = $node_storage->getQuery();
            $query->condition('type', $bundle)
              ->accessCheck(TRUE)
              ->range(0, 50);
            $nids = $query->execute();

            if (!empty($nids)) {
              $nodes_batch = $node_storage->loadMultiple($nids);
              $nodes += $nodes_batch;
            }
            break;
          }
        }
      }
    }

    return $nodes;
  }

  /**
   * Get editable media fields from a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   Array of field configurations keyed by field name.
   */
  private function getEditableMediaFields(NodeInterface $node) {
    $editable_fields = [];
    $bundle = $node->bundle();

    // Load field configs for this bundle.
    $field_configs = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'node',
        'bundle' => $bundle,
      ]);

    foreach ($field_configs as $field_config) {
      // Only get media reference fields.
      if ($field_config->get('field_type') === 'entity_reference') {
        $settings = $field_config->get('settings');
        if ($settings['target_type'] === 'media') {
          // Check if node has this field and it's not empty.
          if ($node->hasField($field_config->getName())) {
            $editable_fields[$field_config->getName()] = $field_config;
          }
        }
      }
    }

    return $editable_fields;
  }

  /**
   * Build a form widget for a specific field.
   *
   * @param string $field_name
   *   The field name.
   * @param object $field_config
   *   The field config entity.
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   Form element array, or NULL if widget cannot be built.
   */
  private function buildFieldWidget($field_name, $field_config, NodeInterface $node) {
    // Get the label from field_config.
    $label = $field_config->get('label');
    $description = $field_config->get('description');

    // Get field storage config to access settings.
    $field_storage = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->load('media.' . $field_name);

    if (!$field_storage) {
      return NULL;
    }

    $field_type = $field_storage->get('type');

    // Build widget based on field type.
    $widget = [
      '#type' => 'textfield',
      '#title' => $label,
      '#description' => $description,
      '#default_value' => '',
    ];

    // Example: Handle different field types.
    switch ($field_type) {
      case 'string':
      case 'string_long':
        $widget['#type'] = 'textfield';
        break;

      case 'text':
      case 'text_long':
      case 'text_with_summary':
        $widget['#type'] = 'textarea';
        break;

      case 'integer':
      case 'decimal':
      case 'float':
        $widget['#type'] = 'number';
        break;

      case 'boolean':
        $widget['#type'] = 'checkbox';
        break;

      case 'entity_reference':
        // Handle entity references (taxonomy terms, etc).
        $widget = $this->buildEntityReferenceWidget($field_config, $label, $description);
        break;

      case 'list_string':
      case 'list_integer':
        $widget = $this->buildSelectWidget($field_storage, $label, $description);
        break;

      default:
        return NULL;
    }

    return $widget;
  }

  /**
   * Build widget for entity reference field.
   */
  private function buildEntityReferenceWidget($field_config, $label, $description) {
    $settings = $field_config->get('settings');
    $target_type = $settings['target_type'] ?? NULL;

    if (!$target_type) {
      return NULL;
    }

    // Load entities for autocomplete.
    $storage = $this->entityTypeManager->getStorage($target_type);
    $entities = $storage->loadMultiple();

    $options = ['' => $this->t('- None -')];
    foreach ($entities as $entity) {
      $options[$entity->id()] = $entity->label();
    }

    return [
      '#type' => 'select',
      '#title' => $label,
      '#description' => $description,
      '#options' => $options,
      '#default_value' => '',
    ];
  }

  /**
   * Build widget for select (list) field.
   */
  private function buildSelectWidget($field_storage, $label, $description) {
    $settings = $field_storage->get('settings');
    $allowed_values = $settings['allowed_values'] ?? [];

    $options = ['' => $this->t('- None -')];
    if (!empty($allowed_values)) {
      foreach ($allowed_values as $key => $value) {
        $options[$key] = $value['label'] ?? $key;
      }
    }

    return [
      '#type' => 'select',
      '#title' => $label,
      '#description' => $description,
      '#options' => $options,
      '#default_value' => '',
    ];
  }

  /**
   * Submit handler for step one.
   */
  public function submitStepOne(array &$form, FormStateInterface $form_state) {
    $this->configuration['node_id'] = $form_state->getValue('node_id');
    $this->configuration['directory_tid'] = $form_state->getValue('directory_tid');

    $form_state->set('step', 2);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for back button.
   */
  public function submitStepBack(array &$form, FormStateInterface $form_state) {
    $form_state->set('step', 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['field_values'] = $form_state->getValue('field_values') ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function execute($media = NULL) {
    if (!$media) {
      return;
    }

    $node_id = $this->configuration['node_id'];
    $directory_tid = $this->configuration['directory_tid'] ?? NULL;
    $field_values = $this->configuration['field_values'] ?? [];

    // Load the node.
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node) {
      return;
    }

    // Step 1: Move media to directory if specified.
    if ($directory_tid && $this->moduleHandler()->moduleExists('media_directories')) {
      $this->moveMediaToDirectory($media, $directory_tid);
    }

    // Step 2: Add media to node.
    $this->addMediaToNode($media, $node);

    // Step 3: Apply field values to media.
    if (!empty($field_values)) {
      $this->applyFieldValues($media, $field_values);
    }
  }

  /**
   * Move media to directory.
   */
  private function moveMediaToDirectory($media, $directory_tid) {
    if ($media->hasField('media_directory')) {
      $media->set('media_directory', ['target_id' => $directory_tid]);
      $media->save();
    }
  }

  /**
   * Add media to node media reference field.
   */
  private function addMediaToNode($media, NodeInterface $node) {
    $bundle = $node->bundle();

    // Get media reference fields.
    $field_configs = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'node',
        'bundle' => $bundle,
      ]);

    foreach ($field_configs as $field_config) {
      if ($field_config->get('field_type') === 'entity_reference') {
        $settings = $field_config->get('settings');
        if ($settings['target_type'] === 'media') {
          $field_name = $field_config->getName();
          if ($node->hasField($field_name)) {
            // Add media to this field.
            $current_values = $node->get($field_name)->getValue();
            $current_values[] = ['target_id' => $media->id()];
            $node->set($field_name, $current_values);
          }
        }
      }
    }

    $node->save();
  }

  /**
   * Apply field values to media.
   */
  private function applyFieldValues($media, array $field_values) {
    foreach ($field_values as $field_name => $value) {
      if (!empty($value) && $media->hasField($field_name)) {
        $media->set($field_name, $value);
      }
    }

    $media->save();
  }

  /**
   * Access control.
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $access = $account->hasPermission('administer media');
    return $return_as_object ? $this->accessResult($access) : $access;
  }

}
