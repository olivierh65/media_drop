<?php

namespace Drupal\media_drop\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the depot list.
 */
class DepotController extends ControllerBase {

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
   * Constructs a new DepotController.
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
   * Lists all depots.
   */
  public function listDepots() {
    $depots = $this->database->select('media_drop_depots', 'a')
      ->fields('a')
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();

    $rows = [];
    foreach ($depots as $depot) {
      $url = \Drupal::request()->getSchemeAndHttpHost() . '/media-drop/' . $depot->token;

      // Count uploads.
      $upload_count = $this->database->select('media_drop_uploads', 'u')
        ->condition('depot_id', $depot->id)
        ->countQuery()
        ->execute()
        ->fetchField();

      $rows[] = [
        'name' => $depot->name,
        'directory' => $depot->base_directory,
        'media_types' => [
          'data' => [
            '#markup' => $this->formatMediaTypes($depot),
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
        'status' => $depot->status ? $this->t('Active') : $this->t('Inactive'),
        'created' => \Drupal::service('date.formatter')->format($depot->created, 'short'),
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('media_drop.depot_edit', ['depot_id' => $depot->id]),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => Url::fromRoute('media_drop.depot_delete', ['depot_id' => $depot->id]),
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
      '#empty' => $this->t('No depots created yet. @link', [
        '@link' => Link::fromTextAndUrl(
          $this->t('Create a depot'),
          Url::fromRoute('media_drop.depot_add')
        )->toString(),
      ]),
    ];

    $build['add_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Add depot'),
      '#url' => Url::fromRoute('media_drop.depot_add'),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'button--small'],
      ],
      '#prefix' => '<div class="action-links">',
      '#suffix' => '</div>',
    ];

    return $build;
  }

  /**
   * Formats the display of media types for a depot.
   */
  protected function formatMediaTypes($depot) {
    $types = [];

    if (!empty($depot->default_media_type)) {
      $media_type = $this->entityTypeManager->getStorage('media_type')->load($depot->default_media_type);
      if ($media_type) {
        $types[] = '<strong>' . $this->t('Images') . ':</strong> ' . $media_type->label();
      }
    }
    else {
      $types[] = '<em>' . $this->t('Images: default mapping') . '</em>';
    }

    if (!empty($depot->video_media_type)) {
      $media__type = $this->entityTypeManager->getStorage('media_type')->load($depot->video_media_type);
      if ($media_type) {
        $types[] = '<strong>' . $this->t('Videos') . ':</strong> ' . $media_type->label();
      }
    }
    else {
      $types[] = '<em>' . $this->t('Videos: default mapping') . '</em>';
    }

    // Add Media Directories directory if defined.
    if (!empty($depot->media_directory) && \Drupal::moduleHandler()->moduleExists('media_directories')) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($depot->media_directory);
      if ($term) {
        $types[] = '<strong>' . $this->t('Directory') . ':</strong> ' . $term->getName();
      }
    }

    return implode('<br>', $types);
  }

  /**
   * AJAX callback to check if a media type has filefield_paths enabled.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function checkFileFieldPathsAjax(Request $request) {
    $response = new AjaxResponse();
    $media_type_id = $request->request->get('media_type_id');
    $field_type = $request->request->get('field_type');

    if (!$media_type_id || !$field_type) {
      return $response;
    }

    $has_filefield_paths = $this->hasFileFieldPathsEnabled($media_type_id);

    // Build the HTML for the warning and checkbox if needed.
    $warning_html = '';
    $checkbox_html = '';

    if ($has_filefield_paths) {
      $warning_html = '<div class="messages messages--warning">' .
        $this->t('The selected media type has filefield_paths enabled, which will manage file paths automatically. Directory selection below will be ignored.') .
        '</div>';

      $checkbox_html = '<div class="form-item"><label><input type="checkbox" name="media_types[disable_filefield_paths_' . $field_type . ']" class="disable-filefield-paths" data-field-type="' . $field_type . '"> ' .
        $this->t('Disable filefield_paths for this media type') .
        '</label><div class="description">' .
        $this->t('Uncheck filefield_paths on the media field to allow manual directory selection.') .
        '</div></div>';
    }

    // Return the HTML to update.
    $response->addCommand(new HtmlCommand(
      '#media-types-' . $field_type . '-warning',
      $warning_html
    ));

    $response->addCommand(new HtmlCommand(
      '#media-types-' . $field_type . '-checkbox',
      $checkbox_html
    ));

    // Check if any media type still has filefield_paths enabled.
    $image_type = $request->request->get('image_type');
    $video_type = $request->request->get('video_type');

    $image_has_ffp = $image_type ? $this->hasFileFieldPathsEnabled($image_type) : FALSE;
    $video_has_ffp = $video_type ? $this->hasFileFieldPathsEnabled($video_type) : FALSE;

    $directories_html = '';

    if ($image_has_ffp || $video_has_ffp) {
      $directories_html = '<div class="messages messages--warning">' .
        '<strong>' . $this->t('Directory selection disabled:') . '</strong> ' .
        $this->t('One or more selected media types have filefield_paths enabled. File paths are managed automatically by filefield_paths configuration. To enable manual directory selection, disable filefield_paths on those media types above.') .
        '</div>';
    }

    $response->addCommand(new HtmlCommand(
      '#media-drop-directories-warning',
      $directories_html
    ));

    return $response;
  }

  /**
   * Check if a media type has filefield_paths enabled on its file field.
   *
   * @param string $media_type_id
   *   The media type ID.
   *
   * @return bool
   *   TRUE if filefield_paths is enabled.
   */
  protected function hasFileFieldPathsEnabled($media_type_id) {
    if (empty($media_type_id)) {
      return FALSE;
    }

    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media_type_id);
    if (!$media_type) {
      return FALSE;
    }

    $source_field = $media_type->getSource()->getConfiguration()['source_field'] ?? NULL;
    if (!$source_field) {
      return FALSE;
    }

    $field_configs = $this->entityTypeManager->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'media',
        'bundle' => $media_type_id,
        'field_name' => $source_field,
      ]);

    foreach ($field_configs as $field_config) {
      $settings = $field_config->getThirdPartySettings('filefield_paths');
      if (!empty($settings) && !empty($settings['enabled'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
