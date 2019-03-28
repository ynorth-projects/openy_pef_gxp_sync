<?php

namespace Drupal\openy_pef_gxp_sync;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Class OpenYPefGxpMappingRepository.
 *
 * @package Drupal\openy_pef_gxp_sync
 */
class OpenYPefGxpMappingRepository {

  /**
   * Chunk size for entity removal.
   */
  const CHUNK_DELETE = 50;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $loggerChannel;

  /**
   * OpenYPefGxpMappingRepository constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel
   *   Logger channel.
   */
  public function __construct(EntityTypeManager $entityTypeManager, LoggerChannelInterface $loggerChannel) {
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerChannel = $loggerChannel;
  }

  /**
   * Get mapping items by product id.
   *
   * @param string $productId
   *   Product ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The list of founded items.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function getMappingByProductId($productId) {
    return $this->entityTypeManager
      ->getStorage('openy_pef_gxp_mapping')
      ->loadByProperties(['product_id' => $productId]);
  }

  /**
   * Remove all mappings.
   *
   * Referenced sessions will also be removed.
   */
  public function removeAll() {
    $storage = $this->entityTypeManager->getStorage('openy_pef_gxp_mapping');

    $query = $storage->getQuery('openy_pef_gxp_mapping');
    $ids = $query->execute();
    if (!$ids) {
      return;
    }

    $chunks = array_chunk($ids, self::CHUNK_DELETE);

    foreach ($chunks as $chunk) {
      $entities = $storage->loadMultiple($chunk);
      $storage->delete($entities);
      $this->loggerChannel->debug(
        'Chunk of %chunk openy_pef_gxp_mapping entities has been deleted.',
        ['%chunk' => self::CHUNK_DELETE]
      );
    }
  }

}
