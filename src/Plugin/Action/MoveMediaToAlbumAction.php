<?php

namespace Drupal\media_drop\Plugin\Action;

use Drupal\media_album_av_common\Service\DirectoryService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\MessageCommand;

/**
 * Moves media entities to an album node with optional directory and field values.
 *
 * @Action(
 *   id = "media_drop_move_to_album",
 *   label = @Translation("Move to Album"),
 *   type = "media",
 *   category = @Translation("Media Drop"),
 *   confirm = TRUE,
 *   configurable = TRUE,
 * )
 */
class MoveMediaToAlbumAction extends BaseAlbumAction {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, DirectoryService $taxonomy_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $taxonomy_service);

    $this->move = TRUE;
  }

  /**
   *
   */
  public function executeMultiple(array $data) {
    // No specific order - process as is.
    $skipped = 0;
    $done = 0;
    $no_entity = 0;
    $no_media = 0;
    $no_album = 0;
    $moved_ids = [];

    foreach ($data['entities'] as $entity) {
      switch ($this->execute($data['album_id'], $entity)) {
        case 'skipped':
          $skipped++;
          break;

        case 'done':
          $moved_ids[] = $entity->id();
          $done++;
          break;

        case 'no_entity':
          $no_entity++;
          break;

        case 'no_media':
          $no_media++;
          break;

        case 'no_album':
          $no_album++;
          break;
      }
    }
    $response[] = new MessageCommand(
    $this->t('Action completed: @done moved, @skipped skipped, @no_entity with no entity, @no_media with non-media entity, @no_album with no album.', [
      '@done' => $done,
      '@skipped' => $skipped,
      '@no_entity' => $no_entity,
      '@no_media' => $no_media,
      '@no_album' => $no_album,
    ]), NULL, ['type' => $done > 0 ? 'status' : ($skipped > 0 ? 'warning' : 'error')]
    );

    if ($done > 0) {
      return [
        'status' => 'success',
        'response' => $response,
        'moved_ids' => $moved_ids,
      ];
    }
    elseif ($skipped > 0) {
      return [
        'status' => 'warning',
        'response' => $response,
        'moved_ids' => $moved_ids,
      ];
    }
    else {
      return [
        'status' => 'error',
        'response' => $response,
        'moved_ids' => $moved_ids,
      ];
    }

  }

  /**
   * {@inheritdoc}
   */
  public function execute($album_id = NULL, $entity = NULL) {
    if (!$entity) {
      return 'no_entity';
    }

    if ($entity->getEntityTypeId() !== 'media') {
      return 'no_media';
    }

    if (!$this->albumNode) {
      $this->albumNode = $this->entityTypeManager
        ->getStorage('node')
        ->load($album_id);
    }

    if (!$this->albumNode) {
      \Drupal::messenger()->addError(
      $this->t('album node not found.')
      );
      return 'no_album';
    }

    // Check if media is already in the album - skip if it is.
    $existing_media = $this->getMediaIdsInAlbum($this->albumNode);
    $media_id = $entity->id();
    if (isset($existing_media[$media_id])) {
      \Drupal::logger('media_drop')->info('Skipping media @mid - already in album', [
        '@mid' => $media_id,
      ]);
      return 'skipped';
    }

    // Move to directory if configured (including ROOT which is NULL in database, 0 internally).
    if (isset($this->configuration['directory_tid']) && $this->configuration['directory_tid'] !== NULL && $this->configuration['directory_tid'] !== '') {

      if ($entity->hasField('directory')) {
        // Set directory field. For ROOT (0 or -1), convert to NULL for database storage.
        if ($this->configuration['directory_tid'] == 0 || $this->configuration['directory_tid'] == -1) {
          // ROOT directory - set as NULL in database.
          $entity->set('directory', NULL);
        }
        else {
          // Regular directory - set the target_id.
          $entity->set('directory', $this->configuration['directory_tid']);
        }
      }
      else {
        \Drupal::logger('media_drop')->warning('execute() - Media @mid does not have directory field', [
          '@mid' => $entity->id(),
        ]);
      }

      // Move the physical files to the corresponding directory.
      $this->taxonomyService->moveMediaFilesToDirectory($entity, $this->configuration['directory_tid'], TRUE);
    }
    else {
      \Drupal::logger('media_drop')->notice('execute() - No directory_tid configured (value: @val)', [
        '@val' => var_export($this->configuration['directory_tid'], TRUE),
      ]);
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
    if (!empty($this->configuration['grouped_media_fields'])) {
      $this->applyFieldValuesToMedia($entity);
    }

    $entity->save();

    if ($media_field_found) {
      $this->albumNode->save();
    }
    return 'done';
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;

    $user_input = $form_state->getUserInput();
    $action_id = $user_input['action_id'] ?? NULL;
    $album_grp = $user_input['album_grp'] ?? NULL;
    $prepared_data = json_decode($user_input['prepared_data'] ?? '[]', TRUE);
    $action_data_flag = $user_input['action_data_flag'] ?? NULL;

    $form['action_id'] = [
      '#type' => 'hidden',
      '#value' => $action_id,
    ];
    $form['album_grp'] = [
      '#type' => 'hidden',
      '#value' => $album_grp,
    ];
    $form['prepared_data'] = [
      '#type' => 'hidden',
      '#value' => is_array($prepared_data) ? json_encode($prepared_data) : $prepared_data,
    ];
    $form['action_data_flag'] = [
      '#type' => 'hidden',
    // Just a flag to indicate data is initialized.
      '#value' => $action_data_flag,
    ];

    $form['album_defaults'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue(['album_defaults']) ?? '',
    ];

    // Step 1: Select an Album and Directory.
    $form['step_1'] = [
      '#type' => 'details',
      '#title' => $this->t('Step 1: Select an Album and Directory'),
      '#open' => TRUE,
      '#tree' => TRUE,
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
      '#default_value' => '',
      '#disabled' => FALSE,
      '#parents' => ['step_1', 'album_id'],
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
      '#tree' => TRUE,
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
        $form['step_2_wrapper']['step_2'] = $this->buildAlbumConfigurationForm($form_state, []);
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

    return $form;
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
   *   The updated step_2 form element.
   */
  public function ajaxUpdateAlbumFields(array $form, FormStateInterface $form_state) {
    // Force form rebuild so Drupal recalculates all form values.
    $form_state->setRebuild(TRUE);

    // Return the wrapper (which contains step_2).
    return $form['step_2_wrapper'];
  }

  /**
   * Build the album configuration form section.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $wrapper
   *   Unused, kept for compatibility.
   *
   * @return array
   *   The step_2 element ready to add to main form.
   */
  protected function buildAlbumConfigurationForm(FormStateInterface $form_state, array $wrapper) {
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

    $album_defaults = $this->getAlbumDefaults($this->albumNode->id());
    $form_state->setValue(['album_defaults'], json_encode($album_defaults));

    if ($this->move) {
      // Directory selection (if media_directories is enabled).
      if (\Drupal::moduleHandler()->moduleExists('media_directories')) {
        $directory_element = $this->buildDirectorySelector($album_defaults['album_prefered_directory_tid'] ?? -1);
        if ($directory_element) {
          $step_2['directory_tid'] = $directory_element;
        }
      }
    }
    // Show album editable fields - grouped by designation.
    $grouped_media_fields = $this->getAlbumEditableFieldsGrouped($this->albumNode);

    if (!empty($grouped_media_fields)) {
      $step_2['grouped_media_fields'] = [
        '#type' => 'details',
        '#title' => $this->t('Media Type Fields (from Media Field)'),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      foreach ($grouped_media_fields as $designation_key => $field_group) {
        $field_config = $field_group['field_config'];
        $field_label = $field_group['designation'];
        $field_names = $field_group['field_names'];

        $step_2['grouped_media_fields'][$designation_key] = [
          '#type' => 'details',
          '#title' => $field_label,
          '#open' => FALSE,
        ];

        if (($this->albumNode->hasField($field_group['field_names'][0])) &&
        (!empty($this->albumNode->get($field_group['field_names'][0])->first()))) {
          $default_from_node = $this->albumNode->get($field_group['field_names'][0])->first()->get('target_id')->getValue();
        }
        else {
          $default_from_node = NULL;
        }
        $default_value = $this->configuration['grouped_media_fields'][$designation_key] ??
          $default_from_node ?? NULL;

        $step_2['grouped_media_fields'][$designation_key]['value'] = $this->buildFieldWidget(
        $field_config,
        $default_value
        );

        // Store the field names that belong to this designation for later processing.
        $step_2['grouped_media_fields'][$designation_key]['field_names'] = [
          '#type' => 'value',
          '#value' => $field_names,
        ];

        $field_names_display = implode(', ', $field_names);
        $step_2['grouped_media_fields'][$designation_key]['description'] = [
          '#markup' => '<p><em>' . $this->t('This value will be applied to all selected media (fields: @fields).', ['@fields' => $field_names_display]) . '</em></p>',
        ];

        $widget = $this->buildFieldWidget($field_config, $default_value);

        // Ajouter #autocreate uniquement sur les taxonomies "libres".
        if ($field_config->getType() === 'entity_reference') {
          $target_type = $field_config->getSetting('target_type');
          if ($target_type === 'taxonomy_term') {
            $handler_settings = $field_config->getSetting('handler_settings') ?? [];
            $target_bundles = $handler_settings['target_bundles'] ?? [];
            $vocabulary_id = !empty($target_bundles) ? reset(array_keys($target_bundles)) : NULL;

            if ($vocabulary_id && $this->isAutocreateVocabulary($vocabulary_id, $field_config->getName())) {
              $widget['#autocreate'] = ['bundle' => $vocabulary_id];
            }
          }
        }

        $step_2['grouped_media_fields'][$designation_key]['value'] = $widget;
      }
    }

    return $step_2;
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
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $this->configuration['album_id'] = $values['step_1']['album_id'] ?? NULL;

    // Get directory_tid from step_2 inside wrapper.
    $directory_tid = NULL;
    if (isset($values['step_2_wrapper']['step_2']) && is_array($values['step_2_wrapper']['step_2'])) {
      $directory_tid = $values['step_2_wrapper']['step_2']['directory_tid'] ?? NULL;
      // Ensure directory_tid is an integer (not a string).
      if ($directory_tid !== NULL && $directory_tid !== '') {
        $directory_tid = (int) $directory_tid;
      }
    }
    $this->configuration['directory_tid'] = $directory_tid;

    // Store album field values and create missing autocreate terms.
    if (isset($values['step_2_wrapper']['step_2']['grouped_media_fields'])) {
      if (!isset($this->configuration['grouped_media_fields'])) {
        $this->configuration['grouped_media_fields'] = [];
      }

      // Process grouped media fields and create autocreate terms if needed.
      $processed_fields = $this->processAutocreateTerms($values['step_2_wrapper']['step_2']['grouped_media_fields']);

      $this->configuration['grouped_media_fields'] = array_merge(
        $this->configuration['grouped_media_fields'],
        $processed_fields
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
   * Build the directory selector element.
   *
   * @param int $prefered_directory_tid
   *   The preferred directory term ID from album. -1 if not defined.
   *
   * @return array
   *   Form element array for directory selection.
   */
  protected function buildDirectorySelector($prefered_directory_tid = -1) {
    $config = \Drupal::config('media_directories.settings');
    $vocabulary_id = $config->get('directory_taxonomy');

    if (!$vocabulary_id) {
      return NULL;
    }

    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabulary_id]);

    // Get directories already used in the album and cache them.
    $this->usedDirectoriesCache = $this->getUsedDirectoriesInDepot($this->albumNode);

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

    // Track preferred directory separately (may or may not be in $used_direct).
    $is_preferred_in_use = FALSE;
    if ($prefered_directory_tid !== -1 && $prefered_directory_tid !== NULL) {
      $is_preferred_in_use = in_array($prefered_directory_tid, $used_direct);
      // Add preferred directory to used_direct to include it in the "used" section.
      if (!in_array($prefered_directory_tid, $used_direct)) {
        $used_direct[] = $prefered_directory_tid;
      }
    }

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
          // Use == because some terms are stored as int and some as string, depending on how they are loaded.
          $term_label = ($chain_tid == 0) ? 'Root (no directory)' : $terms_by_id[$chain_tid]->label();

          // Determine markers for this directory:
          // ★ = actually used (contains media)
          // ◆ = preferred directory
          // ★◆ = both used and preferred.
          $markers = '';
          if (in_array($chain_tid, $this->usedDirectoriesCache)) {
            $markers .= '★ ';
          }
          if ($chain_tid == $prefered_directory_tid && $prefered_directory_tid != -1) {
            $markers .= '◆ ';
          }

          if ($markers) {
            $label = $indent . $markers . $term_label;
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

    // Handle ROOT specially if it's used or preferred.
    $root_markers = '';
    if (in_array(0, $this->usedDirectoriesCache)) {
      $root_markers .= '★ ';
    }
    if ($prefered_directory_tid == 0) {
      $root_markers .= '◆ ';
    }

    if ($root_markers) {
      $root_label = '– ' . $root_markers . 'Root (no directory)';
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
      $options[(string) $this->t('→ Currently used directories (★) / Preferred (◆)')] = $used_options;
    }

    if (!empty($unused_options)) {
      $options[(string) $this->t('→ Other directories')] = $unused_options;
    }

    // Determine default value: preferred directory if set, otherwise from configuration.
    $default_value = 0;
    if ($prefered_directory_tid != -1 && $prefered_directory_tid != NULL) {
      $default_value = $prefered_directory_tid;
    }
    else {
      $default_value = $this->configuration['directory_tid'] ?? 0;
    }

    return [
      '#type' => 'select',
      '#title' => $this->t('Move to directory'),
      '#options' => $options,
      '#default_value' => $default_value,
      '#description' => $this->t('Optionally move the selected media to this directory. Directories marked with ★ are currently used in this depot. Directories marked with ◆ are the album\'s preferred directory. Indentation shows the directory hierarchy.'),
    ];
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
   * Get all media IDs already present in the depot node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The depot node.
   *
   * @return array
   *   Array of media IDs with their labels, indexed by media ID.
   */
  protected function getMediaIdsInDepot($node) {
    $existing_media = [];

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
              $media_storage = $this->entityTypeManager->getStorage('media');
              foreach ($node->get($field_name) as $item) {
                $media_id = $item->target_id;
                if ($media_id) {
                  $latest_revision_id = $media_storage->getLatestRevisionId($media_id);
                  if ($latest_revision_id) {
                    $media = $media_storage->loadRevision($latest_revision_id);
                    if ($media) {
                      $existing_media[$media_id] = $media->label();
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
        ->warning('Error getting media IDs in depot: @message', [
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
   * Get directories already used by media in the depot node.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The depot node.
   *
   * @return array
   *   Array of directory taxonomy term IDs.
   */
  protected function getUsedDirectoriesInDepot($node) {
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

    \Drupal::logger('media_drop')->notice('Found used directories in depot @nid: @dirs', [
      '@nid' => $node->id(),
      '@dirs' => implode(', ', $directories),
    ]);

    return $directories;
  }

  /**
   *
   */
  protected function isAutocreateVocabulary(string $vocabulary_id, string $field_name): bool {
    $config = \Drupal::config('media_album_av.settings');

    // Parcourir tous les media types configurés.
    $media_types = $this->entityTypeManager
      ->getStorage('media_type')
      ->loadMultiple();

    foreach ($media_types as $media_type_id => $media_type) {
      $category_config = $config->get('category_fields.' . $media_type_id);
      if (!$category_config) {
        continue;
      }

      // Si ce field_name correspond au champ catégorie configuré
      // pour ce media type → retourner le flag autocreate.
      if (($category_config['field_name'] ?? '') === $field_name) {
        return (bool) ($category_config['autocreate'] ?? FALSE);
      }
    }

    // Par défaut : pas d'autocreate.
    return FALSE;
  }

}
