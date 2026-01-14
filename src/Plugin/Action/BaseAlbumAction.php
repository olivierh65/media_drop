<?php

namespace Drupal\media_drop\Plugin\Action;

use Drupal\views\Views;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\media_taxonomy_service\Service\DirectoryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 *
 */
abstract class BaseAlbumAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {


  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The taxonomy service.
   *
   * @var \Drupal\media_taxonomy_service\Service\DirectoryService
   */
  protected $taxonomyService;

  /**
   * Media entities to add to album.
   *
   * @var array
   */
  protected $mediaEntities = [];

  /**
   * The selected album node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $albumNode;

  /**
   * Selected media from VBO action.
   *
   * @var array
   */
  protected $selectedMedia = [];

  /**
   * Cache for used directories in album.
   *
   * @var array
   */
  protected $usedDirectoriesCache = [];


  /**
   * Is media must be moved to another directory.
   *
   * @var bool
   */
  protected $move;

  /**
   * Constructs an AddMediaToalbumAction object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, DirectoryService $taxonomy_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->taxonomyService = $taxonomy_service;
    $this->selectedMedia = [];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
    $configuration,
    $plugin_id,
    $plugin_definition,
    $container->get('entity_type.manager'),
    $container->get('media_drop.taxonomy_service')
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
      'album_id' => NULL,
      'directory_tid' => NULL,
      'album_field_values' => [],
      'order' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Retrieve selected media IDs from VBO tempstore.
    $build_info = $form_state->getBuildInfo();
    $view_id = $build_info['args'][0];
    $display_id = $build_info['args'][1];

    $temp_store_name = "views_bulk_operations_{$view_id}_{$display_id}";
    $user_id = (string) \Drupal::currentUser()->id();

    $view_tempstore = \Drupal::service('tempstore.private')->get($temp_store_name);
    $temp_store_data = $view_tempstore->get($user_id);

    $exclude_mode = !empty($temp_store_data['exclude_mode']);
    if (!$exclude_mode) {
      if ($temp_store_data && isset($temp_store_data['list'])) {
        $media_ids = array_map(function ($item) {
          return is_array($item) ? $item[0] : $item;
        }, $temp_store_data['list']);

        if (!empty($media_ids)) {
          // Load the LATEST revision of each media to get current field values.
          $media_storage = \Drupal::entityTypeManager()->getStorage('media');
          $this->mediaEntities = [];
          foreach ($media_ids as $media_id) {
            $latest_revision_id = $media_storage->getLatestRevisionId($media_id);
            if ($latest_revision_id) {
              $this->mediaEntities[$media_id] = $media_storage->loadRevision($latest_revision_id);
            }
          }
        }
      }
    }
    else {
      // Case of exclude mode, media list must be get from the view.
      // Pay attention to have batch: true in VBO settings.
      /** @var \Drupal\views\ViewExecutable $view */
      $view = Views::getView($view_id);
      $view->setDisplay($display_id);

      // ⚠️ important : appliquer les exposed filters
      if (!empty($temp_store_data['exposed_input'])) {
        $view->setExposedInput($temp_store_data['exposed_input']);
      }

      $view->execute();

      $media_storage = \Drupal::entityTypeManager()->getStorage('media');
      $this->mediaEntities = [];

