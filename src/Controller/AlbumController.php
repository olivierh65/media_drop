<?php

namespace Drupal\media_drop\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the album list.
 */
class AlbumController extends ControllerBase {

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
   * Constructs a new AlbumController.
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
   * Lists all albums.
   */
  public function listAlbums() {
    $albums = $this->database->select('media_drop_albums', 'a')
      ->fields('a')
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();

    $rows = [];
    foreach ($albums as $album) {
      $url = \Drupal::request()->getSchemeAndHttpHost() . '/media-drop/' . $album->token;

      // Count uploads.
      $upload_count = $this->database->select('media_drop_uploads', 'u')
        ->condition('album_id', $album->id)
        ->countQuery()
        ->execute()
        ->fetchField();

      $rows[] = [
        'name' => $album->name,
        'directory' => $album->base_directory,
        'media_types' => [
          'data' => [
            '#markup' => $this->formatMediaTypes($album),
          ],
        ],
        'url' => [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'code',
            '#value' => $url,
          ],
        ],
        'uploads' => $upload_count,
        'status' => $album->status ? $this->t('Active') : $this->t('Inactive'),
        'created' => \Drupal::service('date.formatter')->format($album->created, 'short'),
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('media_drop.album_edit', ['album_id' => $album->id]),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => Url::fromRoute('media_drop.album_delete', ['album_id' => $album->id]),
              ],
            ],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Directory'),
        $this->t('Media types'),
        $this->t('Drop URL'),
        $this->t('Uploads'),
        $this->t('Status'),
        $this->t('Created'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No albums created yet. @link', [
        '@link' => Link::fromTextAndUrl(
          $this->t('Create an album'),
          Url::fromRoute('media_drop.album_add')
        )->toString(),
      ]),
    ];

    $build['add_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Add album'),
      '#url' => Url::fromRoute('media_drop.album_add'),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'button--small'],
      ],
      '#prefix' => '<div class="action-links">',
      '#suffix' => '</div>',
    ];

    return $build;
  }

  /**
   * Formats the display of media types for an album.
   */
  protected function formatMediaTypes($album) {
    $types = [];

    if (!empty($album->default_media_type)) {
      $media_type = $this->entityTypeManager->getStorage('media_type')->load($album->default_media_type);
      if ($media_type) {
        $types[] = '<strong>' . $this->t('Images') . ':</strong> ' . $media_type->label();
      }
    }
    else {
      $types[] = '<em>' . $this->t('Images: default mapping') . '</em>';
    }

    if (!empty($album->video_media_type)) {
      $media__type = $this->entityTypeManager->getStorage('media_type')->load($album->video_media_type);
      if ($media_type) {
        $types[] = '<strong>' . $this->t('Videos') . ':</strong> ' . $media_type->label();
      }
    }
    else {
      $types[] = '<em>' . $this->t('Videos: default mapping') . '</em>';
    }

    // Add Media Directories directory if defined.
    if (!empty($album->media_directory) && \Drupal::moduleHandler()->moduleExists('media_directories')) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($album->media_directory);
      if ($term) {
        $types[] = '<strong>' . $this->t('Directory') . ':</strong> ' . $term->getName();
      }
    }

    return implode('<br>', $types);
  }

}
