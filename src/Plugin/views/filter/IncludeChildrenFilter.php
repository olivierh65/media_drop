<?php

namespace Drupal\media_drop\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * @ViewsFilter("include_children_filter")
 */
class IncludeChildrenFilter extends FilterPluginBase {

  public $no_operator = TRUE;

  /**
   *
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['value']['default'] = '1';
    $options['display_sub']['default'] = TRUE;
    return $options;
  }

  /**
   */
  public function adminSummary() {
    return $this->t('Inclure les sous-répertoires');
  }

  /**
   *
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    $identifier = $this->options['expose']['identifier'] ?? 'include_children';
    $exposed    = $this->view->getExposedInput();

    $form['value'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Inclure les sous-répertoires'),
      '#options'       => [
        '1' => $this->t('Oui'),
        '0' => $this->t('Non'),
      ],
      '#default_value' => $exposed[$identifier] ?? '0',
      '#attributes'    => ['class' => ['include-children-filter']],
    ];
    $form['display_sub'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Afficher les sous-répertoires dans la vue'),
      '#default_value' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="include_children"]' => ['value' => '1'],
        ],
      ],
      '#attributes' => ['class' => ['include-children-display-sub']],
      '#prefix' => '<div class="include-children-display-sub-wrapper">',
      '#suffix' => '</div>',
    ];
  }

  /**
   */
  public function validateExposed(&$form, FormStateInterface $form_state) {
    // Pas de validation bloquante.
  }

  /**
   *
   */
  public function acceptExposedInput($input) {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }
    $identifier = $this->options['expose']['identifier'] ?? 'include_children';
    if ($input[$identifier] === 'All') {
      $input[$identifier] = 1;
    }
    $this->value = $input[$identifier] ?? '1';
    return TRUE;
  }

  /**
   *
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
      $inc                         = isset($parts[1]) ? (int) (bool) $parts[1] : 0;
      $form[$id]['#default_value'] = $inc;
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
   * Pas de condition SQL : c'est DirectoryFilter qui lit cette valeur.
   */
  public function query() {}

}
