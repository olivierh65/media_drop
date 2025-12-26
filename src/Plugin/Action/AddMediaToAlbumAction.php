<?php

namespace Drupal\media_drop\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Adds media entities to an album node with media field values.
 *
 * @Action(
 *   id = "media_drop_add_to_album",
 *   label = @Translation("Add to album"),
 *   type = "media",
 *   category = @Translation("Media Drop"),
 *   confirm = TRUE
 * )
 */
class AddMediaToAlbumAction extends ConfigurableActionBase implements ContainerFactoryPluginInterface {


  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * Constructs an AddMediaToAlbumAction object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
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
      'album_id' => NULL,
      'directory_tid' => NULL,
      'album_field_values' => [],
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

    $form['#tree'] = TRUE;

    // Step 1: Select Album and Directory.
    $form['step_1'] = [
      '#type' => 'details',
      '#title' => $this->t('Step 1: Select Album and Directory'),
      '#open' => TRUE,
    ];

    $form['step_1']['info'] = [
      '#markup' => '<div class="messages messages--status">' .
      $this->t('Selected media: <strong>@count</strong>', ['@count' => count($this->mediaEntities)]) .
      '</div>',
    ];

    // Album selection.
    $album_bundles = [];
    $album_options = $this->getAlbumOptions($album_bundles);

    $form['step_1']['album_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Select album'),
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

    // Directory selection has been moved to Step 2.
    // Step 2: Configure Album Fields.
    if ($form_state->getValue('album_id')) {
      $album_id = $form_state->getValue('album_id');
      $this->albumNode = $this->entityTypeManager->getStorage('node')->load($album_id);
    }

    // Wrapper for AJAX updates.
    $form['step_2_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'album-fields-wrapper'],
    ];

    if ($this->albumNode) {
      $form['step_2_wrapper'] = $this->buildAlbumConfigurationForm($form['step_2_wrapper']);
    }

    // Attach autocomplete libraries to the main form.
    $form['#attached'] = [
      'library' => [
        'core/drupal.autocomplete',
      ],
    ];

