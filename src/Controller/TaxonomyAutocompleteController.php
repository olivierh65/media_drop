<?php

namespace Drupal\media_drop\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for taxonomy term autocomplete in VBO modals.
 */
class TaxonomyAutocompleteController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new TaxonomyAutocompleteController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Autocomplete callback for taxonomy terms.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $vocabularies
   *   Comma-separated list of vocabulary IDs.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with matching terms.
   */
  public function autocomplete(Request $request, $vocabularies) {
    $input = $request->query->get('q', '');
    $matches = [];

    if (empty($input) || strlen($input) < 2) {
      return new JsonResponse($matches);
    }

    // Parse vocabulary IDs.
    $vocab_ids = array_filter(array_map('trim', explode(',', $vocabularies)));

    if (empty($vocab_ids)) {
      return new JsonResponse($matches);
    }

    // Query for matching terms.
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', $vocab_ids, 'IN')
      ->condition('name', '%' . $input . '%', 'LIKE')
      ->sort('name', 'ASC')
      ->range(0, 10)
      ->accessCheck(FALSE);

    $term_ids = $query->execute();

    if (!empty($term_ids)) {
      $terms = $storage->loadMultiple($term_ids);

      foreach ($terms as $term) {
        // Format: "term_id|term_label" for Drupal's entity_autocomplete widget.
        $matches[] = [
          'value' => $term->id() . '|' . $term->label(),
          'label' => $term->label(),
        ];
      }
    }

    return new JsonResponse($matches);
  }

}
