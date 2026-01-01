<?php

namespace Drupal\media_drop\Trait;

use Drupal\taxonomy\Entity\Term;

/**
 * Trait for shared directory selection functionality.
 *
 * Provides reusable methods for handling hierarchical directory selection
 * using jstree across different modules.
 */
trait DirectorySelectionTrait {

  /**
   * Get directory data for jstree.
   *
   * Converts a hierarchical taxonomy structure into JSON format
   * suitable for jstree initialization. Includes weight for drag-and-drop
   * reorganization.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param int|null $selected_tid
   *   Optional selected term ID.
   *
   * @return array
   *   Array of tree nodes for jstree with weight information.
   */
  protected function getDirectoryTreeData($vocabulary_id, $selected_tid = NULL) {
    $entity_type_manager = $this->getEntityTypeManager();

    $terms = $entity_type_manager
      ->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, 0, NULL, TRUE);

    $tree = [];
    foreach ($terms as $term) {
      $parent_id = 0;
      if ($term->parent && !empty($term->parent->target_id)) {
        $parent_id = $term->parent->target_id;
      }
      if ($parent_id == 0) {
        $tree[] = $this->buildTreeNode($term, $vocabulary_id, $selected_tid);
      }
    }

    return $tree;
  }

  /**
   * Recursively build tree nodes for jstree.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The taxonomy term.
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param int|null $selected_tid
   *   Optional selected term ID.
   *
   * @return array
   *   Tree node array with weight information.
   */
  private function buildTreeNode(Term $term, $vocabulary_id, $selected_tid = NULL) {
    $entity_type_manager = $this->getEntityTypeManager();

    // Get the weight of the term from its properties.
    $weight = 0;
    if ($term->hasField('weight')) {
      $weight = (int) $term->get('weight')->value;
    }

    $node = [
      'id' => $term->id(),
      'text' => $term->getName(),
      'data' => [
        'weight' => $weight,
      ],
      'state' => [
        'selected' => $selected_tid && $selected_tid == $term->id(),
      ],
    ];

    // Load children.
    $children_terms = $entity_type_manager
      ->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, $term->id(), 1, TRUE);

    if (!empty($children_terms)) {
      $node['children'] = [];
      foreach ($children_terms as $child_term) {
        $node['children'][] = $this->buildTreeNode($child_term, $vocabulary_id, $selected_tid);
      }
    }

    return $node;
  }

  /**
   * Get term options for select element (fallback for non-jstree).
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param int $parent
   *   Parent term ID.
   * @param int $depth
   *   Current depth for indentation.
   *
   * @return array
   *   Term options array.
   */
  protected function getTermOptions($vocabulary_id, $parent = 0, $depth = 0) {
    $entity_type_manager = $this->getEntityTypeManager();
    $options = [];

    $terms = $entity_type_manager
      ->getStorage('taxonomy_term')
      ->loadTree($vocabulary_id, $parent, 1, TRUE);

    foreach ($terms as $term) {
      $prefix = str_repeat('--', $depth);
      $options[$term->id()] = $prefix . ' ' . $term->getName();

      // Recursively get children.
      $children = $this->getTermOptions($vocabulary_id, $term->id(), $depth + 1);
      if (!empty($children)) {
        $options += $children;
      }
    }

    return $options;
  }

  /**
   * Create a new directory term.
   *
   * @param string $vocabulary_id
   *   The vocabulary ID.
   * @param string $term_name
   *   The term name.
   * @param int $parent_id
   *   Parent term ID.
   *
   * @return \Drupal\taxonomy\Entity\Term
   *   The created term.
   *
   * @throws \Exception
   */
  protected function createDirectoryTerm($vocabulary_id, $term_name, $parent_id = 0) {
    $term = Term::create([
      'vid' => $vocabulary_id,
      'name' => $term_name,
      'parent' => $parent_id ? [$parent_id] : [],
    ]);

    $term->save();

    return $term;
  }

  /**
   * Get the entity type manager service.
   *
   * This method should be implemented by the using class to return
   * the EntityTypeManagerInterface instance.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  abstract protected function getEntityTypeManager();

}
