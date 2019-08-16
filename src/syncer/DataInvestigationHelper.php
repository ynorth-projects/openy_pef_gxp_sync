<?php

namespace Drupal\openy_pef_gxp_sync\syncer;

/**
 * Class InvestigationHelper.
 *
 * Used for investigation of data structure.
 *
 * @package Drupal\openy_pef_gxp_sync\syncer
 */
class DataInvestigationHelper {

  /**
   * Data to investigate.
   *
   * @var array
   */
  protected $data;

  public function __construct() {
    $this->data = [];
  }

  public function setData($data) {
    $this->data = $data;
  }

  /**
   * Get all possible values for "recurring" property.
   *
   * @return array
   */
  public function getPossibleRecurringValues() {
    $filtered = [];
    foreach ($this->data as $locationId => $classes) {
      foreach ($classes as $class) {
        $type = '-n/a-';
        if (array_key_exists('recurring', $class)) {
          $type = trim($class['recurring']);
          if (empty($type)) {
            $type = '-empty-';
          }
        }

        if (!isset($filtered[$type])) {
          $filtered[$type] = 0;
        }
        $filtered[$type]++;
      }
    }

    return $filtered;
  }

  /**
   * Help investigate duplicate classes for Studio C, Friday 09:10, Blaisdell.
   *
   * Filter the classes by the next criteria:
   *  - Location: Blaisdell
   *  - Room: Studio C
   *  - Day: Friday
   *  - Time: 09:10
   *
   * @return array
   */
  public function getFridaysCardioAtBlaisdellAtNineTen() {
    $filtered = [];
    foreach ($this->data as $locationId => $classes) {
      if ($locationId == 5)  {
        foreach ($classes as $class) {
          if (trim($class['studio']) != 'Studio C') {
            continue;
          }

          if ($class['patterns']['day'] != 'Friday') {
            continue;
          }

          if ($class['patterns']['start_time'] != '09:10') {
            continue;
          }

          $filtered[] = $class;
        }
      }
    }

    return $filtered;
  }
}
