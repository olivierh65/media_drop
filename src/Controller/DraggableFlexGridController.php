<?php

namespace Drupal\media_drop\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 *
 */
class DraggableFlexGridController extends ControllerBase {

  /**
   * The private tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Constructs a new DraggableFlexGridController.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempstore_factory
   *   The private tempstore factory.
   */
  public function __construct(PrivateTempStoreFactory $tempstore_factory) {
    $this->tempStoreFactory = $tempstore_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.private')
    );
  }

  /**
   *
   */
  public function saveOrder(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $order = $data['order'] ?? [];

    $tempstore = $this->tempStoreFactory->get('media_drop');
    $tempstore->set('ordered_media_ids', $order);

    return new JsonResponse(['status' => 'success']);
  }

}
