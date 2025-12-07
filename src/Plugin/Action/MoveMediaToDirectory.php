<?php

namespace Drupal\media_drop\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Moves media entities to a Media Directories directory.
 *
 * @Action(
 *   id = "media_drop_move_to_directory",
 *   label = @Translation("Move to directory"),
 *   type = "media",
 *   category = @Translation("Media Drop")
 * )
 */
class MoveMediaToDirectory extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a MoveMediaToDirectory object.
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
      'directory_tid' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Check if media_directories is enabled.
    if (!\Drupal::moduleHandler()->moduleExists('media_directories')) {
      $form['warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('The Media Directories module must be enabled to use this action.') .
        '</div>',
      ];
      return $form;
    }

    // Get the Media Directories taxonomy.
    $config = \Drupal::config('media_directories.settings');
    $vocabulary_id = $config->get('directory_taxonomy');

    if (!$vocabulary_id) {
      $form['warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('No taxonomy configured in Media Directories.') .
        '</div>',
      ];
      return $form;
    }

    // Load terms.
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, 0, NULL, TRUE);

    $options = [0 => $this->t('- Root (no directory) -')];
    foreach ($terms as $term) {
      $prefix = str_repeat('--', $term->depth);
      $options[$term->id()] = $prefix . ' ' . $term->getName();
    }

    $form['directory_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('Destination directory'),
      '#options' => $options,
      '#default_value' => $this->configuration['directory_tid'],
      '#required' => TRUE,
      '#description' => $this->t('The selected media will be <strong>moved</strong> (not copied) to this directory.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['directory_tid'] = $form_state->getValue('directory_tid');
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity) {
      return;
    }

    // Make sure it is a media entity.
    if ($entity->getEntityTypeId() !== 'media') {
      return;
    }

    $directory_tid = $this->configuration['directory_tid'];

    // If the directory is 0, we clear the directory field.
    if ($directory_tid == 0) {
      if ($entity->hasField('directory')) {
        $entity->set('directory', NULL);
      }
    }
    else {
      // Otherwise, set the new directory.
      if ($entity->hasField('directory')) {
        $entity->set('directory', ['target_id' => $directory_tid]);
      }
      else {
        \Drupal::messenger()->addWarning(
          $this->t('The media @name does not have a "directory" field.', [
            '@name' => $entity->label(),
          ])
        );
        return;
      }
    }

    $entity->save();
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
