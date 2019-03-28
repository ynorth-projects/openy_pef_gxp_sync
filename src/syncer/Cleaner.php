<?php

namespace Drupal\openy_pef_gxp_sync\syncer;

use Drupal\Core\Logger\LoggerChannelInterface;

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
  protected $loggerChannel;

  /**
   * Cleaner constructor.
   *
   * @param \Drupal\openy_pef_gxp_sync\syncer\WrapperInterface $wrapper
   *   Wrapper.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel
   *   LoggerChannel.
   */
  public function __construct(WrapperInterface $wrapper, LoggerChannelInterface $loggerChannel) {
    $this->wrapper = $wrapper;
    $this->loggerChannel = $loggerChannel;
  }

  /**
   * {@inheritdoc}
   */
  public function clean() {
    // @todo Remove classes with changed hash by location.
    // @todo Remove classes presented in hash, but not in API.
  }

}
