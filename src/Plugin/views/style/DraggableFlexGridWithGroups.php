<?php

namespace Drupal\media_drop\Plugin\views\style;

use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * @ViewsStyle(
 *   id = "draggable_flexgrid_with_groups",
 *   title = @Translation("Draggable Flex Grid"),
 *   help = @Translation("Flexbox grid with drag & drop reordering"),
 *   theme = "media_drop_draggable_flexgrid_with_groups",
 *   display_types = {"normal", "page", "block"}
 * )
 */
class DraggableFlexGridWithGroups extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesFields = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['columns'] = ['default' => 4];
    $options['gap'] = ['default' => '20px'];
    $options['justify'] = ['default' => 'flex-start'];
    $options['align'] = ['default' => 'stretch'];
    $options['responsive'] = ['default' => TRUE];
    $options['field_groups'] = ['default' => []];
    $options['show_ungrouped'] = ['default' => TRUE];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    if (($this->view->id() == 'media_drop_manage') && ($this->view->current_display == 'page_1')) {
      $manage = TRUE;
    }
    else {
      $manage = FALSE;
    }

    $form['columns'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of columns'),
      '#default_value' => $this->options['columns'],
      '#min' => 1,
      '#max' => 12,
    ];

    $form['gap'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gap between items'),
      '#default_value' => $this->options['gap'],
      '#description' => $this->t('CSS gap value (e.g., 20px, 1rem, 2em)'),
    ];

    $form['justify'] = [
      '#type' => 'select',
      '#title' => $this->t('Justify content'),
      '#options' => [
        'flex-start' => $this->t('Flex start'),
        'flex-end' => $this->t('Flex end'),
        'center' => $this->t('Center'),
        'space-between' => $this->t('Space between'),
        'space-around' => $this->t('Space around'),
        'space-evenly' => $this->t('Space evenly'),
      ],
      '#default_value' => $this->options['justify'],
    ];

    $form['align'] = [
      '#type' => 'select',
      '#title' => $this->t('Align items'),
      '#options' => [
        'stretch' => $this->t('Stretch'),
        'flex-start' => $this->t('Flex start'),
        'flex-end' => $this->t('Flex end'),
        'center' => $this->t('Center'),
        'baseline' => $this->t('Baseline'),
      ],
      '#default_value' => $this->options['align'],
    ];

    $form['responsive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Responsive grid'),
      '#description' => $this->t('Automatically adjust columns based on screen size'),
      '#default_value' => $this->options['responsive'],
    ];

    // Récupérer tous les champs disponibles.
    $fields = $this->displayHandler->getHandlers('field');
    $field_options = [];
    foreach ($fields as $field_name => $field) {
      $field_options[$field_name] = $field->adminLabel();
    }

    // Section pour la configuration des groupes.
    $form['field_groups'] = [
      '#type' => 'details',
      '#title' => $this->t('Field Groups Configuration'),
      '#open' => TRUE,
      '#weight' => 10,
      '#tree' => TRUE,
    ];

    $form['field_groups']['description'] = [
      '#markup' => '<p>' . $this->t('Organize your fields into groups. Each group will be rendered in a separate container with its own CSS class.') . '</p>',
    ];

    // Créer 10 groupes configurables.
    $num_groups = 10;
    for ($i = 1; $i <= $num_groups; $i++) {
      $group_key = 'group_' . $i;

      if ($manage) {
        switch ($i) {
          case 1:
            $title = '1-' . $this->t('Thumbnail Field');
            break;

          case 2:
            $title = '2-' . $this->t('VBO Actions Field');
            break;

          case 3:
            $title = '3-' . $this->t('Name Field');
            break;

          case 4:
            $title = '4-' . $this->t('Media Details Fields');
            break;

          case 5:
            $title = '5-' . $this->t('Action Field');
            break;

          case 6:
            $title = '6-' . $this->t('Image Preview Field');
            break;

          default:
            $title = $this->t('Group @num', ['@num' => $i]);
            break;
        }
      }
      else {
        $title = $this->t('Group @num', ['@num' => $i]);
      }

      $form['field_groups'][$group_key] = [
        '#type' => 'details',
        '#title' => $title,
        '#open' => !empty($this->options['field_groups'][$group_key]['enabled']),
      ];

      $form['field_groups'][$group_key]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable this group'),
        '#default_value' => $this->options['field_groups'][$group_key]['enabled'] ?? FALSE,
      ];

      $form['field_groups'][$group_key]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Group label (optional)'),
        '#default_value' => $this->options['field_groups'][$group_key]['label'] ?? '',
        '#description' => $this->t('Leave empty for no label'),
        '#states' => [
          'visible' => [
            ':input[name="style_options[field_groups][' . $group_key . '][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['field_groups'][$group_key]['css_class'] = [
        '#type' => 'textfield',
        '#title' => $this->t('CSS class'),
        '#default_value' => $this->options['field_groups'][$group_key]['css_class'] ?? 'zone-' . $i,
        '#description' => $this->t('CSS class for this group container'),
        '#states' => [
          'visible' => [
            ':input[name="style_options[field_groups][' . $group_key . '][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['field_groups'][$group_key]['wrapper_element'] = [
        '#type' => 'select',
        '#title' => $this->t('Wrapper element'),
        '#options' => [
          'div' => 'div',
          'section' => 'section',
          'aside' => 'aside',
          'header' => 'header',
          'footer' => 'footer',
          'nav' => 'nav',
        ],
        '#default_value' => $this->options['field_groups'][$group_key]['wrapper_element'] ?? 'div',
        '#states' => [
          'visible' => [
            ':input[name="style_options[field_groups][' . $group_key . '][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      if (!empty($field_options)) {
        $form['field_groups'][$group_key]['fields'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Fields in this group'),
          '#options' => $field_options,
          '#default_value' => $this->options['field_groups'][$group_key]['fields'] ?? [],
          '#description' => $this->t('Select the fields to include in this group. Fields can only belong to one group.'),
          '#states' => [
            'visible' => [
              ':input[name="style_options[field_groups][' . $group_key . '][enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];
      }
    }

    $form['show_ungrouped'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show ungrouped fields'),
      '#default_value' => $this->options['show_ungrouped'] ?? TRUE,
      '#description' => $this->t('If checked, fields not assigned to any group will be displayed in an "ungrouped" container at the end.'),
      '#weight' => 100,
    ];

  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    parent::validateOptionsForm($form, $form_state);

    // Vérifier qu'un champ n'est pas dans plusieurs groupes.
    $field_groups = $form_state->getValue(['style_options', 'field_groups']);
    $assigned_fields = [];
    $duplicates = [];

    if ($field_groups) {
      foreach ($field_groups as $group_id => $group_config) {
        if (!empty($group_config['enabled']) && !empty($group_config['fields'])) {
          foreach ($group_config['fields'] as $field_id => $checked) {
            if ($checked) {
              if (isset($assigned_fields[$field_id])) {
                $duplicates[$field_id] = $field_id;
              }
              $assigned_fields[$field_id] = $group_id;
            }
          }
        }
      }
    }

    if (!empty($duplicates)) {
      $form_state->setError(
        $form['field_groups'],
        $this->t('The following fields are assigned to multiple groups: @fields. Each field can only belong to one group.',
          ['@fields' => implode(', ', $duplicates)]
        )
      );
    }
  }

  /**
   * Get all grouped fields.
   *
   * @return array
   *   Array of field IDs that are assigned to groups.
   */
  protected function getGroupedFields() {
    $grouped = [];
    if (!empty($this->options['field_groups'])) {
      foreach ($this->options['field_groups'] as $group_config) {
        if (!empty($group_config['enabled']) && !empty($group_config['fields'])) {
          foreach ($group_config['fields'] as $field_id => $checked) {
            if ($checked) {
              $grouped[] = $field_id;
            }
          }
        }
      }
    }
    return $grouped;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    // Récupérer les rows rendues par le row plugin.
    $rows = [];
    foreach ($this->view->result as $row_index => $row) {
      $this->view->row_index = $row_index;
      $rows[] = $this->view->rowPlugin->render($row);
    }
    unset($this->view->row_index);

    // Construire le tableau de rendu avec les options étendues.
    $build = [
      '#theme' => $this->themeFunctions(),
      // '#theme' => 'theme_perso',
      '#view' => $this->view,
      '#rows' => $rows,
      '#options' => $this->options,
      '#grouped_fields' => $this->getGroupedFields(),
    ];

    // Ajouter les librairies.
    $build['#attached']['library'][] = 'media_drop/draggable_flexgrid';

    // Ajouter les settings pour JavaScript.
    $build['#attached']['drupalSettings']['draggableFlexGrid'] = [
      'view_id' => $this->view->id(),
      'display_id' => $this->view->current_display,
    ];

    return $build;
  }

  /**
   * Get the media image URL for a given row index and field ID.
   * Used in the Twig template.
   *
   * @param int $index
   *   The row index.
   * @param string $field_id
   *   The field ID containing the image.
   * @param string|null $image_style
   *   (optional) The image style to apply.
   */
  public function getMediaImageUrl($index, $field_id, $image_style = NULL) {
    if (!isset($this->view->result[$index])) {
      return NULL;
    }

    $row = $this->view->result[$index];
    $entity = $row->_entity;

    // Vérifier que c'est bien une entité media.
    if ($entity->getEntityTypeId() !== 'media') {
      return NULL;
    }

    // Récupérer le champ source (généralement field_media_image ou thumbnail)
    $source_field = $entity->getSource()->getSourceFieldDefinition($entity->bundle->entity);
    $field_name = $source_field->getName();

    if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
      $file = $entity->get($field_name)->entity;

      if ($file) {
        $uri = $file->getFileUri();

        // Avec style d'image.
        if ($image_style) {
          $style = \Drupal::entityTypeManager()->getStorage('image_style')->load($image_style);
          return $style ? $style->buildUrl($uri) : NULL;
        }

        // URL directe.
        return \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
      }
    }

    return NULL;
  }

  /**
   * Get the media image URL for a given row index and field ID.
   *
   * Used in the Twig template.
   *
   * @param int $index
   *   The row index.
   * @param string $field_id
   *   The field ID containing the image.
   * @param string|null $image_style
   *   (optional) The image style to apply.
   */
  public function getMediaImageSize($index, $field_id, $image_style = NULL) {
    if (!isset($this->view->result[$index])) {
      return [0, 0];
    }

    $row = $this->view->result[$index];
    $entity = $row->_entity;

    // Vérifier que c'est bien une entité media.
    if ($entity->getEntityTypeId() !== 'media') {
      return [0, 0];
    }

    // Récupérer le champ source (généralement field_media_image ou thumbnail)
    $source_field = $entity->getSource()->getSourceFieldDefinition($entity->bundle->entity);
    $field_name = $source_field->getName();

    if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
      $file = $entity->get($field_name)->entity;

      if ($file) {
        $rpath = \Drupal::service('file_system')->realpath($file->getFileUri());
        if (!empty($rpath) && file_exists($rpath)) {
          $image_info = getimagesize($rpath);
          return [
            'width' => $image_info[0],
            'height' => $image_info[1],
          ];
        }
        else {
          return [0, 0];
        }
      }
      else {
        return [0, 0];
      }
    }
    return [0, 0];
  }

  /**
   * Retourne toutes les informations pertinentes d'un media.
   *
   * @param int $index
   *   L'index de la ligne dans la vue.
   * @param string|null $image_style
   *   (optionnel) Nom du style d'image si applicable.
   *
   * @return array
   *   Tableau contenant les informations du media, ou vide si inexistant.
   */
  public function getMediaFullInfo($index, $image_style = NULL) {
    if (!isset($this->view->result[$index])) {
      return [];
    }

    $row = $this->view->result[$index];
    $entity = $row->_entity;

    // Vérifier que c'est bien une entité media.
    if ($entity->getEntityTypeId() !== 'media') {
      return [];
    }

    $info = [];
    $info['bundle'] = $entity->bundle();
    $info['id'] = $entity->id();
    $info['label'] = $entity->label();

    // Source field (généralement field_media_image ou thumbnail)
    $source_field_def = $entity->getSource()->getSourceFieldDefinition($entity->bundle->entity);
    $field_name = $source_field_def->getName();

    // Récupérer l'entité fichier si existante.
    if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
      $file = $entity->get($field_name)->entity;

      if ($file) {
        // Chemin réel sur le disque.
        $real_path = \Drupal::service('file_system')->realpath($file->getFileUri());
        $info['file_path'] = $real_path;

        $info['file_uri'] = $file->getFileUri();

        // URL publique.
        // URL publique si le fichier est dans public://.
        if (\Drupal::service('stream_wrapper_manager')->getScheme($info['file_uri']) === 'public') {
          $info['url'] = \Drupal::service('file_url_generator')->generateString($info['file_uri']);
        }
        // Fichier privé : URL via le route system/files.
        elseif (\Drupal::service('stream_wrapper_manager')->getScheme($info['file_uri']) === 'private') {
          $info['url'] = Url::fromRoute('system.files', ['file' => $file->getFilename()], ['absolute' => TRUE])->toString();
        }

        // Taille de l'image si applicable.
        if (file_exists($real_path)) {
          $image_info = getimagesize($real_path);
          $info['width'] = $image_info[0];
          $info['height'] = $image_info[1];
        }
        else {
          $info['width'] = 0;
          $info['height'] = 0;
        }

        // Infos supplémentaires du fichier.
        $info['file_name'] = $file->getFilename();
        $info['mime_type'] = $file->getMimeType();
        $info['size_bytes'] = $file->getSize();

        // ALT et description si disponible.
        if ($entity->hasField('field_media_image') && !$entity->get('field_media_image')->isEmpty()) {
          $media_field = $entity->get('field_media_image')->first();
          $info['alt'] = $media_field->alt ?? '';
          $info['description'] = $media_field->title ?? '';
        }
      }
    }

    // Tous les champs textuels disponibles.
    foreach ($entity->getFields() as $field_name => $field) {
      if ($field->getFieldDefinition()->getType() === 'string' || $field->getFieldDefinition()->getType() === 'text_long') {
        $info['fields'][$field_name] = $entity->get($field_name)->value;
      }
    }

    return $info;
  }

}
