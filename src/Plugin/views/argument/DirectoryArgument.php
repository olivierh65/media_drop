<?php

namespace Drupal\media_drop\Plugin\views\argument;

use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Form\FormStateInterface;

/**
 * Argument contextuel Views pour filtrer les médias par répertoire (taxonomy tid).
 *
 * Format de l'argument dans l'URL :
 *   - TID        → filtre sur ce tid seul (ex. /view/path/42)
 *   - TID:1      → filtre sur ce tid + ses enfants (ex. /view/path/42:1)
 *   - 0          → racine uniquement.
 *
 * @ingroup views_argument_handlers
 *
 * @ViewsArgument("media_directory_argument")
 */
class DirectoryArgument extends ArgumentPluginBase {

  /**
   * {@inheritdoc}
   */
  public function summaryName($data) {
    $tid = $data->{$this->name_alias};
    if ($tid == 0) {
      return $this->t('Racine');
    }
    $term = Term::load($tid);
    return $term ? $term->label() : $this->t('Inconnu (@tid)', ['@tid' => $tid]);
  }

  /**
   * {@inheritdoc}
   */
  public function title() {
    [$tid] = $this->parseArgument($this->argument);
    if ($tid == 0) {
      return $this->t('Racine');
    }
    $term = Term::load($tid);
    return $term ? $term->label() : $this->t('Inconnu (@tid)', ['@tid' => $tid]);
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
   * {@inheritdoc}
   */
  public function query($group_by = FALSE) {
    $this->ensureMyTable();

    $exposed = $this->view->getExposedInput();
    $id      = $this->options['expose']['identifier'] ?? $this->options['id'];

    if (isset($exposed[$id])) {
      // L'argument est exposé et a une valeur : on utilise la valeur exposée.
      return;
    }

    /*
    $stored = \Drupal::service('tempstore.private')
    ->get('media_drop')
    ->get('directory_argument');

    if (!empty($stored)) {
    // @todo Rechercher la position de cet argument dans la vue plutôt que de supposer que c'est le premier.
    [$parent_tid, $include_children] = $this->parseArgument($stored[0]);
    }
     */

    // Sinon, on utilise l'argument de l'URL.
    [$parent_tid, $include_children] = $this->parseArgument($this->argument);

    $tids = [$parent_tid];

    // Si include_children et pas la racine (ou racine demandant quand même
    // les enfants), on charge l'arborescence.
    if ($include_children) {
      if ($parent_tid === 0) {
        // Racine + enfants = tout → pas de filtre nécessaire.
        return;
      }
      $term = Term::load($parent_tid);
      if ($term) {
        $children = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadTree($term->bundle(), $parent_tid);
        foreach ($children as $child) {
          $tids[] = (int) $child->tid;
        }
      }
    }

    $table_alias  = $this->query->ensureTable('media_field_data');
    $placeholders = [];
    $values       = [];
    foreach ($tids as $i => $tid) {
      $ph             = ':dir_arg_tid_' . $i;
      $placeholders[] = $ph;
      $values[$ph]    = $tid;
    }

    $this->query->addWhereExpression(
    0,
    $table_alias . '.directory IN (' . implode(', ', $placeholders) . ')',
    $values
    );

    // Supprime cet argument contextuel de l'URL pour que les soumissions
    // ultérieures du formulaire exposé ne conservent que les valeurs du formulaire.
    $pos = $this->view->argument[$id]->position ?? NULL;
    if ($pos !== FALSE && isset($this->view->args[$pos])) {
      unset($this->view->args[$pos]);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Formulaire de configuration de l'argument dans l'UI Views.
   */
  protected function defineOptions() {
    $options                             = parent::defineOptions();
    $options['default_tid']              = ['default' => '0'];
    $options['default_include_children'] = ['default' => 0];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    // Charge la liste des termes pour la valeur par défaut.
    $term_options = ['0' => $this->t('- Racine -')];
    $terms        = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree('media_album_av_folders');
    foreach ($terms as $term) {
      $term_options[$term->tid] = str_repeat('-', $term->depth) . ' ' . $term->name;
    }

    $form['default_tid'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Répertoire par défaut'),
      '#description'   => $this->t("Utilisé quand aucun argument n'est présent dans l'URL."),
      '#options'       => $term_options,
      '#default_value' => $this->options['default_tid'] ?? '0',
    ];

    $form['default_include_children'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Inclure les sous-répertoires par défaut'),
      '#default_value' => $this->options['default_include_children'] ?? 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setArgument($arg) {
    if ($arg === '' || $arg === NULL) {
      $tid              = $this->options['default_tid'] ?? '0';
      $include_children = !empty($this->options['default_include_children']) ? '1' : '0';
      $arg              = $tid . ':' . $include_children;
    }

    return parent::setArgument($arg);
  }

}
