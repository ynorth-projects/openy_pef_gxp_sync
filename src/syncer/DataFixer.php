<?php

namespace Drupal\openy_pef_gxp_sync\syncer;

use DateTime;
use DateTimeZone;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\openy_mappings\LocationMappingRepository;
use Drupal\openy_pef_gxp_sync\OpenYPefGxpSyncException;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Class Fixer.
 *
 * @package Drupal\openy_pef_gxp_sync\syncer
 */
class DataFixer implements DataFixerInterface {

  /**
   * API URL.
   */
  const API_URL = 'https://groupexpro.com/schedule/embed';

  /**
   * Static API timezone.
   *
   * Introduced to avoid errors with daylight savings.
   */
  const API_TIMEZONE = 'America/Chicago';

  /**
   * Http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Wrapper.
   *
   * @var \Drupal\openy_pef_gxp_sync\syncer\WrapperInterface
   */
  protected $wrapper;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannel
   */
  protected $logger;

  /**
   * Cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * Mapping repository.
   *
   * @var \Drupal\openy_mappings\LocationMappingRepository
   */
  protected $mappingRepository;

  /**
   * DataFixer constructor.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   Http client.
   * @param \Drupal\openy_pef_gxp_sync\syncer\WrapperInterface $wrapper
   *   Wrapper.
   * @param \Drupal\Core\Logger\LoggerChannel $loggerChannel
   *   Logger.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   Cache backend.
   * @param \Drupal\openy_mappings\LocationMappingRepository $mappingRepository
   *   Location mapping repo.
   */
  public function __construct(HttpClientInterface $client, WrapperInterface $wrapper, LoggerChannel $loggerChannel, CacheBackendInterface $cacheBackend, LocationMappingRepository $mappingRepository) {
    $this->client = $client;
    $this->wrapper = $wrapper;
    $this->logger = $loggerChannel;
    $this->cacheBackend = $cacheBackend;
    $this->mappingRepository = $mappingRepository;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function fix() {
    // @TODO refactor duplicate code.
    $this->logger->info('%name started.', ['%name' => get_class($this)]);
    $locations = $this->mappingRepository->loadAllLocationsWithGroupExId();

    // Set weeks range.
    $startWeek = new DateTime('now', new DateTimeZone(self::API_TIMEZONE));
    $startWeek->setTimestamp(strtotime("this week"));
    $startWeek->setTime(1, 0);
    $endWeek = clone $startWeek;
    $endWeek->modify('+7 day');
    $weeks = [
      [
        'start' => $startWeek->getTimestamp(),
        'end' => $endWeek->getTimestamp(),
      ],
    ];
    foreach (range(1, 4) as $week_index) {
      $startWeek->modify('+8 day');
      $endWeek = clone $startWeek;
      $endWeek->modify('+7 day');
      $weeks[$week_index] = [
        'start' => $startWeek->getTimestamp(),
        'end' => $endWeek->getTimestamp(),
      ];
    }

    $cancelledData = [];
    $subData = [];
    $allEmbedData = [];
    foreach ($weeks as $week_index => $week) {
      foreach ($locations as $location) {
        // Get locations.
        $locationGpxId = $location->field_groupex_id->value;
        $locationId = $location->field_location_ref->target_id;

        // Get request.
        $cache = $this->cacheBackend->get(get_class($this) . '_new_' . $locationId . $week_index);
        if (!$cache) {
          try {
            $request = $this->client->request('GET', self::API_URL . '?schedule&a=3&location=' . $locationGpxId . '&start=' . $week['start'] . '&end=' . $week['end']);
          }
          catch (GuzzleException $e) {
            throw new OpenYPefGxpSyncException(sprintf('Failed to get schedules for location with ID: %d', $locationGpxId));
          }
          $body = json_decode((string) $request->getBody(), TRUE);
          $this->cacheBackend->set(get_class($this) . '_new_' . $locationId . $week_index, $body, CacheBackendInterface::CACHE_PERMANENT);
        }
        else {
          $body = $cache->data;
        }

        $allEmbedData[$week_index][$locationId] = $body;

        foreach ($body as $schedule) {
          // Get data for cancelled session.
          if (isset($schedule['canceled']) && $schedule['canceled'] == 'true') {
            $tempTitle = $schedule['title'];

            // Delete 'CANCELLED:' from title.
            if (!empty(trim($schedule['title']))) {
              $tempTitle = explode(' ', trim($schedule['title']));
              unset($tempTitle[0]);
              $tempTitle = implode(' ', $tempTitle);
              $tempTitle = str_replace('®', '', $tempTitle);
            }
            // Move original_instructor to instructor.
            $schedule['instructor'] = $schedule['original_instructor'];
            unset($schedule['original_instructor']);

            // Set hash for field title, studio, instructor, category, time.
            $schedule['hash'] = (string) sha1(serialize(
              [
                (string) trim(utf8_encode($tempTitle)),
                (string) trim($schedule['studio']),
                (string) trim($schedule['instructor']),
                (string) trim($schedule['category']),
                (string) trim($schedule['time']),
              ]
            ));

            $cancelledData[$week_index][$locationId][$schedule['id']] = $schedule;
          }
          // Create array for sub instructor.
          if (!empty(trim($schedule['sub_instructor'])) && !isset($schedule['canceled'])) {
            // Move original_instructor to instructor.
            $schedule['instructor'] = $schedule['original_instructor'];
            unset($schedule['original_instructor']);
            $tempTitle = str_replace('®', '', $schedule['title']);
            $schedule['hash'] = (string) sha1(serialize(
              [
                (string) trim(utf8_encode($tempTitle)),
                (string) trim($schedule['studio']),
                (string) trim($schedule['instructor']),
                (string) trim($schedule['category']),
                (string) trim($schedule['time']),
              ]
            ));
            $subData[$week_index][$locationId][$schedule['id']] = $schedule;
          }
        }
        $this->logger->info('Week index: %week Get data for location ID: %id finish', ['%id' => $locationId, '%week' => $week_index]);
      }
    }

    $data = $this->wrapper->getSourceData();

    // Fix Data.
    $newClasses = [];
    foreach ($data as $locationId => $location) {
      foreach ($location as $class_index => $class) {
        foreach ($weeks as $week_index => $week) {
          if (isset($class['recurring'])) {
            // Format time.
            $originalStartTime = explode(':', $class['patterns']['start_time']);
            $originalEndTime = explode(':', $class['patterns']['end_time']);
            $startTime = new DateTime('NOW');
            $endTime = new DateTime('NOW');
            $startTime->setTime($originalStartTime[0], $originalStartTime[1]);
            $endTime->setTime($originalEndTime[0], $originalEndTime[1]);
            $time = $startTime->format('g:ia') . '-' . $endTime->format('g:ia');

            // Set hash for class.
            $tempTitle = str_replace('Â®', '', trim($class['title']));
            $hash = (string) sha1(serialize(
              [
                (string) trim(utf8_encode($tempTitle)),
                (string) trim($class['studio']),
                (string) trim($class['instructor']),
                (string) trim($class['category']),
                (string) trim($time),
              ]
            ));

            // Create exclusion for base class and create new cancelled class.
            if (isset($cancelledData[$week_index][$locationId])) {
              foreach ($cancelledData[$week_index][$locationId] as $schedule) {
                if ($schedule['hash'] == $hash) {
                  // Parse date.
                  $date = explode(',', $schedule['date']);

                  // Create new cancelled class.
                  $newClass = $class;
                  $newClass['title'] = ' CANCELLED: ' . $class['title'];
                  $newClass['start_date'] = implode(',', $date);
                  $newClass['end_date'] = implode(',', $date);
                  $newClass['recurring'] = 'none';
                  $newClasses[$locationId][$schedule['id']] = $newClass;

                  // Set exclusions for base class.
                  if (!isset($data[$locationId][$class_index]['exclusions'])) {
                    $data[$locationId][$class_index]['exclusions'] = [];
                  }
                  if (!in_array(trim($date[1] . ', ' . $date[2]), $data[$locationId][$class_index]['exclusions'])) {
                    $data[$locationId][$class_index]['exclusions'][] = trim($date[1] . ', ' . $date[2]);
                  }

                }
              }
            }

            // Create exclusion for base class and create new class
            // with sub instructor.
            if (isset($subData[$week_index][$locationId])) {
              foreach ($subData[$week_index][$locationId] as $schedule) {
                if ($schedule['hash'] == $hash) {
                  // Parse date.
                  $date = explode(',', $schedule['date']);

                  // Create new class with sub instructor.
                  $newClass = $class;
                  $newClass['start_date'] = implode(',', $date);
                  $newClass['end_date'] = implode(',', $date);
                  $newClass['instructor'] = $schedule['sub_instructor'] . '(Sub For: ' . $schedule['instructor'] . ')';
                  $newClasses[$locationId][$schedule['id']] = $newClass;

                  // Set exclusions for base class.
                  if (!isset($data[$locationId][$class_index]['exclusions'])) {
                    $data[$locationId][$class_index]['exclusions'] = [];
                  }
                  if (!in_array(trim($date[1] . ', ' . $date[2]), $data[$locationId][$class_index]['exclusions'])) {
                    $data[$locationId][$class_index]['exclusions'][] = trim($date[1] . ', ' . $date[2]);
                  }
                }
              }
            }
          }
        }

        // Fix for monthly.
        if (!isset($class['recurring'])) {
          continue;
        }
        if ($class['recurring'] == 'monthly') {
          // Format time.
          $originalStartTime = explode(':', $class['patterns']['start_time']);
          $originalEndTime = explode(':', $class['patterns']['end_time']);
          $startTime = new DateTime('NOW');
          $endTime = new DateTime('NOW');
          $startTime->setTime($originalStartTime[0], $originalStartTime[1]);
          $endTime->setTime($originalEndTime[0], $originalEndTime[1]);
          $time = $startTime->format('g:ia') . '-' . $endTime->format('g:ia');
          foreach ($allEmbedData as $week) {
            foreach ($week[$locationId] as $schedule) {
              if (
                $schedule['original_instructor'] == $class['instructor']
                && $schedule['studio'] == $class['studio']
                && str_replace('®', '', $schedule['title']) == str_replace('Â®', '', trim($class['title']))
                && $schedule['category'] == $class['category']
                && $schedule['category'] == $class['category']
                && trim($schedule['time']) == $time
              ) {
                // Parse date.
                $date = explode(',', $schedule['date']);

                // Create new class without recurring.
                $newClass = $class;
                $newClass['start_date'] = implode(',', $date);
                $newClass['end_date'] = implode(',', $date);
                $newClasses[$locationId][$schedule['id']] = $newClass;
                unset($data[$locationId][$class_index]);
              }
              else {
                unset($data[$locationId][$class_index]);
              }
            }
          }
        }
      }
    }
    $newData = $data;
    foreach ($locations as $location) {
      $locationId = $location->field_location_ref->target_id;
      if (isset($newClasses[$locationId])) {
        $newData[$locationId] = array_merge($newClasses[$locationId], $data[$locationId]);
      }
    }
    $this->wrapper->updateSourceData($newData);
    $this->logger->info('%name finish.', ['%name' => get_class($this)]);
  }

}