    return $form;
  }

  /**
   * Get album options for select widget.
   *
   * @param array $bundles
   *   Array of node bundle machine names (unused, kept for signature).
   *
   * @return array
   *   Array of node IDs keyed by node title.
   */
  protected function getAlbumOptions(array $bundles) {
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
              // Get all media in this field.
              foreach ($node->get($field_name) as $item) {
                $media_id = $item->target_id;
                if ($media_id) {
                  $media = $this->entityTypeManager->getStorage('media')->load($media_id);
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
    catch (\Exception $e) {
      \Drupal::logger('media_drop')
        ->warning('Error getting media bundles in node: @message', [
          '@message' => $e->getMessage(),
        ]);
    }

    return $bundles;
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
      $options = array_merge($options, $child_options);
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
    $this->usedDirectoriesCache = $this->getUsedDirectoriesInAlbum($this->albumNode);

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
    $all_options_with_state = $this->buildHierarchicalDirectoryOptionsWithState(
      $terms,
      $used_direct,
      $used_all
    );

    $unused_options = [];
    foreach ($all_options_with_state as $tid => $data) {
      if (!in_array($tid, $used_all)) {
        $unused_options[$tid] = $data['label'];
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
   * Build hierarchical directory options with state classification.
   *
   * @param array $terms
   *   Array of taxonomy terms.
   * @param array $used_direct
   *   Array of term IDs that directly contain media.
   * @param array $used_all
   *   Array of term IDs that contain media or are ancestors of those that do.
   * @param int $parent_id
   *   Parent term ID for recursion.
   * @param int $depth
   *   Current depth level.
   *
   * @return array
   *   Options array with state: [tid => ['label' => ..., 'state' => ...], ...]
   */
  protected function buildHierarchicalDirectoryOptionsWithState(array $terms, array $used_direct, array $used_all, $parent_id = 0, $depth = 0) {
    $options = [];
    $indent = str_repeat('– ', $depth);

    foreach ($terms as $term) {
      $parent = $term->get('parent');
      $term_parent_id = !empty($parent->target_id) ? $parent->target_id : 0;

      if ($term_parent_id != $parent_id) {
        continue;
      }

      $term_id = $term->id();
      $term_label = $term->label();

      // Determine state: direct > ancestor > unused.
      if (in_array($term_id, $used_direct)) {
        $state = 'direct';
      }
      elseif (in_array($term_id, $used_all)) {
        $state = 'ancestor';
      }
      else {
        $state = 'unused';
      }

      $options[$term_id] = [
        'label' => $indent . $term_label,
        'state' => $state,
      ];

      // Recursively add children.
      $child_options = $this->buildHierarchicalDirectoryOptionsWithState(
        $terms,
        $used_direct,
        $used_all,
        $term_id,
        $depth + 1
      );
      $options = array_merge($options, $child_options);
    }

    return $options;
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
  protected function getUsedDirectoriesInAlbum($node) {
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
              $medias = $this->entityTypeManager->getStorage('media')->loadMultiple($media_ids);

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
  protected function getAlbumEditableFields($node) {
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

      // Types of fields to exclude.
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
   * Build the album configuration form section.
   *
   * @param array $wrapper
   *   The wrapper form element to populate.
   *
   * @return array
   *   The populated wrapper element.
   */
  protected function buildAlbumConfigurationForm(array $wrapper) {
    if (!$this->albumNode) {
      return $wrapper;
    }

    $wrapper['step_2'] = [
      '#type' => 'details',
      '#title' => $this->t('Step 2: Configure Album Fields'),
      '#open' => TRUE,
    ];

    $wrapper['step_2']['info'] = [
      '#markup' => '<div class="messages messages--status">' .
      $this->t('Album: <strong>@album_title</strong>', ['@album_title' => $this->albumNode->label()]) .
      '</div>',
    ];

    // Show media compatibility info.
    $incompatible_media = $this->getIncompatibleMedia($this->albumNode);
    if (!empty($incompatible_media)) {
      $incompatible_list = '';
      foreach ($incompatible_media as $media) {
        $incompatible_list .= '<li>' . $media->label() . ' (' . $media->bundle() . ')</li>';
      }
      $wrapper['step_2']['incompatible_warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('<strong>These media will NOT be imported:</strong>') .
        '<ul>' . $incompatible_list . '</ul>' .
        '</div>',
      ];
    }

    // Directory selection (if media_directories is enabled).
    if (\Drupal::moduleHandler()->moduleExists('media_directories')) {
      $directory_element = $this->buildDirectorySelector();
      if ($directory_element) {
        $wrapper['step_2']['directory_tid'] = $directory_element;
      }
    }

    // Show album editable fields - grouped by designation.
    $grouped_album_fields = $this->getAlbumEditableFieldsGrouped($this->albumNode);

    if (!empty($grouped_album_fields)) {
      $wrapper['step_2']['album_fields'] = [
        '#type' => 'details',
        '#title' => $this->t('Media Type Fields (from Media Field)'),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      foreach ($grouped_album_fields as $designation_key => $field_group) {
        $field_config = $field_group['field_config'];
        $field_label = $field_group['designation'];
        $field_names = $field_group['field_names'];

        $wrapper['step_2']['album_fields'][$designation_key] = [
          '#type' => 'details',
          '#title' => $field_label,
          '#open' => FALSE,
        ];

        $default_value = $this->configuration['album_field_values'][$designation_key] ?? NULL;

        $wrapper['step_2']['album_fields'][$designation_key]['value'] = $this->buildFieldWidget(
          $field_config,
          $default_value
        );

        // Store the field names that belong to this designation for later processing.
        $wrapper['step_2']['album_fields'][$designation_key]['field_names'] = [
          '#type' => 'value',
          '#value' => $field_names,
        ];

        $field_names_display = implode(', ', $field_names);
        $wrapper['step_2']['album_fields'][$designation_key]['description'] = [
          '#markup' => '<p><em>' . $this->t('This value will be applied to all selected media (fields: @fields).', ['@fields' => $field_names_display]) . '</em></p>',
        ];
      }
    }

    return $wrapper;
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
  public static function ajaxUpdateAlbumFields(array $form, FormStateInterface $form_state) {
    // Get the action plugin to access instance methods.
    $action = new self([], 'media_drop_add_to_album', [], \Drupal::service('entity_type.manager'));

    // Get selected media from tempstore (previous step).
    $tempstore = \Drupal::service('tempstore.private')->get('views_bulk_operations');
    $action->selectedMedia = $tempstore->get('selected_media') ?? [];

    // Get selected album ID.
    $album_id = $form_state->getValue(['step_1', 'album_id']);

    if ($album_id) {
      // Load the album node.
      $action->albumNode = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($album_id);

      // Rebuild the album configuration form.
      if ($action->albumNode) {
        $wrapper = [
          '#type' => 'container',
          '#attributes' => ['id' => 'album-fields-wrapper'],
        ];
        $form['step_2_wrapper'] = $action->buildAlbumConfigurationForm($wrapper);
      }
    }

    return $form['step_2_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->configuration['album_id'] = $values['step_1']['album_id'] ?? NULL;
    $this->configuration['directory_tid'] = $values['step_1']['directory_tid'] ?? NULL;

    // Store media field values (description, alt).
    if (isset($values['step_2']['media_fields'])) {
      if (!isset($this->configuration['media_field_values'])) {
        $this->configuration['media_field_values'] = [];
      }
      $this->configuration['media_field_values'] = array_merge(
        $this->configuration['media_field_values'],
        $values['step_2']['media_fields']
      );
    }

    // Store album field values.
    if (isset($values['step_2']['album_fields'])) {
      if (!isset($this->configuration['album_field_values'])) {
        $this->configuration['album_field_values'] = [];
      }
      $this->configuration['album_field_values'] = array_merge(
        $this->configuration['album_field_values'],
        $values['step_2']['album_fields']
      );
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
        $this->t('Album node not found.')
      );
      return;
    }

    // Move to directory if configured.
    if ($this->configuration['directory_tid']) {
      if ($entity->hasField('directory')) {
        $entity->set('directory', ['target_id' => $this->configuration['directory_tid']]);
      }
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
      $current_values = $node->get($field_name)->getValue();
      $media_id = $media->id();

      $already_exists = FALSE;
      foreach ($current_values as $value) {
        if ($value['target_id'] == $media_id) {
          $already_exists = TRUE;
          break;
        }
      }

      if (!$already_exists) {
        $current_values[] = ['target_id' => $media_id];
        $node->set($field_name, $current_values);
      }
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
                if (is_array($value_to_set) && isset($value_to_set['target_id'])) {
                  $media->set($field_name, $value_to_set);
                }
                else {
                  // Handle "term_id|term_label" format from autocomplete.
                  $target_id = $value_to_set;
                  if (is_string($value_to_set) && strpos($value_to_set, '|') !== FALSE) {
                    [$target_id] = explode('|', $value_to_set, 2);
                  }
                  $media->set($field_name, ['target_id' => $target_id]);
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

    // Apply media field metadata (alt, description).
    if (isset($this->configuration['media_field_values'])) {
      foreach ($this->configuration['media_field_values'] as $field_name => $field_meta) {
        if (is_array($field_meta)) {
          if (isset($field_meta['alt']) && !empty($field_meta['alt']) && $media->hasField($field_name)) {
            $values = $media->get($field_name)->getValue();
            if (!empty($values)) {
              $values[0]['alt'] = $field_meta['alt'];
              $media->set($field_name, $values);
            }
          }

          if (isset($field_meta['description']) && !empty($field_meta['description']) && $media->hasField($field_name)) {
            $values = $media->get($field_name)->getValue();
            if (!empty($values)) {
              $values[0]['description'] = $field_meta['description'];
              $media->set($field_name, $values);
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

}
