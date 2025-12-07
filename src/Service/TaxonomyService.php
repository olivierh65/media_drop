<?php

namespace Drupal\media_drop\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Service pour gérer les termes de taxonomie Media Directories.
 */
class TaxonomyService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a TaxonomyService.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Récupère l'ID de la taxonomie Media Directories.
   */
  public function getMediaDirectoriesVocabulary() {
    if (!\Drupal::moduleHandler()->moduleExists('media_directories')) {
      return NULL;
    }

    $config = \Drupal::config('media_directories.settings');
    return $config->get('directory_taxonomy');
  }

  /**
   * Crée ou récupère un terme de taxonomie pour un répertoire.
   *
   * @param int $album_id
   *   L'ID de l'album.
   * @param string $user_folder_name
   *   Le nom du dossier utilisateur (ex: "olivier.dupont").
   * @param string|null $subfolder_name
   *   Le nom du sous-dossier optionnel (ex: "matin").
   *
   * @return int|null
   *   L'ID du terme créé/trouvé, ou NULL si Media Directories n'est pas activé.
   */
  public function ensureDirectoryTerm($album_id, $user_folder_name, $subfolder_name = NULL) {
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    if (!$vocabulary_id) {
      return NULL;
    }

    // Récupérer l'album pour avoir le terme parent.
    $database = \Drupal::database();
    $album = $database->select('media_drop_albums', 'a')
      ->fields('a')
      ->condition('id', $album_id)
      ->execute()
      ->fetchObject();

    if (!$album) {
      return NULL;
    }

    // Terme parent = le répertoire de l'album (si défini)
    $parent_tid = !empty($album->media_directory) ? $album->media_directory : 0;

    // 1. Créer/récupérer le terme pour le dossier utilisateur
    $user_term_id = $this->getOrCreateTerm($vocabulary_id, $user_folder_name, $parent_tid);

    // 2. Si un sous-dossier est spécifié, le créer sous le dossier utilisateur
    if (!empty($subfolder_name)) {
      return $this->getOrCreateTerm($vocabulary_id, $subfolder_name, $user_term_id);
    }

    return $user_term_id;
  }

  /**
   * Récupère ou crée un terme de taxonomie.
   *
   * @param string $vocabulary_id
   *   L'ID du vocabulaire.
   * @param string $term_name
   *   Le nom du terme.
   * @param int $parent_tid
   *   L'ID du terme parent (0 pour la racine).
   *
   * @return int|null
   *   L'ID du terme.
   */
  protected function getOrCreateTerm($vocabulary_id, $term_name, $parent_tid = 0) {
    // Chercher si le terme existe déjà.
    $query = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', $vocabulary_id)
      ->condition('name', $term_name)
      ->accessCheck(FALSE);

    if ($parent_tid > 0) {
      $query->condition('parent', $parent_tid);
    }

    $tids = $query->execute();

    if (!empty($tids)) {
      // Le terme existe déjà.
      return reset($tids);
    }

    // Créer le nouveau terme.
    $term = Term::create([
      'vid' => $vocabulary_id,
      'name' => $term_name,
      'parent' => $parent_tid > 0 ? [$parent_tid] : [],
    ]);

    $term->save();

    return $term->id();
  }

  /**
   * Crée la structure de termes pour un album complet.
   *
   * @param int $album_id
   *   L'ID de l'album.
   * @param string $album_name
   *   Le nom de l'album.
   *
   * @return int|null
   *   L'ID du terme de l'album créé.
   */
  public function createAlbumDirectoryStructure($album_id, $album_name) {
    $vocabulary_id = $this->getMediaDirectoriesVocabulary();

    if (!$vocabulary_id) {
      return NULL;
    }

    // Créer un terme pour l'album lui-même s'il n'existe pas.
    $album_term_id = $this->getOrCreateTerm($vocabulary_id, $album_name, 0);

    // Mettre à jour l'album avec ce terme.
    $database = \Drupal::database();
    $database->update('media_drop_albums')
      ->fields(['media_directory' => $album_term_id])
      ->condition('id', $album_id)
      ->execute();

    return $album_term_id;
  }

  /**
   * Nettoie les termes vides (sans médias associés).
   *
   * @param string $vocabulary_id
   *   L'ID du vocabulaire.
   */
  public function cleanupEmptyTerms($vocabulary_id) {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabulary_id]);

    foreach ($terms as $term) {
      // Vérifier si des médias utilisent ce terme.
      $query = $this->entityTypeManager
        ->getStorage('media')
        ->getQuery()
        ->condition('directory', $term->id())
        ->accessCheck(FALSE);

      $count = $query->count()->execute();

      if ($count == 0) {
        // Aucun média n'utilise ce terme, on peut le supprimer.
        $term->delete();
      }
    }
  }

}
