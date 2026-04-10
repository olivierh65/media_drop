<?php

namespace Drupal\media_drop\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * @ViewsFilter("media_directory_filter")
 */
class DirectoryFilter extends FilterPluginBase {

  public $no_operator = TRUE;

  /**
   * Stocke si l'inclusion des enfants est demandée.
   */
  protected bool $includeChildren = FALSE;

  /**
   * {@inheritdoc}
   */
  public function canExpose() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isExposed() {
    return !empty($this->options['exposed']);
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options                       = parent::defineOptions();
    $options['exposed']['default'] = TRUE;
    $options['value']['default']   = '0';
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    return $this->t('Répertoire + option sous-répertoires');
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {

    $exposed_input = $this->view->getExposedInput();
    $identifier    = $this->options['expose']['identifier'] ?? 'directory';

    // Get the media directory vocabulary from configuration.
    $vocabulary = $this->getMediaDirectoryVocabulary();

    // Chargement des termes.
    $options = ['0' => '- Racine -'];
    $terms   = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($vocabulary);
    foreach ($terms as $term) {
      $options[$term->tid] = str_repeat('-', $term->depth) . ' ' . $term->name;
    }

    $dir_argument = $this->getDirectoryArgument();
    if ($dir_argument !== NULL && !empty($dir_argument->view)) {
      $raw     = $dir_argument->view->args[$dir_argument->position] ?? '';
      $parts   = explode(':', $raw, 2);
      $arg_tid = (string) (int) $parts[0];
    }

    $this->value = $exposed_input[$identifier] ?? $arg_tid ?? '0';

    $form['value'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Répertoire'),
      '#options'       => $options,
      '#default_value' => $this->value,
    ];

    /* $form['include_children'] = [
    '#type'          => 'select',
    '#title'         => $this->t('Inclure les sous-répertoires'),
    '#options'       => [
    '1' => $this->t('Oui'),
    '0' => $this->t('Non'),
    ],
    '#default_value' => $arg_include_children ?? $exposed_input['include_children'] ?? '1',
    '#prefix'        => '<div class="include-children-wrapper">',
    '#suffix'        => '</div>',
    ]; */

    $form['#attached']['library'][] = 'media_drop/media-drop';
  }

  /**
   * {@inheritdoc}
   * Pas de validation bloquante — on accepte 'All' comme string.
   */
  public function validateExposed(&$form, FormStateInterface $form_state) {
    // Pas d'appel à parent : on gère nous-mêmes.
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state) {
    parent::buildExposedForm($form, $form_state);

    $id = $this->options['expose']['identifier'] ?? $this->options['id'];

    // Supprimer l'option 'All' ajoutée automatiquement par Views.
    if (isset($form[$id]['#options']['All'])) {
      unset($form[$id]['#options']['All']);
      $form[$id]['#default_value'] = '0';
    }

    $dir_argument = $this->getDirectoryArgument();
    if ($dir_argument !== NULL && !empty($dir_argument->view)) {
      $raw                         = $dir_argument->view->args[$dir_argument->position] ?? '';
      $parts                       = explode(':', $raw, 2);
      $tid                         = (string) (int) $parts[0];
      $form[$id]['#default_value'] = $tid;
    }
  }

  /**
   * Get directory argument plugin if exists in this view.
   */
  protected function getDirectoryArgument() {
    foreach ($this->view->argument as $argument) {
      if ($argument->getPluginId() === 'media_directory_argument') {
        return $argument;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   * Toujours TRUE pour que query() soit systématiquement appelé.
   */
  public function acceptExposedInput($input) {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }

    $identifier            = $this->options['expose']['identifier'] ?? 'directory_with_children ';
    $this->value           = $input[$identifier] ?? '0';
    $this->includeChildren = !empty($input['include_children']);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {

    $exposed = $this->view->getExposedInput();
    $id      = $this->options['expose']['identifier'] ?? $this->options['id'];

    // Si le filtre contextuel 'directory_with_children' est configuré sur
    // cette vue et qu'une valeur lui a été transmise :
    // - Si l'exposé a aussi une valeur, on efface l'URL et on utilise l'exposé.
    // - Si l'exposé n'a pas de valeur, on garde l'URL (on ne double pas la condition SQL).
    // On retrouve sa position dans $view->args via l'ordre de $view->argument.
    if (isset($this->view->argument[$id])) {
      $pos = $this->view->argument[$id]->position ?? NULL;
      if ($pos !== FALSE
        && isset($this->view->args[$pos])
        && $this->view->args[$pos] !== ''
      ) {
        // L'URL a une valeur.
        if (!empty($exposed[$id])) {
          // L'exposé a aussi une valeur : le formulaire prend la main.
          unset($this->view->args[$pos]);
        }
        else {
          return;
          // L'exposé n'a pas de valeur : on garde l'URL.
          // [$this->value, $this->includeChildren] = $this->parseArgument($this->view->args[$pos]);
          // unset($this->view->args[$pos]);.
        }
      }
    }

    $selected         = $this->value ?? '0';
    $include_children = $this->includeChildren ?? FALSE;

    if ($selected === 'All') {
      $this->value = '0';
      $selected    = '0';
    }

    $tids = [(int) $selected];

    if (($selected === 0) && $include_children) {
      // If the root is selected and children are included, we include all terms.
      return;
    }

    if ($include_children) {
      $term = Term::load((int) $selected);
      if ($term) {
        $children = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadTree($term->bundle(), (int) $selected);
        foreach ($children as $child) {
          $tids[] = (int) $child->tid;
        }
      }
    }

    $table_alias  = $this->query->ensureTable('media_field_data');
    $placeholders = [];
    $values       = [];
    foreach ($tids as $i => $tid) {
      $ph             = ':dir_tid_' . $i;
      $placeholders[] = $ph;
      $values[$ph]    = $tid;
    }

    $this->query->addWhereExpression(
      0,
      $table_alias . '.directory IN (' . implode(', ', $placeholders) . ')',
      $values
    );
  }

  /**
   * Parse l'argument brut "TID" ou "TID:include_children".
   *
   * @param string|null $raw
   *   Valeur brute de l'argument.
   *
   * @return array
   *   [int $tid, bool $include_children]
   */
  protected function parseArgument(?string $raw): array {
    if ($raw === NULL || $raw === '') {
      return [0, FALSE];
    }
    $parts            = explode(':', $raw, 2);
    $tid              = (int) $parts[0];
    $include_children = isset($parts[1]) && (bool) $parts[1];
    return [$tid, $include_children];
  }

  /**
   * Retourne la configuration de grouping pour le style plugin.
   */
  public function getGroupingConfig(): array {
    return [
    [
      'field'          => 'directory',
      'rendered'       => FALSE,
      'rendered_strip' => FALSE,
    ],
    ];
  }

  /**
   * Get the media directory vocabulary ID.
   *
   * Gets the vocabulary from media_drop configuration, or falls back to
   * the value from media_album_av settings, or defaults to 'media_album_av_folders'.
   *
   * @return string
   *   The vocabulary ID to use for media directories.
   */
  private function getMediaDirectoryVocabulary(): string {
    // Try media_drop settings first.
    $media_drop_config = \Drupal::config('media_drop.settings');
    $vocabulary = $media_drop_config->get('media_directory_vocabulary');
    if (!empty($vocabulary)) {
      return $vocabulary;
    }

    // Try media_album_av settings.
    $media_album_av_config = \Drupal::config('media_album_av.settings');
    $vocabulary = $media_album_av_config->get('prefered_media_directory');
    if (!empty($vocabulary)) {
      return $vocabulary;
    }

    // Default to 'media_album_av_folders'.
    return 'media_album_av_folders';
  }

}
