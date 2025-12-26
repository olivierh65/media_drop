<?php

namespace Drupal\media_drop\Controller;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\media_drop\Traits\MediaFieldFilterTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Controller for media management page.
 */
class ManageMediaController extends ControllerBase {

  use MediaFieldFilterTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The extension list module service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $extensionListModule;

  /**
   * Constructs a ManageMediaController object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleExtensionList $extension_list_module,
  ) {
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->extensionListModule = $extension_list_module;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('extension.list.module')
    );
  }

  /**
   * Get the entity type manager.
   */
  protected function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * Media management page.
   */
  public function managePage() {
    // Check if user has permission to manage media.
    if (!$this->currentUser()->hasPermission('manage media')) {
      throw new AccessDeniedHttpException('You do not have permission to manage media.');
    }

    // Check if VBO is installed and enabled.
    $vbo_installed = $this->moduleHandler->moduleExists('views_bulk_operations');

    if (!$vbo_installed) {
      $build['warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('The <a href="@url" target="_blank">Views Bulk Operations</a> module must be installed and enabled to use this functionality.', [
          '@url' => 'https://www.drupal.org/project/views_bulk_operations',
        ]) . '<br><br>' .
        $this->t('Installation: <code>composer require drupal/views_bulk_operations && drush en views_bulk_operations -y && drush cr</code>') .
        '</div>',
      ];
      return $build;
    }

    // Check if the view exists.
    $view = $this->entityTypeManager->getStorage('view')->load('media_drop_manage');

    if (!$view) {
      $build['info'] = [
        '#markup' => '<div class="messages messages--status">' .
        $this->t('The media management view does not exist yet.') .
        '</div>',
      ];

      $build['create_view'] = [
        '#type' => 'details',
        '#title' => $this->t('Create the view automatically'),
        '#open' => TRUE,
      ];

      $build['create_view']['button'] = [
        '#type' => 'link',
        '#title' => $this->t('Create the view now'),
        '#url' => Url::fromRoute('media_drop.create_manage_view'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'button--action'],
        ],
      ];

      $build['create_view']['description'] = [
        '#markup' => '<p>' . $this->t('Click this button to automatically create the media management view.') . '</p>',
      ];

      $build['manual_import'] = [
        '#type' => 'details',
        '#title' => $this->t('Or import manually'),
        '#open' => FALSE,
      ];

      $build['manual_import']['steps'] = [
        '#markup' => '<ol>' .
        '<li>' . $this->t('Go to <a href="/admin/config/development/configuration/single/import">Configuration > Single import</a>') . '</li>' .
        '<li>' . $this->t('Configuration type: <strong>View</strong>') . '</li>' .
        '<li>' . $this->t('Paste the content of the file <code>views.view.media_drop_manage.yml</code>') . '</li>' .
        '<li>' . $this->t('Click "Import"') . '</li>' .
        '</ol>',
      ];

      $build['alternative'] = [
        '#type' => 'details',
        '#title' => $this->t('Manual management'),
        '#open' => FALSE,
      ];

      $build['alternative']['content'] = [
        '#markup' => '<p>' . $this->t('In the meantime, you can manage media via:') . '</p>' .
        '<ul>' .
        '<li><a href="/admin/content/media">' . $this->t('Drupal media list') . '</a></li>' .
        '<li><a href="/admin/structure/views/add">' . $this->t('Create the view manually') . '</a></li>' .
        '</ul>',
      ];

      return $build;
    }

    // The view exists, display its content directly.
    $view_executable = $this->entityTypeManager
      ->getStorage('view')
      ->load('media_drop_manage')
      ->getExecutable();

    if (!$view_executable) {
      $build['error'] = [
        '#markup' => '<div class="messages messages--error">' .
        $this->t('Error loading the view.') .
        '</div>',
      ];
      return $build;
    }

    // Update vocabulary ID for directory filter if media_directories is enabled.
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();
    if ($vocabulary_id) {
      $display_handler = $view_executable->getDisplay('default');
      if ($display_handler) {
        $current_filters = $display_handler->getOption('filters') ?: [];
        $current_filters['directory']['vid'] = $vocabulary_id;
        $display_handler->setOption('filters', $current_filters);
      }
    }

    $view_executable->setDisplay('page_1');
    $view_executable->preExecute();
    $view_executable->execute();

    $build = $view_executable->buildRenderable('page_1', []);
    $build['#attached']['library'][] = 'media_drop/admin_grid';
    return $build;
  }

  /**
   * Get the taxonomy ID used by Media Directories.
   */
  protected function getMediaDirectoriesVocabulary() {
    if ($this->moduleHandler->moduleExists('media_directories')) {
      $config = $this->configFactory->get('media_directories.settings');
      return $config->get('directory_taxonomy');
    }
    return NULL;
  }

  /**
   * Create the view programmatically.
   */
  public function createView() {
    try {
      $view_config = $this->getViewConfig();

      $view = \Drupal::entityTypeManager()
        ->getStorage('view')
        ->create($view_config);

      $view->save();

      $this->messenger()->addStatus($this->t('The view has been created successfully!'));

      drupal_flush_all_caches();

      return $this->redirect('media_drop.manage_media');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error creating the view: @error', [
        '@error' => $e->getMessage(),
      ]));
      return $this->redirect('media_drop.manage_media');
    }
  }

  /**
   * Get the view configuration.
   */
  protected function getViewConfig() {
    $module_path = $this->extensionListModule->getPath('media_drop');
    $yaml_file = $module_path . '/config/optional/views.view.media_drop_manage.yml';

    if (!file_exists($yaml_file)) {
      throw new \Exception($this->t('View configuration file not found: @file', [
        '@file' => $yaml_file,
      ]));
    }

    $view_config = Yaml::decode(file_get_contents($yaml_file));

    if (!$view_config) {
      throw new \Exception($this->t('Unable to parse the view configuration file.'));
    }

    $vocabulary_id = $this->getMediaDirectoriesVocabulary();
    if ($vocabulary_id && isset($view_config['display']['default']['display_options']['filters']['directory'])) {
      $view_config['display']['default']['display_options']['filters']['directory']['vid'] = $vocabulary_id;
    }

    return $view_config;
  }

}
