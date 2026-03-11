<?php

namespace Drupal\media_drop\Controller;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\media_drop\Traits\MediaFieldFilterTrait;
use Drupal\media_album_av_common\Service\DirectoryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\media_album_av_common\Traits\AlbumTrait;

/**
 * Controller for media management page.
 */
class ManageMediaController extends ControllerBase {

  use MediaFieldFilterTrait;
  use AlbumTrait;

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
   * The taxonomy service.
   *
   * @var \Drupal\media_album_av_common\Service\DirectoryService
   */
  protected $taxonomyService;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The media action service.
   *
   * @var \Drupal\media_album_light_table_style\Service\MediaActionService
   */
  protected $mediaActionService;

  /**
   * Constructs a ManageMediaController object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleExtensionList $extension_list_module,
    DirectoryService $taxonomy_service,
    FileSystemInterface $file_system,
    FormBuilderInterface $form_builder,
    $media_action_service,
  ) {
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->extensionListModule = $extension_list_module;
    $this->taxonomyService = $taxonomy_service;
    $this->fileSystem = $file_system;
    $this->formBuilder = $form_builder;
    $this->mediaActionService = $media_action_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('extension.list.module'),
      $container->get('media_drop.taxonomy_service'),
      $container->get('file_system'),
      $container->get('form_builder'),
      $container->get('media_album_light_table_style.media_action')
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
  public function managePage(Request $request, $id = NULL) {
    // Check if user has permission to manage media.
    if (!$this->currentUser()->hasPermission('manage media')) {
      throw new AccessDeniedHttpException('You do not have permission to manage media.');
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

    $view_executable->setDisplay('page_1');

    if ($id !== NULL) {
      // $view_executable->setArguments([$id]);
      // Parser l'argument pour récupérer tid et include_children.
      $parts = explode(':', $id, 2);
      $tid = (int) $parts[0];
      $include_children = isset($parts[1]) ? (int) (bool) $parts[1] : 0;

      // Injecter dans l'exposed input pour que les filtres exposés
      // soient initialisés avec les bonnes valeurs.
      $view_executable->setExposedInput([
        'directory_with_children' => (string) $tid,
        'include_children' => (string) $include_children,
      ]);

    }
    $view_executable->preExecute();
    $view_executable->execute();

    $view_build = $view_executable->buildRenderable('page_1', []);

    return $view_build;

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

    $vocabulary_id = $this->taxonomyService->getMediaDirectoriesVocabulary();
    if ($vocabulary_id && isset($view_config['display']['default']['display_options']['filters']['directory'])) {
      $view_config['display']['default']['display_options']['filters']['directory']['vid'] = $vocabulary_id;
    }

    return $view_config;
  }

}
