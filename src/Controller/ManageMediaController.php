<?php

namespace Drupal\media_drop\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Serialization\Yaml;

/**
 * Contrôleur pour la page de gestion des médias.
 */
class ManageMediaController extends ControllerBase {

  /**
   * Page de gestion des médias.
   */
  public function managePage() {
    // Vérifier si VBO est installé.
    if (!\Drupal::moduleHandler()->moduleExists('views_bulk_operations')) {
      $build['warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('Le module <a href="@url" target="_blank">Views Bulk Operations</a> doit être installé pour utiliser cette fonctionnalité.', [
          '@url' => 'https://www.drupal.org/project/views_bulk_operations',
        ]) . '<br><br>' .
        $this->t('Installation : <code>composer require drupal/views_bulk_operations && drush en views_bulk_operations -y</code>') .
        '</div>',
      ];
      return $build;
    }

    // Vérifier si la vue existe.
    $view = \Drupal::entityTypeManager()->getStorage('view')->load('media_drop_manage');

    if (!$view) {
      // Proposer de créer la vue.
      $build['info'] = [
        '#markup' => '<div class="messages messages--status">' .
        $this->t('La vue de gestion des médias n\'existe pas encore.') .
        '</div>',
      ];

      $build['create_view'] = [
        '#type' => 'details',
        '#title' => $this->t('Créer la vue automatiquement'),
        '#open' => TRUE,
      ];

      $build['create_view']['button'] = [
        '#type' => 'link',
        '#title' => $this->t('Créer la vue maintenant'),
        '#url' => Url::fromRoute('media_drop.create_manage_view'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'button--action'],
        ],
      ];

      $build['create_view']['description'] = [
        '#markup' => '<p>' . $this->t('Cliquez sur ce bouton pour créer automatiquement la vue de gestion des médias.') . '</p>',
      ];

      $build['manual_import'] = [
        '#type' => 'details',
        '#title' => $this->t('Ou importer manuellement'),
        '#open' => FALSE,
      ];

      $build['manual_import']['steps'] = [
        '#markup' => '<ol>' .
        '<li>' . $this->t('Allez dans <a href="/admin/config/development/configuration/single/import">Configuration > Import de configuration unique</a>') . '</li>' .
        '<li>' . $this->t('Type de configuration : <strong>Vue</strong>') . '</li>' .
        '<li>' . $this->t('Collez le contenu du fichier <code>views.view.media_drop_manage.yml</code>') . '</li>' .
        '<li>' . $this->t('Cliquez sur "Importer"') . '</li>' .
        '</ol>',
      ];

      $build['alternative'] = [
        '#type' => 'details',
        '#title' => $this->t('Gestion manuelle'),
        '#open' => FALSE,
      ];

      $build['alternative']['content'] = [
        '#markup' => '<p>' . $this->t('En attendant, vous pouvez gérer les médias via :') . '</p>' .
        '<ul>' .
        '<li><a href="/admin/content/media">' . $this->t('Liste des médias Drupal') . '</a></li>' .
        '<li><a href="/admin/structure/views/add">' . $this->t('Créer la vue manuellement') . '</a></li>' .
        '</ul>',
      ];

      return $build;
    }

    // La vue existe, afficher directement son contenu.
    $view_executable = \Drupal::service('entity_type.manager')
      ->getStorage('view')
      ->load('media_drop_manage')
      ->getExecutable();

    if (!$view_executable) {
      $build['error'] = [
        '#markup' => '<div class="messages messages--error">' .
        $this->t('Erreur lors du chargement de la vue.') .
        '</div>',
      ];
      return $build;
    }

    // Mettre à jour dynamiquement le vid du filtre répertoire si media_directories est activé.
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();
    if ($vocabulary_id) {
      // Get the display handler for 'default'.
      $display_handler = $view_executable->getDisplay('default');
      if ($display_handler) {
        $current_filters = $display_handler->getOption('filters') ?: [];
        $current_filters['directory'] = [
          'vid' => $vocabulary_id,
        ];
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
   * Récupère l'ID de la taxonomie utilisée par Media Directories.
   */
  protected function getMediaDirectoriesVocabulary() {
    if (\Drupal::moduleHandler()->moduleExists('media_directories')) {
      $config = \Drupal::config('media_directories.settings');
      return $config->get('directory_taxonomy');
    }
    return NULL;
  }

  /**
   * Créer la vue programmatiquement.
   */
  public function createView() {
    try {
      $view_config = $this->getViewConfig();

      $view = \Drupal::entityTypeManager()
        ->getStorage('view')
        ->create($view_config);

      $view->save();

      $this->messenger()->addStatus($this->t('La vue a été créée avec succès !'));

      // Vider le cache.
      drupal_flush_all_caches();

      return $this->redirect('media_drop.manage_media');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Erreur lors de la création de la vue : @error', [
        '@error' => $e->getMessage(),
      ]));
      return $this->redirect('media_drop.manage_media');
    }
  }

  /**
   * Configuration de la vue.
   */
  protected function getViewConfig() {
    // Charger la configuration depuis le fichier YAML.
    $module_path = \Drupal::service('extension.list.module')->getPath('media_drop');
    $yaml_file = $module_path . '/config/optional/views.view.media_drop_manage.yml';

    if (!file_exists($yaml_file)) {
      throw new \Exception($this->t('Le fichier de configuration de la vue est introuvable : @file', [
        '@file' => $yaml_file,
      ]));
    }

    // Parser le fichier YAML.
    $view_config = Yaml::decode(file_get_contents($yaml_file));

    if (!$view_config) {
      throw new \Exception($this->t('Impossible de parser le fichier de configuration de la vue.'));
    }

    // Mettre à jour dynamiquement le vid du filtre répertoire si media_directories est activé.
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();
    if ($vocabulary_id && isset($view_config['display']['default']['display_options']['filters']['directory_target_id'])) {
      $view_config['display']['default']['display_options']['filters']['directory_target_id']['vid'] = $vocabulary_id;
    }

    return $view_config;
  }

}
