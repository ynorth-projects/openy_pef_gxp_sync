<?php

namespace Drupal\openy_pef_gxp_sync\syncer;

/**
 * Class Wrapper.
 *
 * @package Drupal\openy_pef_gxp_sync\syncer.
 */
class Wrapper implements WrapperInterface {

  /**
   * Source Data.
   *
   * @var array
   */
  protected $sourceData;

  /**
   * Processed data.
   *
   * @var array
   */
  protected $processedData = [];

  /**
   * Hashes.
   *
   * @var array
   */
  protected $hashes = [];

  /**
   * {@inheritdoc}
   */
  public function getSourceData() {
    return $this->sourceData;
  }

  /**
   * {@inheritdoc}
   */
  public function setSourceData($locationId, array $data) {
    $this->sourceData[$locationId] = $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getProcessedData() {
    if (!empty($this->processedData)) {
      return $this->processedData;
    }

    $items = $this->process($this->getSourceData());
    $this->setProcessedData($items);

    return $items;
  }

  /**
   * Clean up broken titles.
   *
   * @param array $item
   *   Schedules item.
   */
  private function cleanUpTitle(array &$item) {
    $item['title'] = str_replace('Â', '', $item['title']);
  }

  /**
   * Process data.
   *
   * @param array $data
   *   Source data.
   *
   * @return array
   *   Processed data.
   */
  private function process(array $data) {
    // Group items at first.
    $grouped = [];
    foreach ($data as $locationId => $locationItems) {
      foreach ($locationItems as $item) {
        $grouped[$locationId][$item['class_id']][] = $item;
      }
    }

    $hashes = [];
    $processed = [];

    foreach ($grouped as $locationId => $locationData) {
      foreach ($locationData as $classId => $classData) {
        $hashes[$locationId][$classId] = (string) crc32(serialize($classData));

        foreach ($classData as $class) {
          $this->cleanUpTitle($class);
          $class['location_id'] = $locationId;
          $processed[$locationId][$classId][] = $class;
        }
      }
    }

    $this->hashes = $hashes;

    return $processed;
  }

  /**
   * Set processed data.
   *
   * @param array $data
   *   Data.
   */
  public function setProcessedData(array $data) {
    $this->processedData = $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getHashes() {
    if (!empty($this->hashes)) {
      return $this->hashes;
    }

    $this->getProcessedData();
    return $this->getHashes();
  }

}
