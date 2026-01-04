<?php

namespace Drupal\media_drop\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Depot deletion confirmation form.
 */
class DepotDeleteForm extends ConfirmFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The depot to delete.
   *
   * @var object
   */
  protected $depot;

  /**
   * Constructs a new DepotDeleteForm.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_drop_depot_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $depot_id = NULL) {
    $this->depot = $this->database->select('media_drop_depots', 'a')
      ->fields('a')
      ->condition('id', $depot_id)
      ->execute()
      ->fetchObject();

    if (!$this->depot) {
      $this->messenger()->addError($this->t('Depot not found.'));
      return $this->redirect('media_drop.depot_list');
    }

    // Count associated uploads.
    $upload_count = $this->database->select('media_drop_uploads', 'u')
      ->condition('depot_id', $this->depot->id)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($upload_count > 0) {
      $form['warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('Warning: this depot contains @count media item(s). The media themselves will not be deleted, but the links with this depot will be lost.', [
          '@count' => $upload_count,
        ]) .
        '</div>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the depot %name?', [
      '%name' => $this->depot->name,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action cannot be undone. The base directory and files will not be deleted.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('media_drop.depot_list');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Delete upload entries.
    $this->database->delete('media_drop_uploads')
      ->condition('depot_id', $this->depot->id)
      ->execute();

    // Delete the depot.
    $this->database->delete('media_drop_depots')
      ->condition('id', $this->depot->id)
      ->execute();

    $this->messenger()->addStatus($this->t('The depot %name has been deleted.', [
      '%name' => $this->depot->name,
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
