<?php

namespace Drupal\openy_pef_gxp_sync\syncer;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\openy_pef_gxp_sync\OpenYPefGxpMappingRepository;

/**
 * Class Cleaner.
 *
 * @package Drupal\openy_pef_gxp_sync\syncer
 */
class Cleaner implements CleanerInterface {

  /**
   * Wrapper.
   *
   * @var \Drupal\openy_pef_gxp_sync\syncer\WrapperInterface
   */
  protected $wrapper;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Mapping repo.
   *
   * @var \Drupal\openy_pef_gxp_sync\OpenYPefGxpMappingRepository
   */
  protected $mappingRepo;

  /**
   * State.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Cleaner constructor.
   *
   * @param \Drupal\openy_pef_gxp_sync\syncer\WrapperInterface $wrapper
   *   Wrapper.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel
   *   LoggerChannel.
   * @param \Drupal\openy_pef_gxp_sync\OpenYPefGxpMappingRepository $openYPefGxpMappingRepository
   *   Mapping repository.
   * @param \Drupal\Core\State\StateInterface $state
   *   State.
   */
  public function __construct(WrapperInterface $wrapper, LoggerChannelInterface $loggerChannel, OpenYPefGxpMappingRepository $openYPefGxpMappingRepository, StateInterface $state) {
    $this->wrapper = $wrapper;
    $this->logger = $loggerChannel;
    $this->mappingRepo = $openYPefGxpMappingRepository;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function clean() {
    $this->logger->info('%name started.', ['%name' => get_class($this)]);

    $dataToRemove = $this->wrapper->getDataToRemove();
    foreach ($dataToRemove as $locationId => $locationData) {
      foreach ($locationData as $classId) {
        $this->mappingRepo->removeByLocationIdAndClassId($locationId, $classId);
      }
    }

    // Clear hashes data in state and unlock new sync.
    $this->state->delete('openy_pef_gxp_sync_hashes');

    $this->logger->info('%name finished.', ['%name' => get_class($this)]);
  }

}
