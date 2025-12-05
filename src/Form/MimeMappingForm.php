<?php

namespace Drupal\media_drop\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for MIME type to Media type mappings.
 */
class MimeMappingForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new MimeMappingForm.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_drop_mime_mapping_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get all available media types.
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    $media_type_options = [];
    foreach ($media_types as $media_type) {
      $media_type_options[$media_type->id()] = $media_type->label();
    }

    // Get existing mappings.
    $mappings = $this->database->select('media_drop_mime_mapping', 'm')
      ->fields('m')
      ->orderBy('weight')
      ->orderBy('mime_type')
      ->execute()
      ->fetchAll();

    $form['#tree'] = TRUE;
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure which Drupal media types will be created based on the MIME types of uploaded files. Custom media types are also available.') . '</p>',
    ];

    $form['mappings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('MIME Type'),
        $this->t('Drupal Media Type'),
        $this->t('Weight'),
        $this->t('Delete'),
      ],
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'mapping-weight',
        ],
      ],
      '#empty' => $this->t('No mappings configured.'),
    ];

    foreach ($mappings as $mapping) {
      $id = $mapping->id;

      $form['mappings'][$id]['#attributes']['class'][] = 'draggable';

      $form['mappings'][$id]['mime_type'] = [
        '#type' => 'textfield',
        '#default_value' => $mapping->mime_type,
        '#size' => 30,
        '#maxlength' => 128,
        '#required' => TRUE,
      ];

      $form['mappings'][$id]['media_type'] = [
        '#type' => 'select',
        '#options' => $media_type_options,
        '#default_value' => $mapping->media_type,
        '#required' => TRUE,
      ];

      $form['mappings'][$id]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight'),
        '#title_display' => 'invisible',
        '#default_value' => $mapping->weight,
        '#attributes' => ['class' => ['mapping-weight']],
      ];

      $form['mappings'][$id]['delete'] = [
        '#type' => 'checkbox',
        '#default_value' => 0,
      ];

      $form['mappings'][$id]['id'] = [
        '#type' => 'hidden',
        '#value' => $id,
      ];
    }

    // Section to add a new mapping.
    $form['new_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Add a new mapping'),
      '#open' => FALSE,
    ];

    $form['new_mapping']['new_mime_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('MIME Type'),
      '#description' => $this->t('Example: image/heic, video/mov'),
      '#size' => 30,
      '#maxlength' => 128,
    ];

    $form['new_mapping']['new_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal Media Type'),
      '#options' => ['' => $this->t('- Select -')] + $media_type_options,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $new_mime_type = $form_state->getValue(['new_mapping', 'new_mime_type']);
    $new_media_type = $form_state->getValue(['new_mapping', 'new_media_type']);

    // If a new mapping is being added, check that it is complete.
    if (!empty($new_mime_type) && empty($new_media_type)) {
      $form_state->setErrorByName('new_mapping][new_media_type',
        $this->t('Please select a media type.'));
    }
    if (empty($new_mime_type) && !empty($new_media_type)) {
      $form_state->setErrorByName('new_mapping][new_mime_type',
        $this->t('Please enter a MIME type.'));
    }

    // Check that the new MIME type does not already exist.
    if (!empty($new_mime_type)) {
      $exists = $this->database->select('media_drop_mime_mapping', 'm')
        ->condition('mime_type', $new_mime_type)
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($exists) {
        $form_state->setErrorByName('new_mapping][new_mime_type',
          $this->t('This MIME type already exists.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mappings = $form_state->getValue('mappings');

    if ($mappings) {
      foreach ($mappings as $id => $mapping) {
        if ($mapping['delete']) {
          // Delete.
          $this->database->delete('media_drop_mime_mapping')
            ->condition('id', $id)
            ->execute();
        }
        else {
          // Update.
          $this->database->update('media_drop_mime_mapping')
            ->fields([
              'mime_type' => $mapping['mime_type'],
              'media_type' => $mapping['media_type'],
              'weight' => $mapping['weight'],
            ])
            ->condition('id', $id)
            ->execute();
        }
      }
    }

    // Add a new mapping if necessary.
    $new_mime_type = $form_state->getValue(['new_mapping', 'new_mime_type']);
    $new_media_type = $form_state->getValue(['new_mapping', 'new_media_type']);

    if (!empty($new_mime_type) && !empty($new_media_type)) {
      $this->database->insert('media_drop_mime_mapping')
        ->fields([
          'mime_type' => $new_mime_type,
          'media_type' => $new_media_type,
          'weight' => 0,
        ])
        ->execute();
    }

    $this->messenger()->addStatus($this->t('The mappings have been saved.'));
  }

}
