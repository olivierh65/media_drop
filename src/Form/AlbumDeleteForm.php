<?php

namespace Drupal\media_drop\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Album deletion confirmation form.
 */
class AlbumDeleteForm extends ConfirmFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The album to delete.
   *
   * @var object
   */
  protected $album;

  /**
   * Constructs a new AlbumDeleteForm.
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
    return 'media_drop_album_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $album_id = NULL) {
    $this->album = $this->database->select('media_drop_albums', 'a')
      ->fields('a')
      ->condition('id', $album_id)
      ->execute()
      ->fetchObject();

    if (!$this->album) {
      $this->messenger()->addError($this->t('Album not found.'));
      return $this->redirect('media_drop.album_list');
    }

    // Count associated uploads.
    $upload_count = $this->database->select('media_drop_uploads', 'u')
      ->condition('album_id', $this->album->id)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($upload_count > 0) {
      $form['warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('Warning: this album contains @count media item(s). The media themselves will not be deleted, but the links with this album will be lost.', [
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
    return $this->t('Are you sure you want to delete the album %name?', [
      '%name' => $this->album->name,
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
    return new Url('media_drop.album_list');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Delete upload entries.
    $this->database->delete('media_drop_uploads')
      ->condition('album_id', $this->album->id)
      ->execute();

    // Delete the album.
    $this->database->delete('media_drop_albums')
      ->condition('id', $this->album->id)
      ->execute();

    $this->messenger()->addStatus($this->t('The album %name has been deleted.', [
      '%name' => $this->album->name,
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