      foreach ($view->result as $row) {
        if (!empty($row->_entity)) {
          $media_id = $row->_entity->id();
          $latest_revision_id = $media_storage->getLatestRevisionId($media_id);
          if ($latest_revision_id) {
            $this->mediaEntities[$media_id] = $media_storage->loadRevision($latest_revision_id);
          }
        }
      }
    }

    $form['#tree'] = TRUE;

    // Step 1: Select an Album and Directory.
    $form['step_1'] = [
      '#type' => 'details',
      '#title' => $this->t('Step 1: Select an Album and Directory'),
      '#open' => TRUE,
    ];

    $form['step_1']['info'] = [
      '#markup' => '<div class="messages messages--status">' .
      $this->t('Selected media: <strong>@count</strong>', ['@count' => count($this->mediaEntities)]) .
      '</div>',
    ];

    // Album selection.
    $album_bundles = [];
    $album_options = $this->getAvailableAlbums($album_bundles);

    $form['step_1']['album_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Album'),
      '#description' => $this->t('Select an existing album (node) with media fields to add the selected media to.'),
      '#options' => $album_options,
      '#required' => TRUE,
      '#default_value' => $this->configuration['album_id'] ?? '',
      '#empty_option' => $this->t('- Select an album -'),
      '#ajax' => [
        'callback' => [$this, 'ajaxUpdateAlbumFields'],
        'wrapper' => 'album-fields-wrapper',
        'event' => 'change',
      ],
    ];

    $form['step_1']['order'] = [
      '#type' => 'hidden',
      '#value' => [1, 2, 3],
      '#attributes' => [
        'class' => ['media-drop-order-step1'],
      ],
    ];

    // Wrapper for AJAX updates.
    $form['step_2_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'album-fields-wrapper'],
    ];

    $form['step_2_wrapper']['order'] = [
      '#type' => 'hidden',
      '#value' => [11, 22, 33],
      '#attributes' => [
        'class' => ['media-drop-order'],
      ],
    ];

    // Directory selection has been moved to Step 2.
    // Step 2: Configure Album Fields.
    if ($form_state->getValue(['step_1', 'album_id'])) {
      $album_id = $form_state->getValue(['step_1', 'album_id']);
      $this->albumNode = $this->entityTypeManager->getStorage('node')->load($album_id);

      if ($this->albumNode) {
        // Build step_2 inside wrapper.
        $form['step_2_wrapper']['step_2'] = $this->buildAlbumConfigurationForm([]);
      }
    }
    else {
      // Return hidden step_2 if no album selected.
      $form['step_2_wrapper']['step_2'] = [
        '#type' => 'details',
        '#title' => $this->t('Step 2: Configure Album Fields'),
        '#open' => TRUE,
        '#tree' => TRUE,
        '#access' => FALSE,
      ];
    }

    // Attach autocomplete libraries to the main form.
    $form['#attached'] = [
      'library' => [
        'core/drupal.autocomplete',
        'core/drupal.form-states',
      ],
    ];

    // Add states to disable submit button until an album is selected.
    // Submit button is enabled only when album_id is not empty.
    $form['actions']['submit']['#states'] = [
      'disabled' => [
        ':input[name="step_1[album_id]"]' => ['value' => ''],
      ],
    ];

    return $form;
  }

  /**
   * Get available albums for select widget.
   *
   * @param array $bundles
   *   Array of node bundle machine names (unused, kept for signature).
   *
   * @return array
   *   Array of node IDs keyed by node title.
   */
  protected function getAvailableAlbums(array $bundles) {
    $options = [];

    try {
      // Get media bundles from selected media entities.
      $media_bundles = $this->getSelectedMediaBundles();

      if (empty($media_bundles)) {
        return $options;
      }

      // Get compatible node bundles for these media bundles.
      $node_bundles = $this->getNodeBundlesForMedia($media_bundles);

      if (empty($node_bundles)) {
        return $options;
      }

      // Load all nodes (published and unpublished) from compatible bundles.
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', array_keys($node_bundles), 'IN')
        ->sort('title', 'ASC')
        ->accessCheck(FALSE);

      $nids = $query->execute();

      if (empty($nids)) {
        return $options;
      }

      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

      foreach ($nodes as $node) {
        $status = $node->isPublished() ? '' : ' ' . $this->t('[Draft]');
        $options[$node->id()] = $node->getTitle() . $status;
      }
    }
    catch (\Exception $e) {
      // Log error but don't crash.
      \Drupal::logger('media_drop')->warning(
      'Error loading album options: @message',
      [
        '@message' => $e->getMessage(),
      ]
      );
    }

    return $options;
  }

  /**
   * Get the bundles of selected media entities.
   *
   * @return array
   *   Array of media bundle names.
   */
  protected function getSelectedMediaBundles() {
    $bundles = [];

    foreach ($this->mediaEntities as $media) {
      $bundle = $media->bundle();
      if (!in_array($bundle, $bundles)) {
        $bundles[] = $bundle;
      }
    }

    return $bundles;
  }

  /**
   * Get node bundles that have media reference fields compatible with media bundles.
   *
   * @param array $media_bundles
   *   Array of media bundle names.
   *
   * @return array
   *   Array of node bundle machine names with their media field names.
   *   Structure: ['bundle_name' => ['field_name1', 'field_name2'], ...]
   */
  protected function getNodeBundlesForMedia(array $media_bundles) {
    $node_bundles = [];

    try {
      // Load all field configurations.
      $fields = FieldConfig::loadMultiple();

      foreach ($fields as $field) {
        // Only check fields on node entities.
        if ($field->getTargetEntityTypeId() !== 'node') {
          continue;
        }

        // Only check entity_reference fields targeting media.
        if (
        $field->getType() !== 'entity_reference' ||
        $field->getSetting('target_type') !== 'media'
        ) {
          continue;
        }

        $handler_settings = $field->getSetting('handler_settings') ?? [];
        $target_bundles = $handler_settings['target_bundles'] ?? [];

        // If no bundles are restricted, all media bundles are compatible.
        if (empty($target_bundles)) {
          $bundle = $field->getTargetBundle();
          if (!isset($node_bundles[$bundle])) {
            $node_bundles[$bundle] = [];
          }
          $node_bundles[$bundle][] = $field->getName();
          continue;
        }

        // Check if any of the selected media bundles match the restricted bundles.
        if (array_intersect($media_bundles, array_keys($target_bundles))) {
          $bundle = $field->getTargetBundle();
          if (!isset($node_bundles[$bundle])) {
            $node_bundles[$bundle] = [];
          }
          $node_bundles[$bundle][] = $field->getName();
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')->warning(
      'Error getting node bundles for media: @message',
      [
        '@message' => $e->getMessage(),
      ]
      );
    }

    return $node_bundles;
  }

  /**
   * Get media entities that are incompatible with the selected album.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node.
   *
   * @return array
   *   Array of incompatible media entities.
   */
  protected function getIncompatibleMedia($node) {
    $incompatible = [];

    if (empty($this->mediaEntities)) {
      return $incompatible;
    }

    // Get all media reference fields on the album node.
    try {
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'node')
        ->condition('bundle', $node->bundle());

      $field_ids = $query->execute();
      $field_configs = [];

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);
      }

      $compatible_bundles = [];
      foreach ($field_configs as $field_config) {
        if ($field_config->get('field_type') === 'entity_reference') {
          if ($field_config->getSetting('target_type') === 'media') {
            $target_bundles = $field_config->getSetting('handler_settings')['target_bundles'] ?? [];
            // If no bundles are restricted, all are compatible.
            if (empty($target_bundles)) {
              return [];
            }
            $compatible_bundles = array_merge($compatible_bundles, array_keys($target_bundles));
          }
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error getting incompatible media: @message', [
          '@message' => $e->getMessage(),
        ]);
    }

    // Check each media for compatibility.
    foreach ($this->mediaEntities as $media) {
      if (!empty($compatible_bundles) && !in_array($media->bundle(), $compatible_bundles)) {
        $incompatible[] = $media;
      }
    }

    return $incompatible;
  }

  /**
   * Check if a field is an EXIF field.
   *
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if the field is an EXIF field, FALSE otherwise.
   */
  protected function isExifField($field_name) {
    // EXIF fields typically start with 'field_exif_' or are named 'exif_data'.
    return strpos($field_name, 'field_exif_') === 0 || $field_name === 'exif_data';
  }

  /**
   * Check if two field configs are compatible for merging in union.
   *
   * For taxonomy fields, they are compatible if they point to the same vocabulary.
   *
   * @param \Drupal\field\Entity\FieldConfig $field1
   *   The first field config.
   * @param \Drupal\field\Entity\FieldConfig $field2
   *   The second field config.
   *
   * @return bool
   *   TRUE if fields are compatible, FALSE otherwise.
   */
  protected function areFieldsCompatible($field1, $field2) {
    // If field types don't match, they're not compatible.
    if ($field1->getType() !== $field2->getType()) {
      return FALSE;
    }

    // For entity_reference fields, check target type and bundles.
    if ($field1->getType() === 'entity_reference') {
      $target_type_1 = $field1->getSetting('target_type');
      $target_type_2 = $field2->getSetting('target_type');

      if ($target_type_1 !== $target_type_2) {
        return FALSE;
      }

      // For taxonomy terms, check if they point to the same vocabularies.
      if ($target_type_1 === 'taxonomy_term') {
        $bundles_1 = $field1->getSetting('handler_settings')['target_bundles'] ?? [];
        $bundles_2 = $field2->getSetting('handler_settings')['target_bundles'] ?? [];

        // If both have no restrictions, they're compatible.
        if (empty($bundles_1) && empty($bundles_2)) {
          return TRUE;
        }

        // If they have the same target bundles, they're compatible.
        return $bundles_1 === $bundles_2;
      }
    }

    // For other field types, consider them compatible by default.
    return TRUE;
  }

  /**
   * Get media field configurations from the media type of items in the album.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node.
   *
   * @return array
   *   Array of media field configs with their settings.
   */
  protected function getMediaFieldsConfig($node) {
    $media_fields = [];

    try {
      // Get all media from the node's media fields to find the media type.
      $media_bundles = $this->getMediaBundlesInNode($node);

      if (empty($media_bundles)) {
        return $media_fields;
      }

      // Get media fields from the first media type.
      $media_bundle = reset($media_bundles);

      // Load field configs for this media type.
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'media')
        ->condition('bundle', $media_bundle);

      $field_ids = $query->execute();

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);

        // Types of fields to exclude (main media content and EXIF fields).
        // exif field name start with field_exif_ is handled in isExifField().
        $excluded_field_types = ['image', 'file', 'video_file', 'audio_file', 'document'];

        foreach ($field_configs as $field_config) {
          $field_type = $field_config->get('field_type');
          $field_name = $field_config->getName();
          $is_base_field = $field_config->getFieldStorageDefinition()->isBaseField();

          // Only include custom fields that are not excluded types or EXIF fields.
          if (!$is_base_field &&
          !in_array($field_type, $excluded_field_types) &&
          !$this->isExifField($field_name)) {
            $media_fields[$field_name] = [
              'config' => $field_config,
              'type' => $field_type,
            ];
          }
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error loading media field configuration: @message', [
          '@message' => $e->getMessage(),
        ]);
    }

    return $media_fields;
  }

  /**
   * Get media bundle types present in the node's media fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node.
   *
   * @return array
   *   Array of unique media bundle names.
   */
  protected function getMediaBundlesInNode($node) {
    $bundles = [];

    try {
      // Find all media reference fields on the node.
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'node')
        ->condition('bundle', $node->bundle())
        ->condition('field_type', 'entity_reference');

      $field_ids = $query->execute();

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);

        foreach ($field_configs as $field_config) {
          if ($field_config->getSetting('target_type') === 'media') {
            $field_name = $field_config->getName();

            if ($node->hasField($field_name)) {
              // Get all media in this field (load latest revisions).
              $media_storage = $this->entityTypeManager->getStorage('media');
              foreach ($node->get($field_name) as $item) {
                $media_id = $item->target_id;
                if ($media_id) {
                  $latest_revision_id = $media_storage->getLatestRevisionId($media_id);
                  if ($latest_revision_id) {
                    $media = $media_storage->loadRevision($latest_revision_id);
                    if ($media) {
                      $bundle = $media->bundle();
                      if (!in_array($bundle, $bundles)) {
                        $bundles[] = $bundle;
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error getting media bundles in node: @message', [
          '@message' => $e->getMessage(),
        ]);
    }

    return $bundles;
  }

  /**
   * Get all media IDs already present in the album node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node.
   *
   * @return array
   *   Array of media IDs with their labels, indexed by media ID.
   */
  protected function getMediaIdsInAlbum($node) {
    $existing_media = [];

    try {
      // Find all entity_reference fields on the node.
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'node')
        ->condition('bundle', $node->bundle())
        ->condition('field_type', 'entity_reference');

      $field_ids = $query->execute();

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);

        foreach ($field_configs as $field_config) {
          $target_type = $field_config->getSetting('target_type');
          $field_name = $field_config->getName();

          // Only process entity_reference fields that point to entity types
          // that could potentially contain media (skip taxonomy_term, etc).
          if (!in_array($target_type, ['media', 'node'])) {
            continue;
          }

          if (!$node->hasField($field_name)) {
            continue;
          }

          // Get all entities referenced by this field.
          $referenced_items = $node->get($field_name);

          if ($referenced_items->isEmpty()) {
            continue;
          }

          foreach ($referenced_items as $item) {
            $referenced_entity_id = $item->target_id;
            if (!$referenced_entity_id) {
              continue;
            }

            // Load the referenced entity.
            $media_item = $this->entityTypeManager->getStorage($target_type)->load($referenced_entity_id);
            if (!$media_item) {
              \Drupal::logger('media_album_av')->warning('Referenced entity ID @id in field @field could not be loaded.', [
                '@id' => $referenced_entity_id,
                '@field' => $field_name,
              ]);
              continue;
            }

            $existing_media[$referenced_entity_id] = $media_item->label();

          }
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error getting media IDs in album: @message', [
          '@message' => $e->getMessage(),
        ]);
    }

    return $existing_media;
  }

  /**
   * Build directory selector form element.
   *
   * @return array|null
   *   Form element for directory selection or NULL.
   */

  /**
   * Get all parent IDs for a given term, up to the root.
   *
   * @param int $term_id
   *   The term ID to get parents for.
   * @param array $terms
   *   All available terms indexed by ID.
   *
   * @return array
   *   Array of parent term IDs from immediate parent to root.
   */
  protected function getTermAncestors($term_id, array $terms) {
    $ancestors = [];
    $current_id = $term_id;

    while ($current_id && isset($terms[$current_id])) {
      $term = $terms[$current_id];
      $parent = $term->get('parent');
      $parent_id = !empty($parent->target_id) ? $parent->target_id : 0;

      if ($parent_id === 0 || $parent_id === '0') {
        break;
      }

      $ancestors[] = $parent_id;
      $current_id = $parent_id;
    }

    return $ancestors;
  }

  /**
   * Build hierarchical directory options with indentation (without any marking).
   *
   * @param array $terms
   *   Array of taxonomy terms.
   * @param int $parent_id
   *   Parent term ID for recursive building.
   * @param int $depth
   *   Current depth level for indentation.
   *
   * @return array
   *   Hierarchical options array without any ★ marking.
   */
  protected function buildHierarchicalDirectoryOptions(array $terms, $parent_id = 0, $depth = 0) {
    $options = [];
    $indent = str_repeat('– ', $depth);

    foreach ($terms as $term) {
      // Check if this term has the current parent_id.
      $parent = $term->get('parent');
      $term_parent_id = !empty($parent->target_id) ? $parent->target_id : 0;

      if ($term_parent_id != $parent_id) {
        continue;
      }

      $term_id = $term->id();
      $term_label = $term->label();

      $options[$term_id] = $indent . $term_label;

      // Recursively add children.
      $child_options = $this->buildHierarchicalDirectoryOptions($terms, $term_id, $depth + 1);
      // Use + instead of array_merge to preserve numeric keys (term IDs).
      $options = $options + $child_options;
    }

    return $options;
  }

  /**
   * Build the directory selector element.
   *
   * @return array
   *   Form element array for directory selection.
   */
  protected function buildDirectorySelector() {
    $config = \Drupal::config('media_directories.settings');
    $vocabulary_id = $config->get('directory_taxonomy');

    if (!$vocabulary_id) {
      return NULL;
    }

    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabulary_id]);

    // Get directories already used in the album and cache them.
    $this->usedDirectoriesCache = $this->getUsedDirectoriesInalbum($this->albumNode);

    \Drupal::logger('media_drop')->notice('DEBUG buildDirectorySelector - usedDirectoriesCache: @dirs', [
      '@dirs' => implode(', ', $this->usedDirectoriesCache),
    ]);

    // Build terms_by_id for ancestor lookup.
    $terms_by_id = [];
    foreach ($terms as $term) {
      $terms_by_id[$term->id()] = $term;
    }

    // Calculate used directories with their complete parent chains.
    // Structure: $used_chains[$used_id] = [0, parent_id, ..., used_id]
    // This preserves the hierarchy from ROOT for each used directory.
    // Directories that contain media.
    $used_direct = $this->usedDirectoriesCache;
    $used_chains = [];

    foreach ($used_direct as $used_id) {
      if ($used_id === 0) {
        // ROOT: just the root ID.
        $used_chains[0] = [0];
      }
      elseif (isset($terms_by_id[$used_id])) {
        // Term exists: build complete chain from ROOT to this term.
        $ancestors = $this->getTermAncestors($used_id, $terms_by_id);
        $used_chains[$used_id] = array_merge([0], $ancestors, [$used_id]);
      }
      // else: term doesn't exist in vocabulary, skip it.
    }

    // Flatten all IDs (direct + ancestors) for quick lookup.
    $used_all = [];
    foreach ($used_chains as $chain) {
      $used_all = array_merge($used_all, $chain);
    }
    $used_all = array_unique($used_all);

    // Build hierarchical options for used directories from their chains.
    // This displays only the chain elements with proper indentation.
    $used_options = [];
    foreach ($used_chains as $used_id => $chain) {
      foreach ($chain as $depth => $chain_tid) {
        if (!isset($used_options[$chain_tid])) {
          // Build label with indentation based on position in chain.
          $indent = str_repeat('– ', $depth);
          $term_label = ($chain_tid === 0) ? 'Root (no directory)' : $terms_by_id[$chain_tid]->label();

          // Determine if this is a direct media location or ancestor.
          if (in_array($chain_tid, $used_direct)) {
            $label = $indent . '★ ' . $term_label;
          }
          else {
            $label = $indent . $term_label;
          }

          $used_options[$chain_tid] = $label;
        }
      }
    }

    // Build hierarchical options for unused directories from full vocabulary.
    $all_options = $this->buildHierarchicalDirectoryOptions($terms, 0, 0);

    $unused_options = [];
    foreach ($all_options as $tid => $label) {
      if (!in_array($tid, $used_all)) {
        $unused_options[$tid] = $label;
      }
    }

    // Build the options array with optgroups.
    $options = [];

    // Always add ROOT (0) first as a standalone option (not in optgroups).
    $root_label = '– Root (no directory)';
    $options[0] = $root_label;

    // If ROOT is in used directories, add it to the used_options group instead.
    if (in_array(0, $used_direct)) {
      $root_label = '– ★ Root (no directory)';
      $used_options[0] = $root_label;
      // Remove from standalone.
      unset($options[0]);
    }
    elseif (in_array(0, $used_all) && count($used_chains) > 0) {
      // ROOT is ancestor but not direct: include in used group only if other things are used.
      // Remove from standalone.
      unset($options[0]);
    }

    if (!empty($used_options)) {
      $options[(string) $this->t('→ Currently used directories (★)')] = $used_options;
    }

    if (!empty($unused_options)) {
      $options[(string) $this->t('→ Other directories')] = $unused_options;
    }

    return [
      '#type' => 'select',
      '#title' => $this->t('Move to directory'),
      '#options' => $options,
      '#default_value' => $this->configuration['directory_tid'] ?? 0,
      '#description' => $this->t('Optionally move the selected media to this directory. Directories marked with ★ are currently used in this album. Indentation shows the directory hierarchy.'),
    ];
  }

  /**
   * Get directories already used by media in the album node.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The album node.
   *
   * @return array
   *   Array of directory taxonomy term IDs.
   */
  protected function getUsedDirectoriesInalbum($node) {
    $directories = [];

    if (!$node) {
      return $directories;
    }

    // Find all media reference fields.
    try {
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'node')
        ->condition('bundle', $node->bundle());

      $field_ids = $query->execute();
      $field_configs = [];

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error getting used directories: @message', [
          '@message' => $e->getMessage(),
        ]);
      return $directories;
    }

    foreach ($field_configs as $field_config) {
      if ($field_config->get('field_type') === 'entity_reference') {
        if ($field_config->getSetting('target_type') === 'media') {
          $field_name = $field_config->getName();

          if ($node->hasField($field_name)) {
            // Get all media in this field.
            $media_ids = [];
            foreach ($node->get($field_name) as $item) {
              if ($item->target_id) {
                $media_ids[] = $item->target_id;
              }
            }

            if (!empty($media_ids)) {
              // Load the LATEST revision of each media to get current directory values.
              $media_storage = $this->entityTypeManager->getStorage('media');
              $medias = [];
              foreach ($media_ids as $media_id) {
                $latest_revision_id = $media_storage->getLatestRevisionId($media_id);
                if ($latest_revision_id) {
                  $medias[$media_id] = $media_storage->loadRevision($latest_revision_id);
                }
              }

              foreach ($medias as $media) {
                // Get directory from media.
                $directory_id = NULL;

                if ($media->hasField('directory')) {
                  $field_value = $media->get('directory');
                  if ($field_value && !$field_value->isEmpty()) {
                    // Media has an explicit directory assigned.
                    if (isset($field_value->target_id)) {
                      $directory_id = (int) $field_value->target_id;
                    }
                    else {
                      $directory_id = (int) $field_value->value;
                    }
                  }
                  else {
                    // Field exists but is empty = media is in ROOT (0).
                    $directory_id = 0;
                  }
                }

                // Add the directory ID to the list only if we found an explicit value.
                if ($directory_id !== NULL && !in_array($directory_id, $directories)) {
                  $directories[] = $directory_id;
                  \Drupal::logger('media_drop')->debug('Adding directory @did to usedDirectories for media @mid', [
                    '@did' => $directory_id,
                    '@mid' => $media->id(),
                  ]);
                }
              }
            }
          }
        }
      }
    }

    \Drupal::logger('media_drop')->notice('Found used directories in album @nid: @dirs', [
      '@nid' => $node->id(),
      '@dirs' => implode(', ', $directories),
    ]);

    return $directories;
  }

  /**
   * Get union of editable fields from all acceptable media types.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node.
   *
   * @return array
   *   Array of field configs keyed by field name (union of all media types).
   */
  protected function getalbumEditableFields($node) {
    $editable_fields = [];

    try {
      // Find the first media reference field on the node.
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'node')
        ->condition('bundle', $node->bundle())
        ->condition('field_type', 'entity_reference');

      $field_ids = $query->execute();
      $media_field = NULL;

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);

        // Find the first media reference field.
        foreach ($field_configs as $field_config) {
          if ($field_config->getSetting('target_type') === 'media') {
            $media_field = $field_config;
            break;
          }
        }
      }

      if (!$media_field) {
        \Drupal::logger('media_drop')->notice('No media field found on node @nid', ['@nid' => $node->id()]);
        return $editable_fields;
      }

      // Get the media bundles this field accepts.
      $target_bundles = $media_field->getSetting('handler_settings')['target_bundles'] ?? [];

      \Drupal::logger('media_drop')->notice('Media field target_bundles: @bundles', ['@bundles' => implode(', ', array_keys($target_bundles))]);

      // Determine which media bundles to load fields from.
      $media_bundles_to_load = [];

      if (!empty($target_bundles)) {
        // Media field restricts to specific bundles.
        $media_bundles_to_load = array_keys($target_bundles);
      }
      else {
        // If no bundles restricted, use bundles from actual media in node.
        $media_bundles_to_load = $this->getMediaBundlesInNode($node);
        \Drupal::logger('media_drop')->notice('Using bundles from node: @bundles', ['@bundles' => implode(', ', $media_bundles_to_load)]);
      }

      if (empty($media_bundles_to_load)) {
        \Drupal::logger('media_drop')->notice('No media bundles found');
        return $editable_fields;
      }

      // Show message about multiple types.
      if (count($media_bundles_to_load) > 1) {
        \Drupal::messenger()->addStatus(
        $this->t('Media field accepts multiple types: <strong>@types</strong>. Fields will be applied only where they exist.', [
          '@types' => implode(', ', $media_bundles_to_load),
        ])
        );
      }

      \Drupal::logger('media_drop')->notice('Loading fields for all media bundles: @bundles', ['@bundles' => implode(', ', $media_bundles_to_load)]);

      // Types of fields to exclude (main media content and EXIF).
      $excluded_field_types = ['image', 'file', 'video_file', 'audio_file', 'document'];

      // Load fields from ALL acceptable media bundles and create union.
      foreach ($media_bundles_to_load as $media_bundle) {
        $query = $this->entityTypeManager->getStorage('field_config')
          ->getQuery()
          ->condition('entity_type', 'media')
          ->condition('bundle', $media_bundle);

        $field_ids = $query->execute();
        \Drupal::logger('media_drop')->notice('Found @count fields for media bundle @bundle', ['@count' => count($field_ids), '@bundle' => $media_bundle]);

        if (!empty($field_ids)) {
          $field_configs = $this->entityTypeManager->getStorage('field_config')
            ->loadMultiple($field_ids);

          foreach ($field_configs as $field_config) {
            $field_name = $field_config->getName();
            $field_type = $field_config->get('field_type');
            $is_base_field = $field_config->getFieldStorageDefinition()->isBaseField();

            // Only include custom fields that are not excluded types or EXIF fields.
            if (!$is_base_field &&
            !in_array($field_type, $excluded_field_types) &&
            !$this->isExifField($field_name)) {
              // Check if field already exists in union.
              if (!isset($editable_fields[$field_name])) {
                // Add new field.
                $editable_fields[$field_name] = $field_config;
              }
              else {
                // Check if the new field is compatible with the existing one.
                if ($this->areFieldsCompatible($editable_fields[$field_name], $field_config)) {
                  // Keep the first occurrence (already there).
                  \Drupal::logger('media_drop')->notice(
                  'Field @field already in union, compatible with @bundle',
                  ['@field' => $field_name, '@bundle' => $media_bundle]
                  );
                }
                else {
                  // Log incompatibility but don't fail.
                  \Drupal::logger('media_drop')->warning(
                  'Field @field in bundle @bundle is incompatible with previous definition',
                  ['@field' => $field_name, '@bundle' => $media_bundle]
                  );
                }
              }
            }
          }
        }
      }

      \Drupal::logger('media_drop')->notice('Union of editable fields: @fields', ['@fields' => implode(', ', array_keys($editable_fields))]);
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error getting album editable fields: @message', [
          '@message' => $e->getMessage(),
        ]);
    }

    return $editable_fields;
  }

  /**
   * Generate a unique designation key for a field.
   *
   * This key groups fields by their type, label, and (for taxonomies) their
   * target vocabulary, so that equivalent fields across media types are merged.
   *
   * @param \Drupal\field\Entity\FieldConfig $field_config
   *   The field config.
   *
   * @return string
   *   A unique designation key.
   */
  protected function getFieldDesignation($field_config) {
    $field_type = $field_config->get('field_type');
    $field_label = $field_config->get('label');

    // For taxonomy fields, include the target vocabularies in the designation.
    if ($field_type === 'entity_reference' &&
    $field_config->getSetting('target_type') === 'taxonomy_term') {
      $target_bundles = $field_config->getSetting('handler_settings')['target_bundles'] ?? [];
      $vocab_key = !empty($target_bundles) ?
        implode(',', array_keys($target_bundles)) : 'all';
      return "{$field_type}|{$field_label}|{$vocab_key}";
    }

    return "{$field_type}|{$field_label}";
  }

  /**
   * Get editable fields grouped by designation (type, label, and taxonomy).
   *
   * This returns fields grouped by their "designation" so that equivalent
   * fields across different media types are presented as one.
   *
   * Also includes fields from entities referenced through entity_reference fields
   * (relationships) to provide a complete picture of editable fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node.
   *
   * @return array
   *   Array grouped by designation key, each containing:
   *   - 'designation': Human-readable designation
   *   - 'field_config': First field config (representative)
   *   - 'field_names': Array of actual field names across all media types
   */
  protected function getAlbumEditableFieldsGrouped($node) {
    $grouped_fields = [];

    try {
      // Find the first media reference field on the node.
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'node')
        ->condition('bundle', $node->bundle())
        ->condition('field_type', 'entity_reference');

      $field_ids = $query->execute();
      $media_field = NULL;

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);

        // Find the first media reference field.
        foreach ($field_configs as $field_config) {
          if ($field_config->getSetting('target_type') === 'media') {
            $media_field = $field_config;
            break;
          }
        }
      }

      if (!$media_field) {
        \Drupal::logger('media_drop')->notice('No media field found on node @nid', ['@nid' => $node->id()]);
        return $grouped_fields;
      }

      // Get the media bundles this field accepts.
      $target_bundles = $media_field->getSetting('handler_settings')['target_bundles'] ?? [];

      // Determine which media bundles to load fields from.
      $media_bundles_to_load = [];

      if (!empty($target_bundles)) {
        // Media field restricts to specific bundles.
        $media_bundles_to_load = array_keys($target_bundles);
      }
      else {
        // If no bundles restricted, use bundles from actual media in node.
        $media_bundles_to_load = $this->getMediaBundlesInNode($node);
      }

      if (empty($media_bundles_to_load)) {
        \Drupal::logger('media_drop')->notice('No media bundles found');
        return $grouped_fields;
      }

      // Show message about multiple types.
      if (count($media_bundles_to_load) > 1) {
        \Drupal::messenger()->addStatus(
        $this->t('Media field accepts multiple types: <strong>@types</strong>. Fields will be applied only where they exist.', [
          '@types' => implode(', ', $media_bundles_to_load),
        ])
        );
      }

      // Types of fields to exclude (main media content).
      $excluded_field_types = ['image', 'file', 'video_file', 'audio_file', 'document'];

      // Load fields from ALL acceptable media bundles and group by designation.
      foreach ($media_bundles_to_load as $media_bundle) {
        $query = $this->entityTypeManager->getStorage('field_config')
          ->getQuery()
          ->condition('entity_type', 'media')
          ->condition('bundle', $media_bundle);

        $field_ids = $query->execute();

        if (!empty($field_ids)) {
          $field_configs = $this->entityTypeManager->getStorage('field_config')
            ->loadMultiple($field_ids);

          foreach ($field_configs as $field_config) {
            $field_type = $field_config->get('field_type');
            $is_base_field = $field_config->getFieldStorageDefinition()->isBaseField();

            // Only include custom fields that are not excluded types or EXIF fields.
            if (!$is_base_field &&
            !in_array($field_type, $excluded_field_types) &&
            !$this->isExifField($field_config->getName())) {

              // Get the designation for this field.
              $designation = $this->getFieldDesignation($field_config);

              // Add to grouped fields.
              if (!isset($grouped_fields[$designation])) {
                $grouped_fields[$designation] = [
                  'designation' => $field_config->get('label'),
                  'field_config' => $field_config,
                  'field_names' => [],
                ];
              }

              // Add this field name to the list for this designation.
              if (!in_array($field_config->getName(), $grouped_fields[$designation]['field_names'])) {
                $grouped_fields[$designation]['field_names'][] = $field_config->getName();
              }
            }
            // Also handle entity_reference fields to include related entity fields.
            elseif ($field_type === 'entity_reference' && !$is_base_field) {
              $target_type = $field_config->getSetting('target_type');
              $target_bundles = $field_config->getSetting('handler_settings')['target_bundles'] ?? [];

              // Load fields from the target entity type(s).
              if (!empty($target_type)) {
                $target_bundles_to_check = !empty($target_bundles) ? array_keys($target_bundles) : [];

                // If no specific bundles, load all.
                if (empty($target_bundles_to_check)) {
                  $bundle_query = $this->entityTypeManager->getStorage('field_config')
                    ->getQuery()
                    ->condition('entity_type', $target_type);
                  $bundle_field_ids = $bundle_query->execute();
                  $target_bundles_to_check = [];

                  if (!empty($bundle_field_ids)) {
                    $target_bundle_configs = $this->entityTypeManager->getStorage('field_config')
                      ->loadMultiple($bundle_field_ids);
                    foreach ($target_bundle_configs as $cfg) {
                      $bundle_name = $cfg->getTargetBundle();
                      if (!in_array($bundle_name, $target_bundles_to_check)) {
                        $target_bundles_to_check[] = $bundle_name;
                      }
                    }
                  }
                }

                // Load and process fields from related entity bundles.
                foreach ($target_bundles_to_check as $related_bundle) {
                  $related_query = $this->entityTypeManager->getStorage('field_config')
                    ->getQuery()
                    ->condition('entity_type', $target_type)
                    ->condition('bundle', $related_bundle);

                  $related_field_ids = $related_query->execute();

                  if (!empty($related_field_ids)) {
                    $related_field_configs = $this->entityTypeManager->getStorage('field_config')
                      ->loadMultiple($related_field_ids);

                    foreach ($related_field_configs as $related_config) {
                      $related_field_type = $related_config->get('field_type');
                      $related_is_base = $related_config->getFieldStorageDefinition()->isBaseField();

                      // Include editable related fields (text, taxonomies, etc.)
                      if (!$related_is_base &&
                      !in_array($related_field_type, $excluded_field_types) &&
                      !$this->isExifField($related_config->getName()) &&
                      in_array($related_field_type, ['string', 'string_long', 'text', 'text_long', 'text_with_summary', 'entity_reference'])) {

                        $related_designation = $this->getFieldDesignation($related_config);

                        // Add to grouped fields if not already there.
                        if (!isset($grouped_fields[$related_designation])) {
                          $grouped_fields[$related_designation] = [
                            'designation' => $related_config->get('label'),
                            'field_config' => $related_config,
                            'field_names' => [],
                          ];
                        }

                        // Add this field name to the list.
                        if (!in_array($related_config->getName(), $grouped_fields[$related_designation]['field_names'])) {
                          $grouped_fields[$related_designation]['field_names'][] = $related_config->getName();
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }

      \Drupal::logger('media_drop')->notice('Grouped fields by designation: @count groups', ['@count' => count($grouped_fields)]);
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error getting album editable fields grouped: @message', [
          '@message' => $e->getMessage(),
        ]);
    }

    return $grouped_fields;
  }

  /**
   * Build field widget based on field config.
   *
   * @param object $field_config
   *   The field config.
   * @param mixed $default_value
   *   The default value for the field.
   *
   * @return array
   *   Form element for the field.
   */
  protected function buildFieldWidget($field_config, $default_value = NULL) {
    $field_type = $field_config->get('field_type');
    $field_label = $field_config->get('label');

    switch ($field_type) {
      case 'string':
      case 'string_long':
        return [
          '#type' => $field_type === 'string_long' ? 'textarea' : 'textfield',
          '#title' => $field_label,
          '#default_value' => $default_value ? ($default_value[0]['value'] ?? '') : '',
        ];

      case 'integer':
      case 'decimal':
      case 'float':
        return [
          '#type' => 'textfield',
          '#title' => $field_label,
          '#default_value' => $default_value ? ($default_value[0]['value'] ?? '') : '',
        ];

      case 'boolean':
        return [
          '#type' => 'checkbox',
          '#title' => $field_label,
          '#default_value' => $default_value ? ($default_value[0]['value'] ?? FALSE) : FALSE,
        ];

      case 'entity_reference':
        $target_type = $field_config->getSetting('target_type');
        $handler_settings = $field_config->getSetting('handler_settings') ?? [];
        $target_bundles = $handler_settings['target_bundles'] ?? [];

        // For taxonomy terms, use textfield with custom autocomplete route.
        if ($target_type === 'taxonomy_term') {
          $vocab_ids = is_array($target_bundles) ? array_keys($target_bundles) : [];

          if (!empty($vocab_ids)) {
            $vocab_string = implode(',', $vocab_ids);

            return [
              '#type' => 'textfield',
              '#title' => $field_label,
              '#default_value' => $default_value && isset($default_value[0]['target_id']) ?
              $this->entityTypeManager->getStorage('taxonomy_term')->load($default_value[0]['target_id'])->label() : '',
              '#attributes' => [
                'class' => ['form-autocomplete'],
                'data-autocomplete-path' => Url::fromRoute(
            'media_drop.taxonomy_autocomplete',
            ['vocabularies' => $vocab_string]
                )->toString(),
              ],
            ];
          }

          // Fallback if no vocabularies specified.
          return [
            '#type' => 'textfield',
            '#title' => $field_label,
            '#default_value' => $default_value && isset($default_value[0]['target_id']) ?
            $this->entityTypeManager->getStorage('taxonomy_term')->load($default_value[0]['target_id'])->label() : '',
            '#attributes' => [
              'class' => ['form-autocomplete'],
            ],
          ];
        }

        // For other entity types (nodes, etc.), use entity_autocomplete.
        return [
          '#type' => 'entity_autocomplete',
          '#target_type' => $target_type,
          '#title' => $field_label,
          '#default_value' => $default_value && isset($default_value[0]['target_id']) ? $this->entityTypeManager->getStorage($target_type)->load($default_value[0]['target_id']) : NULL,
          '#selection_settings' => [
            'target_bundles' => $target_bundles,
          ],
        ];

      case 'list_string':
      case 'list_integer':
        $options = $field_config->getSetting('allowed_values') ?? [];
        return [
          '#type' => 'select',
          '#title' => $field_label,
          '#options' => ['' => $this->t('- None -')] + $options,
          '#default_value' => $default_value ? ($default_value[0]['value'] ?? '') : '',
        ];

      case 'text':
      case 'text_with_summary':
        return [
          '#type' => 'textarea',
          '#title' => $field_label,
          '#default_value' => $default_value ? ($default_value[0]['value'] ?? '') : '',
        ];

      default:
        return [
          '#type' => 'textfield',
          '#title' => $field_label,
          '#default_value' => $default_value ? ($default_value[0]['value'] ?? '') : '',
        ];
    }
  }

  /**
   * Get media metadata fields (title and alt) available in the media types.
   *
   * Returns an array of field groups, each with:
   * - 'type': Either 'title' or 'alt'
   * - 'field_names': Array of actual field names where this metadata can be stored.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The album node.
   *
   * @return array
   *   Array with 'title' and 'alt' keys, each containing field_names array.
   */
  protected function getMediaMetadataFields($node) {
    $metadata_fields = [
      'title' => [],
      'alt' => [],
      'description' => [],
    ];

    try {
      // Find the first media reference field on the node.
      $query = $this->entityTypeManager->getStorage('field_config')
        ->getQuery()
        ->condition('entity_type', 'node')
        ->condition('bundle', $node->bundle())
        ->condition('field_type', 'entity_reference');

      $field_ids = $query->execute();
      $media_field = NULL;

      if (!empty($field_ids)) {
        $field_configs = $this->entityTypeManager->getStorage('field_config')
          ->loadMultiple($field_ids);

        // Find the first media reference field.
        foreach ($field_configs as $field_config) {
          if ($field_config->getSetting('target_type') === 'media') {
            $media_field = $field_config;
            break;
          }
        }
      }

      if (!$media_field) {
        return $metadata_fields;
      }

      // Get the media bundles this field accepts.
      $target_bundles = $media_field->getSetting('handler_settings')['target_bundles'] ?? [];

      // Determine which media bundles to load fields from.
      $media_bundles_to_load = [];

      if (!empty($target_bundles)) {
        $media_bundles_to_load = array_keys($target_bundles);
      }
      else {
        $media_bundles_to_load = $this->getMediaBundlesInNode($node);
      }

      if (empty($media_bundles_to_load)) {
        return $metadata_fields;
      }

      // Load fields from all acceptable media bundles to find title, alt, and description fields.
      foreach ($media_bundles_to_load as $media_bundle) {
        $query = $this->entityTypeManager->getStorage('field_config')
          ->getQuery()
          ->condition('entity_type', 'media')
          ->condition('bundle', $media_bundle);

        $field_ids = $query->execute();

        if (!empty($field_ids)) {
          $field_configs = $this->entityTypeManager->getStorage('field_config')
            ->loadMultiple($field_ids);

          foreach ($field_configs as $field_config) {
            $field_type = $field_config->getType();
            if (($field_type === 'image' || $field_type === 'file' || $field_type === 'video_file')) {
              $field_name = $field_config->getName();

              // Title field: only on image and file fields.
              if (($field_type === 'image' || $field_type === 'file') && !in_array($field_name, $metadata_fields['title'])) {
                $metadata_fields['title'][] = $field_name;
              }

              // Alt field: only on image fields.
              if ($field_type === 'image' && !in_array($field_name, $metadata_fields['alt'])) {
                $metadata_fields['alt'][] = $field_name;
              }

              // Description field: only on video_file fields.
              if ($field_type === 'video_file' || $field_type === 'file') {
                if ($field_name === 'description' && !in_array($field_name, $metadata_fields['description'])) {
                  $metadata_fields['description'][] = $field_name;
                }
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error getting media metadata fields: @message', [
          '@message' => $e->getMessage(),
        ]);
    }

    return $metadata_fields;
  }

  /**
   * Build the album configuration form section.
   *
   * @param array $wrapper
   *   Unused, kept for compatibility.
   *
   * @return array
   *   The step_2 element ready to add to main form.
   */
  protected function buildAlbumConfigurationForm(array $wrapper) {
    if (!$this->albumNode) {
      return [
        '#type' => 'container',
        '#access' => FALSE,
        '#tree' => TRUE,
        '#weight' => 10,
      ];
    }

    /*  $step_2 = [
    '#type' => 'details',
    '#title' => $this->t('Step 2: Configure album Fields'),
    '#open' => TRUE,
    '#tree' => TRUE,
    '#attributes' => ['id' => 'album-fields-wrapper'],
    '#access' => TRUE,
    ]; */

    // Initialize step_2 array.
    $step_2 = [];

    $step_2['info'] = [
      '#markup' => '<div class="messages messages--status">' .
      $this->t('Album: <strong>@album_title</strong>', ['@album_title' => $this->albumNode->label()]) .
      '</div>',
    ];

    // Hidden input to signal that step_2 is fully rendered (for form states).
    // This input only exists when step_2 is created, so form states can use it to detect
    // when step_2 is ready.
    $step_2['step_2_ready_marker'] = [
      '#type' => 'hidden',
      '#value' => '1',
    ];

    // Show existing media in album and which ones will be added.
    $existing_media = $this->getMediaIdsInAlbum($this->albumNode);
    $new_media_count = 0;
    $duplicate_count = 0;
    $new_media_list = '';
    $duplicate_list = '';

    foreach ($this->mediaEntities as $media_id => $media) {
      if (isset($existing_media[$media_id])) {
        $duplicate_count++;
        $duplicate_list .= '<li>' . $media->label() . ' (ID: ' . $media_id . ')</li>';
      }
      else {
        $new_media_count++;
        $new_media_list .= '<li>' . $media->label() . ' (ID: ' . $media_id . ')</li>';
      }
    }

    // Show summary of media processing status.
    $not_processed_count = $duplicate_count + count($this->getIncompatibleMedia($this->albumNode));
    if ($not_processed_count > 0) {
      $step_2['summary_warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('<strong>⚠️ @count media will NOT be processed</strong> (expand the sections below to see details)', ['@count' => $not_processed_count]) .
        '</div>',
      ];
    }

    // Show media already in album (in collapsible details).
    if (!empty($existing_media)) {
      $existing_list = '';
      foreach ($existing_media as $media_id => $label) {
        $existing_list .= '<li>' . $label . ' (ID: ' . $media_id . ')</li>';
      }
      $step_2['existing_media'] = [
        '#type' => 'details',
        '#title' => $this->t('Media already in album (@count)', ['@count' => count($existing_media)]),
        '#open' => FALSE,
        '#markup' => '<ul>' . $existing_list . '</ul>',
      ];
    }

    // Show duplicates (media already in album that were selected).
    if ($duplicate_count > 0) {
      $step_2['duplicates'] = [
        '#type' => 'details',
        '#title' => $this->t('Selected media already in album - will be skipped (@count)', ['@count' => $duplicate_count]),
        '#open' => FALSE,
        '#markup' => '<ul>' . $duplicate_list . '</ul>',
      ];
    }

    // Show media that will be added (in collapsible details).
    if ($new_media_count > 0) {
      $step_2['new_media'] = [
        '#type' => 'details',
        '#title' => $this->t('✓ Media to be added to the album (@count)', ['@count' => $new_media_count]),
        '#open' => TRUE,
        '#markup' => '<ul>' . $new_media_list . '</ul>',
      ];
    }

    // Show media compatibility info (in collapsible details).
    $incompatible_media = $this->getIncompatibleMedia($this->albumNode);
    if (!empty($incompatible_media)) {
      $incompatible_list = '';
      foreach ($incompatible_media as $media) {
        $incompatible_list .= '<li>' . $media->label() . ' (' . $media->bundle() . ')</li>';
      }
      $step_2['incompatible_warning'] = [
        '#type' => 'details',
        '#title' => $this->t('Incompatible media - will NOT be imported (@count)', ['@count' => count($incompatible_media)]),
        '#open' => FALSE,
        '#markup' => '<ul>' . $incompatible_list . '</ul>',
      ];
    }

    if ($this->move) {
      // Directory selection (if media_directories is enabled).
      if (\Drupal::moduleHandler()->moduleExists('media_directories')) {
        $directory_element = $this->buildDirectorySelector();
        if ($directory_element) {
          $step_2['directory_tid'] = $directory_element;
        }
      }
    }
    // Show album editable fields - grouped by designation.
    $grouped_album_fields = $this->getAlbumEditableFieldsGrouped($this->albumNode);

    if (!empty($grouped_album_fields)) {
      $step_2['album_fields'] = [
        '#type' => 'details',
        '#title' => $this->t('Media Type Fields (from Media Field)'),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      foreach ($grouped_album_fields as $designation_key => $field_group) {
        $field_config = $field_group['field_config'];
        $field_label = $field_group['designation'];
        $field_names = $field_group['field_names'];

        $step_2['album_fields'][$designation_key] = [
          '#type' => 'details',
          '#title' => $field_label,
          '#open' => FALSE,
        ];

        if (($this->albumNode->hasField($field_group['field_names'][0])) &&
          (!empty($this->albumNode->get($field_group['field_names'][0])->first()))) {
          $default_from_node[0]['target_id'] = $this->albumNode->get($field_group['field_names'][0])->first()->get('target_id')->getValue();
        }
        else {
          $default_from_node = NULL;
        }
        $default_value = $this->configuration['album_field_values'][$designation_key] ??
          $default_from_node ?? NULL;

        $step_2['album_fields'][$designation_key]['value'] = $this->buildFieldWidget(
        $field_config,
        $default_value
        );

        // Store the field names that belong to this designation for later processing.
        $step_2['album_fields'][$designation_key]['field_names'] = [
          '#type' => 'value',
          '#value' => $field_names,
        ];

        $field_names_display = implode(', ', $field_names);
        $step_2['album_fields'][$designation_key]['description'] = [
          '#markup' => '<p><em>' . $this->t('This value will be applied to all selected media (fields: @fields).', ['@fields' => $field_names_display]) . '</em></p>',
        ];
      }
    }

    return $step_2;
  }

  /**
   * AJAX callback to update album fields when album selection changes.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The updated form element.
   */
  public function ajaxUpdateAlbumFields(array $form, FormStateInterface $form_state) {
    // Force form rebuild so Drupal recalculates all form values.
    $form_state->setRebuild(TRUE);

    // Return the wrapper (which contains step_2).
    return $form['step_2_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // DEBUG: Log the structure of $values to see where directory_tid is.
    \Drupal::logger('media_drop')->notice('submitConfigurationForm - $values structure: @values', [
      '@values' => json_encode(array_keys($values), JSON_PRETTY_PRINT),
    ]);
    if (isset($values['step_2'])) {
      \Drupal::logger('media_drop')->notice('step_2 keys: @keys', [
        '@keys' => json_encode(array_keys($values['step_2']), JSON_PRETTY_PRINT),
      ]);
      if (isset($values['step_2']['directory_tid'])) {
        \Drupal::logger('media_drop')->notice('directory_tid found in step_2: @tid', [
          '@tid' => $values['step_2']['directory_tid'],
        ]);
      }
    }

    $this->configuration['album_id'] = $values['step_1']['album_id'] ?? NULL;

    // Get directory_tid from step_2 inside wrapper.
    $directory_tid = NULL;
    if (isset($values['step_2_wrapper']['step_2']) && is_array($values['step_2_wrapper']['step_2'])) {
      $directory_tid = $values['step_2_wrapper']['step_2']['directory_tid'] ?? NULL;
      \Drupal::logger('media_drop')->notice('Raw directory_tid from form: @tid (type: @type)', [
        '@tid' => var_export($directory_tid, TRUE),
        '@type' => gettype($directory_tid),
      ]);
      // Ensure directory_tid is an integer (not a string).
      if ($directory_tid !== NULL && $directory_tid !== '') {
        $directory_tid = (int) $directory_tid;
      }
    }
    $this->configuration['directory_tid'] = $directory_tid;

    // Store album field values.
    if (isset($values['step_2_wrapper']['step_2']['album_fields'])) {
      if (!isset($this->configuration['album_field_values'])) {
        $this->configuration['album_field_values'] = [];
      }
      $this->configuration['album_field_values'] = array_merge(
      $this->configuration['album_field_values'],
      $values['step_2_wrapper']['step_2']['album_fields']
      );
    }

    // Store media metadata (title, alt) with their field_names.
    if (isset($values['step_2_wrapper']['step_2']['media_metadata'])) {
      if (!isset($this->configuration['media_metadata'])) {
        $this->configuration['media_metadata'] = [];
      }

      foreach ($values['step_2_wrapper']['step_2']['media_metadata'] as $metadata_type => $metadata_data) {
        // Only process if there's actual data (title or alt).
        if (is_array($metadata_data) && isset($metadata_data['value'])) {
          $this->configuration['media_metadata'][$metadata_type] = [
            'value' => $metadata_data['value'],
            'field_names' => $metadata_data['field_names'] ?? [],
          ];
        }
      }
    }
  }

  /**
   * Add a media entity to a node's media reference field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $field_name
   *   The media field name.
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to add.
   */
  protected function addMediaToField($node, $field_name, $media) {
    if ($node->hasField($field_name)) {
      // Get the field values from the entity itself, not cached copies.
      $field_values = $node->get($field_name)->getValue();

      // Make sure we have a clean array.
      if (!is_array($field_values)) {
        $field_values = [];
      }

      $media_id = $media->id();

      // Check if media already exists in the field.
      $already_exists = FALSE;
      foreach ($field_values as $value) {
        if (!empty($value['target_id']) && $value['target_id'] == $media_id) {
          $already_exists = TRUE;
          break;
        }
      }

      if (!$already_exists) {
        // Add the new media reference.
        $field_values[] = ['target_id' => $media_id];

        // Set the field with the complete list of values.
        $node->set($field_name, $field_values);

        \Drupal::logger('media_drop')->debug(
          'Added media @mid to field @field on node @nid',
          [
            '@mid' => $media_id,
            '@field' => $field_name,
            '@nid' => $node->id(),
          ]
        );
      }
    }
  }

  /**
   * Find or create a taxonomy term by label.
   *
   * @param string $term_label
   *   The term label to search for or create.
   * @param string $vocabulary_id
   *   The vocabulary ID where to search/create the term.
   *
   * @return int|null
   *   The term ID if found or created, NULL otherwise.
   */
  protected function findOrCreateTaxonomyTerm($term_label, $vocabulary_id) {
    if (empty($term_label) || empty($vocabulary_id)) {
      return NULL;
    }

    try {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

      // Search for existing term with this label in the vocabulary.
      $query = $term_storage->getQuery()
        ->condition('name', $term_label, '=')
        ->condition('vid', $vocabulary_id)
        ->accessCheck(FALSE);

      $term_ids = $query->execute();

      if (!empty($term_ids)) {
        // Return the first matching term ID.
        return reset($term_ids);
      }

      // Term doesn't exist, create it.
      $new_term = $term_storage->create([
        'name' => $term_label,
        'vid' => $vocabulary_id,
      ]);
      $new_term->save();

      return $new_term->id();
    }
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error finding or creating taxonomy term "@label" in vocabulary @vid: @message', [
          '@label' => $term_label,
          '@vid' => $vocabulary_id,
          '@message' => $e->getMessage(),
        ]);
      return NULL;
    }
  }

  /**
   * Apply field values from configuration to media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   */
  protected function applyFieldValuesToMedia($media) {
    // Apply album field values (grouped by designation).
    if (isset($this->configuration['album_field_values'])) {
      foreach ($this->configuration['album_field_values'] as $designation_key => $field_data) {
        // Get the actual value and field names.
        $field_value = NULL;
        $field_names = [];

        if (is_array($field_data)) {
          // Extract the value from the nested form structure.
          if (isset($field_data['value'])) {
            $field_value = $field_data['value'];
          }
          // Get the list of actual field names for this designation.
          if (isset($field_data['field_names'])) {
            $field_names = $field_data['field_names'];
          }
        }
        else {
          // Fallback for backward compatibility.
          $field_value = $field_data;
        }

        // Skip empty values.
        if ($field_value === '' || $field_value === NULL || $field_value === []) {
          continue;
        }

        // Apply the value to all fields in this group that exist on this media.
        foreach ($field_names as $field_name) {
          if ($media->hasField($field_name)) {
            if (is_array($field_value) && isset($field_value['value'])) {
              $value_to_set = $field_value['value'];
            }
            else {
              $value_to_set = $field_value;
            }

            if ($value_to_set === '' || $value_to_set === NULL || $value_to_set === []) {
              continue;
            }

            $field_definition = $media->getFieldDefinition($field_name);
            $field_type = $field_definition->getType();

            switch ($field_type) {
              case 'entity_reference':
                $field_definition = $media->getFieldDefinition($field_name);
                $target_type = $field_definition->getSetting('target_type');

                if ($target_type === 'taxonomy_term') {
                  // For taxonomy terms, handle multiple input formats:
                  // - term_id|term_label (autocomplete format)
                  // - Just term_id (numeric)
                  // - Just term_label (string)
                  if (is_string($value_to_set) && !empty($value_to_set)) {
                    // Get the first target vocabulary for this field.
                    $handler_settings = $field_definition->getSetting('handler_settings') ?? [];
                    $target_bundles = $handler_settings['target_bundles'] ?? [];

                    if (!empty($target_bundles)) {
                      // Use the first vocabulary if multiple are allowed.
                      $bundle_keys = array_keys($target_bundles);
                      $vocabulary_id = reset($bundle_keys);

                      // Check if value contains pipe (term_id|term_label format).
                      if (strpos($value_to_set, '|') !== FALSE) {
                        // Format: "term_id|term_label" - extract the term_id.
                        [$term_id_str] = explode('|', $value_to_set, 2);
                        $term_id = (int) trim($term_id_str);
                        if ($term_id > 0) {
                          $media->set($field_name, ['target_id' => $term_id]);
                        }
                      }
                      elseif (is_numeric($value_to_set)) {
                        // Value is just a numeric term ID.
                        $term_id = (int) $value_to_set;
                        if ($term_id > 0) {
                          $media->set($field_name, ['target_id' => $term_id]);
                        }
                      }
                      else {
                        // Value is a term label - find or create it.
                        $term_id = $this->findOrCreateTaxonomyTerm($value_to_set, $vocabulary_id);
                        if ($term_id) {
                          $media->set($field_name, ['target_id' => $term_id]);
                        }
                      }
                    }
                  }
                  elseif (is_array($value_to_set) && isset($value_to_set['target_id'])) {
                    // Already has target_id, just set it.
                    $media->set($field_name, $value_to_set);
                  }
                }
                else {
                  // For other entity types (nodes, etc.).
                  if (is_array($value_to_set) && isset($value_to_set['target_id'])) {
                    $media->set($field_name, $value_to_set);
                  }
                  else {
                    // Try to parse "entity_id|entity_label" format from autocomplete.
                    $target_id = $value_to_set;
                    if (is_string($value_to_set) && strpos($value_to_set, '|') !== FALSE) {
                      [$target_id] = explode('|', $value_to_set, 2);
                    }
                    $media->set($field_name, ['target_id' => $target_id]);
                  }
                }
                break;

              case 'boolean':
                $media->set($field_name, (bool) $value_to_set);
                break;

              default:
                $media->set($field_name, $value_to_set);
                break;
            }
          }
        }
      }
    }

    // Apply media metadata (title, alt) with field_names resolution.
    if (isset($this->configuration['media_metadata'])) {
      foreach ($this->configuration['media_metadata'] as $key => $data) {
        if (!is_array($data) || !isset($data['value']) || !isset($data['field_names'])) {
          // Invalid structure, skip.
          unset($this->configuration['media_metadata'][$key]);
        }

        // Apply title.
        if (isset($this->configuration['media_metadata'][$key])) {
          $title_data = $this->configuration['media_metadata'][$key];
          $title_value = is_array($title_data) ? ($title_data['value'] ?? '') : $title_data;
          $title_field_names = is_array($title_data) ? ($title_data['field_names'] ?? []) : [];

          if (!empty($title_value)) {
            // Apply to all identified title fields.
            foreach ($title_field_names as $field_name) {
              if ($media->hasField($field_name)) {
                $item = $media->get($field_name)->first();
                $item->$key = $data['value'];
                \Drupal::logger('media_drop')->debug(
                'Applied title "@value" to field @field on media @mid',
                [
                  '@value' => $title_value,
                  '@field' => $field_name,
                  '@mid' => $media->id(),
                ]
                );
              }
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\media\MediaInterface $object */
    $access = $object->access('update', $account, TRUE)
      ->andIf($object->status->access('edit', $account, TRUE));

    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Execute the action on a single entity.
   *
   * Must be implemented by subclasses.
   *
   * @param \Drupal\media\MediaInterface|null $entity
   *   The media entity.
   */
  abstract public function execute($entity = NULL);

}
