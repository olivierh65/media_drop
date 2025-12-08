<?php

namespace Drupal\media_drop\Form;

use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\Crypt;

/**
 * Formulaire de création/édition d'album.
 */
class AlbumForm extends FormBase {

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
   * Constructs a new AlbumForm.
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
    return 'media_drop_album_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $album_id = NULL) {
    $album = NULL;

    if ($album_id) {
      $album = $this->database->select('media_drop_albums', 'a')
        ->fields('a')
        ->condition('id', $album_id)
        ->execute()
        ->fetchObject();

      if (!$album) {
        $this->messenger()->addError($this->t('Album non trouvé.'));
        return $form;
      }
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nom de l\'album'),
      '#default_value' => $album ? $album->name : '',
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Exemple : Anniversaire 2025, Mariage Sophie & Pierre'),
    ];

    // Récupérer les types de médias qui acceptent des images ou vidéos.
    $media_types = $this->getMediaTypesWithFileFields();
    $image_media_types = $media_types['image'];
    $video_media_types = $media_types['video'];

    $form['media_types'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Types de médias'),
      '#description' => $this->t('Sélectionnez les types de médias à créer pour les fichiers uploadés. Si non spécifié, le système utilisera le mapping MIME par défaut.'),
      '#tree' => TRUE,
    ];

    $form['media_types']['default_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type de média pour les images'),
      '#options' => ['' => $this->t('- Utiliser le mapping MIME par défaut -')] + $image_media_types,
      '#default_value' => $album ? $album->default_media_type : '',
      '#description' => $this->t('Type de média Drupal qui sera créé pour les fichiers image (JPEG, PNG, etc.)'),
    ];

    $form['media_types']['video_media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type de média pour les vidéos'),
      '#options' => ['' => $this->t('- Utiliser le mapping MIME par défaut -')] + $video_media_types,
      '#default_value' => $album ? $album->video_media_type : '',
      '#description' => $this->t('Type de média Drupal qui sera créé pour les fichiers vidéo (MP4, MOV, etc.)'),
    ];

    $form['directories'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Répertoires'),
      '#tree' => TRUE,
    ];

    $form['directories']['base_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Répertoire de stockage'),
      '#default_value' => $album ? $album->base_directory : 'public://media-drop/',
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Exemple : public://media-drop/anniversaire2025<br>Les médias seront enregistrés dans des sous-dossiers par utilisateur.'),
    ];

    $form['directories']['media_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Répertoire dans Media Browser'),
      '#default_value' => $album ? $album->media_directory : '',
      '#maxlength' => 255,
      '#description' => $this->t('Chemin optionnel dans le Media Browser pour organiser les médias (ex: /albums/anniversaire2025).<br>Laissez vide pour utiliser la racine.'),
      '#access' => !\Drupal::moduleHandler()->moduleExists('media_directories'),
    ];

    // Si le module media_directories est activé, proposer la taxonomie.
    if (\Drupal::moduleHandler()->moduleExists('media_directories')) {
      $vocabulary_id = $this->getMediaDirectoriesVocabulary();

      if ($vocabulary_id) {
        $terms = $this->getTermOptions($vocabulary_id);

        $form['directories']['media_directory_term'] = [
          '#type' => 'select',
          '#title' => $this->t('Répertoire Media Directories'),
          '#options' => ['' => $this->t('- Racine -')] + $terms,
          '#default_value' => $album ? $album->media_directory : '',
          '#description' => $this->t('Sélectionnez le terme de taxonomie Media Directories où seront classés les médias uploadés.<br>Cette taxonomie est utilisée par le module Media Directories pour organiser les médias.'),
        ];

        $form['directories']['create_new_term'] = [
          '#type' => 'details',
          '#title' => $this->t('Créer un nouveau répertoire'),
          '#open' => FALSE,
          '#tree' => TRUE,
        ];

        $form['directories']['create_new_term']['new_term_name'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Nom du nouveau répertoire'),
          '#description' => $this->t('Si vous souhaitez créer un nouveau répertoire dans Media Directories, saisissez son nom ici.'),
        ];

        $form['directories']['create_new_term']['parent_term'] = [
          '#type' => 'select',
          '#title' => $this->t('Répertoire parent'),
          '#options' => [0 => $this->t('- Racine -')] + $terms,
          '#description' => $this->t('Sous quel répertoire créer le nouveau répertoire.'),
        ];

        $form['directories']['auto_create_structure'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Créer automatiquement la structure'),
          '#default_value' => TRUE,
          '#description' => $this->t('Si coché, les dossiers utilisateurs et sous-dossiers seront automatiquement ajoutés à la taxonomie Media Directories lors des uploads.'),
        ];
      }
      else {
        $form['directories']['media_directory_warning'] = [
          '#markup' => '<div class="messages messages--warning">' .
          $this->t('Le module Media Directories est activé mais aucune taxonomie n\'est configurée. Veuillez configurer Media Directories d\'abord.') .
          '</div>',
        ];
      }
    }

    if ($album) {
      $url = \Drupal::request()->getSchemeAndHttpHost() . '/media-drop/' . $album->token;

      $form['current_url'] = [
        '#type' => 'item',
        '#title' => $this->t('URL de dépôt'),
        '#markup' => '<div class="media-drop-url"><strong>' . $url . '</strong><br><small>' . $this->t('Partagez cette URL avec les participants pour qu\'ils puissent déposer leurs médias.') . '</small></div>',
      ];

      $form['regenerate_token'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Régénérer le token (changera l\'URL)'),
        '#default_value' => FALSE,
        '#description' => $this->t('Cochez cette case pour générer une nouvelle URL. L\'ancienne URL ne fonctionnera plus.'),
      ];
    }

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Album actif'),
      '#default_value' => $album ? $album->status : 1,
      '#description' => $this->t('Si décoché, l\'album ne sera plus accessible pour les dépôts.'),
    ];

    $form['album_id'] = [
      '#type' => 'hidden',
      '#value' => $album_id,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $album ? $this->t('Mettre à jour') : $this->t('Créer'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Annuler'),
      '#url' => Url::fromRoute('media_drop.album_list'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $base_directory = $form_state->getValue(['directories', 'base_directory']);

    // Vérifier que le répertoire commence par un scheme valide.
    // @todo traiter le cas ou $base_directory est NULL.
    if (!empty($base_directory) && !preg_match('/^(public|private):\/\//', $base_directory)) {
      $form_state->setErrorByName('directories][base_directory', $this->t('Le répertoire doit commencer par public:// ou private://'));
    }

    // Si media_directories n'est pas activé, valider le champ texte.
    if (!\Drupal::moduleHandler()->moduleExists('media_directories')) {
      $media_directory = $form_state->getValue(['directories', 'media_directory']);
      if (!empty($media_directory)) {
        $media_directory = trim($media_directory);
        if (substr($media_directory, 0, 1) === '/') {
          $media_directory = substr($media_directory, 1);
        }
        if (substr($media_directory, -1) === '/') {
          $media_directory = substr($media_directory, 0, -1);
        }
        $form_state->setValue(['directories', 'media_directory'], $media_directory);
      }
    }
    else {
      // Valider que si un nouveau terme est demandé, un nom est fourni.
      $new_term_name = $form_state->getValue(['directories', 'create_new_term', 'new_term_name']);
      if (!empty($new_term_name) && empty(trim($new_term_name))) {
        $form_state->setErrorByName('directories][create_new_term][new_term_name',
          $this->t('Le nom du répertoire ne peut pas être vide.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $album_id = $form_state->getValue('album_id');

    // Gérer la création d'un nouveau terme si demandé (media_directories activé)
    $media_directory_value = '';
    if (\Drupal::moduleHandler()->moduleExists('media_directories')) {
      $new_term_name = $form_state->getValue(['directories', 'create_new_term', 'new_term_name']);

      if (!empty($new_term_name)) {
        // Créer le nouveau terme.
        $vocabulary_id = $this->getMediaDirectoriesVocabulary();
        $parent_tid = $form_state->getValue(['directories', 'create_new_term', 'parent_term']);

        $term = Term::create([
          'vid' => $vocabulary_id,
          'name' => $new_term_name,
          'parent' => $parent_tid ? [$parent_tid] : [],
        ]);
        $term->save();

        $media_directory_value = $term->id();
        $this->messenger()->addStatus($this->t('Le répertoire "@name" a été créé.', ['@name' => $new_term_name]));
      }
      else {
        // Utiliser le terme sélectionné.
        $media_directory_value = $form_state->getValue(['directories', 'media_directory_term']);
      }
    }
    else {
      // Utiliser le champ texte si media_directories n'est pas activé.
      $media_directory_value = $form_state->getValue(['directories', 'media_directory']);
    }

    $values = [
      'name' => $form_state->getValue('name'),
      'base_directory' => rtrim($form_state->getValue(['directories', 'base_directory']), '/'),
      'media_directory' => $media_directory_value,
      'default_media_type' => $form_state->getValue(['media_types', 'default_media_type']),
      'video_media_type' => $form_state->getValue(['media_types', 'video_media_type']),
      'auto_create_structure' => \Drupal::moduleHandler()->moduleExists('media_directories') && $form_state->getValue(['directories', 'auto_create_structure']) ? 1 : 0,
      'status' => $form_state->getValue('status') ? 1 : 0,
      'updated' => \Drupal::time()->getRequestTime(),
    ];

    if ($album_id) {
      // Mise à jour.
      if ($form_state->getValue('regenerate_token')) {
        $values['token'] = Crypt::randomBytesBase64(32);
      }

      $this->database->update('media_drop_albums')
        ->fields($values)
        ->condition('id', $album_id)
        ->execute();

      $this->messenger()->addStatus($this->t('L\'album a été mis à jour.'));
    }
    else {
      // Création.
      $values['token'] = Crypt::randomBytesBase64(32);
      $values['created'] = \Drupal::time()->getRequestTime();

      $this->database->insert('media_drop_albums')
        ->fields($values)
        ->execute();

      // Récupérer l'ID de l'album qui vient d'être créé.
      $new_album_id = $this->database->select('media_drop_albums', 'a')
        ->fields('a', ['id'])
        ->condition('token', $values['token'])
        ->execute()
        ->fetchField();

      // Créer automatiquement la structure de répertoires si media_directories est activé.
      if ($new_album_id && \Drupal::moduleHandler()->moduleExists('media_directories')) {
        try {
          $taxonomy_service = \Drupal::service('media_drop.taxonomy_service');
          // Créer le terme album parent.
          $album_term_id = $taxonomy_service->createAlbumDirectoryStructure(
            $new_album_id,
            $values['name']
          );

          // Mettre à jour l'album avec l'ID du terme parent.
          if ($album_term_id) {
            $this->database->update('media_drop_albums')
              ->fields(['media_directory' => $album_term_id])
              ->condition('id', $new_album_id)
              ->execute();

            $this->messenger()->addStatus($this->t('L\'album et la structure "Répertoires" ont été créés automatiquement.'));
          }
        }
        catch (\Exception $e) {
          $this->messenger()->addWarning($this->t('L\'album a été créé mais la structure de répertoires n\'a pas pu être créée : @error', [
            '@error' => $e->getMessage(),
          ]));
        }
      }
      else {
        $this->messenger()->addStatus($this->t('L\'album a été créé.'));
      }
    }

    $form_state->setRedirect('media_drop.album_list');
  }

  /**
   * Récupère les types de médias qui acceptent des fichiers image ou vidéo.
   */
  protected function getMediaTypesWithFileFields() {
    $image_types = [];
    $video_types = [];

    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();

    foreach ($media_types as $media_type_id => $media_type) {
      $source = $media_type->getSource();
      $source_field = $source->getConfiguration()['source_field'];

      // Récupérer la définition du champ.
      $field_definitions = $this->entityTypeManager
        ->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'media',
          'bundle' => $media_type_id,
          'field_name' => $source_field,
        ]);

      if (!empty($field_definitions)) {
        $field_definition = reset($field_definitions);
        $field_type = $field_definition->getType();

        // Vérifier si c'est un champ fichier ou image.
        if ($field_type === 'image') {
          $image_types[$media_type_id] = $media_type->label() . ' (' . $this->t('Images') . ')';
        }
        elseif ($field_type === 'file') {
          // Vérifier les extensions autorisées pour déterminer si c'est pour vidéo.
          $settings = $field_definition->getSettings();
          $extensions = $settings['file_extensions'] ?? '';

          // Si contient des extensions vidéo.
          if (preg_match('/(mp4|mov|avi|webm|mkv|flv)/i', $extensions)) {
            $video_types[$media_type_id] = $media_type->label() . ' (' . $this->t('Vidéos') . ')';
          }
          // Si contient des extensions image.
          if (preg_match('/(jpg|jpeg|png|gif|webp|bmp)/i', $extensions)) {
            $image_types[$media_type_id] = $media_type->label() . ' (' . $this->t('Fichiers') . ')';
          }
          // Si pas d'extension spécifique, on l'ajoute aux deux.
          if (empty($extensions) || $extensions === '*') {
            $image_types[$media_type_id] = $media_type->label();
            $video_types[$media_type_id] = $media_type->label();
          }
        }
      }
    }

    return [
      'image' => $image_types,
      'video' => $video_types,
    ];
  }

  /**
   * Récupère l'ID de la taxonomie utilisée par Media Directories.
   */
  protected function getMediaDirectoriesVocabulary() {
    $config = \Drupal::config('media_directories.settings');
    $vocabulary_id = $config->get('directory_taxonomy');

    return $vocabulary_id ?: NULL;
  }

  /**
   * Récupère les options de termes pour un vocabulaire avec hiérarchie.
   */
  protected function getTermOptions($vocabulary_id, $parent = 0, $depth = 0) {
    $options = [];

    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, $parent, 1, TRUE);

    foreach ($terms as $term) {
      $prefix = str_repeat('--', $depth);
      $options[$term->id()] = $prefix . ' ' . $term->getName();

      // Récupérer récursivement les enfants.
      $children = $this->getTermOptions($vocabulary_id, $term->id(), $depth + 1);
      if (!empty($children)) {
        $options += $children;
      }
    }

    return $options;
  }

}
