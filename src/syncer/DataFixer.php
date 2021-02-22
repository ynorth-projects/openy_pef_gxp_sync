<?php

namespace Drupal\openy_pef_gxp_sync\syncer;

use DateTime;
use DateTimeZone;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   * Number of weeks.
   *
   * Number.
   */
  const COUNT_WEEKS = 5;

  /**
   * Cache lifetime in seconds.
   *
   * Seconds.
   */
  const CACHE_LIFE = 3600;

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
   * Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config Factory.
   */
  public function __construct(HttpClientInterface $client, WrapperInterface $wrapper, LoggerChannel $loggerChannel, CacheBackendInterface $cacheBackend, LocationMappingRepository $mappingRepository, ConfigFactoryInterface $configFactory) {
    $this->client = $client;
    $this->wrapper = $wrapper;
    $this->logger = $loggerChannel;
    $this->cacheBackend = $cacheBackend;
    $this->mappingRepository = $mappingRepository;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function fix() {
    $this->logger->info('%name started.', ['%name' => get_class($this)]);
    $locations = $this->mappingRepository->loadAllLocationsWithGroupExId();
    $enableLocations = $this->configFactory->get('openy_pef_gxp_sync.enabled_locations')->get('locations');
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
    foreach (range(1, self::COUNT_WEEKS - 1) as $week_index) {
      $startWeek->modify('+8 day');
      $endWeek = clone $startWeek;
      $endWeek->modify('+7 day');
      $weeks[$week_index] = [
        'start' => $startWeek->getTimestamp(),
        'end' => $endWeek->getTimestamp(),
      ];
    }

    $allEmbedData = [];
    foreach ($weeks as $week_index => $week) {
      foreach ($locations as $location) {
        // Get locations.
        $locationGpxId = $location->field_groupex_id->value;
        $locationId = $location->field_location_ref->target_id;

        // Continue if location disable.
        if (!in_array((int) $locationGpxId, $enableLocations)) {
          continue;
        }

        $timeNow = new DateTime('NOW');
        $timeNow = (int) $timeNow->getTimestamp();

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
          $this->cacheBackend->set(get_class($this) . '_new_' . $locationId . $week_index, $body, $timeNow + self::CACHE_LIFE);
        }
        else {
          $body = $cache->data;
        }

        $allEmbedData[$week_index][$locationId] = $body;

        $this->logger->info('Week index: %week Get data for location ID: %id finish', ['%id' => $locationId, '%week' => $week_index]);
      }
    }

    $data = $this->wrapper->getSourceData();

    // Fix Data.
    $newClasses = [];
    foreach ($data as $locationId => $location) {
      foreach ($location as $class_index => $class) {
        if (!isset($class['recurring']) || !isset($class['title'])) {
          continue;
        }
        foreach ($weeks as $week_index => $week) {
          if (!isset($class)) {
            continue;
          }
          if (isset($allEmbedData[$week_index][$locationId])) {
            // Format time for class.
            $originalStartTime = explode(':', $class['patterns']['start_time']);
            $originalEndTime = explode(':', $class['patterns']['end_time']);
            $startTime = new DateTime('NOW');
            $endTime = new DateTime('NOW');
            $startTime->setTime($originalStartTime[0], $originalStartTime[1]);
            $endTime->setTime($originalEndTime[0], $originalEndTime[1]);
            $classTime = $startTime->format('g:ia') . '-' . $endTime->format('g:ia');

            foreach ($allEmbedData[$week_index][$locationId] as $schedule) {
              $scheduleTitle = trim($schedule['title']);
              // Clear title for compare.
              $scheduleTitle = str_replace('Â®', 'R', $scheduleTitle);
              $scheduleTitle = str_replace('®', 'R', $scheduleTitle);

              // Delete 'CANCELLED:' from title.
              $scheduleTitleCancelled = $scheduleTitle;
              if (!empty(trim($schedule['title']))
                && isset($schedule['canceled'])
                && $schedule['canceled'] == 'true'
              ) {
                $scheduleTitleCancelled = explode(' ', $scheduleTitle);
                unset($scheduleTitleCancelled[0]);
                $scheduleTitleCancelled = implode(' ', $scheduleTitleCancelled);
              }

              // Clear title for compare.
              $classTitle = trim($class['title']);
              $classTitle = str_replace('Â®', 'R', $classTitle);
              $classTitle = str_replace('®', 'R', $classTitle);

              $compareTitle = strcasecmp($scheduleTitle, $classTitle) === 0 || strcasecmp($scheduleTitleCancelled, $classTitle) === 0;
              $compareInstructor = strcasecmp(trim($schedule['original_instructor']), trim($class['instructor'])) === 0;
              $compareStudio = strcasecmp(trim($schedule['studio']), trim($class['studio'])) === 0;
              $compareCategory = strcasecmp(trim($schedule['category']), trim($class['category'])) === 0;
              $compareTime = strcasecmp(trim($schedule['time']), trim($classTime)) === 0;

              if ($compareInstructor && $compareStudio && $compareTitle && $compareCategory && $compareTime) {
                $scheduleId = (int) ($week_index . $schedule['id']);
                // Parse date.
                $date = explode(',', $schedule['date']);

                // Create exclusion for base class and create new class
                // with sub instructor.
                if (!empty(trim($schedule['sub_instructor']))) {
                  // Create new class with sub instructor.
                  if (!isset($newClasses[$locationId][$scheduleId])) {
                    $newClass = $this->createClass($class, implode(',', $date));
                    $newClass['instructor'] = $schedule['sub_instructor'] . '(Sub For: ' . $schedule['original_instructor'] . ')';
                    $newClasses[$locationId][$scheduleId] = $newClass;
                  }
                  else {
                    $newClasses[$locationId][$scheduleId]['instructor'] = $schedule['sub_instructor'] . '(Sub For: ' . $schedule['original_instructor'] . ')';
                  }
                  // Set exclusions for base class.
                  $this->createExclusion($data, $locationId, $class_index, $date);
                }

                // Create exclusion for base class and create new cancelled
                // class.
                if (isset($schedule['canceled']) && $schedule['canceled'] == 'true') {
                  // Create new cancelled class.
                  if (!isset($newClasses[$locationId][$scheduleId])) {
                    $newClass = $this->createClass($class, implode(',', $date));
                    $newClass['title'] = ' CANCELLED: ' . $class['title'];
                    $newClasses[$locationId][$scheduleId] = $newClass;
                  }
                  else {
                    $newClasses[$locationId][$scheduleId]['title'] = ' CANCELLED: ' . $class['title'];
                  }

                  // Set exclusions for base class.
                  $this->createExclusion($data, $locationId, $class_index, $date);
                }
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

  /**
   * {@inheritdoc}
   */
  protected function createClass(array $class, string $date) {
    $newClass = $class;
    $newClass['start_date'] = $date;
    $newClass['end_date'] = $date;
    $newClass['recurring'] = 'none';
    return $newClass;
  }

  /**
   * {@inheritdoc}
   */
  protected  function createExclusion(array &$data, int $locationId, int $class_index, array $date) {
    if (!isset($data[$locationId][$class_index]['exclusions'])) {
      $data[$locationId][$class_index]['exclusions'] = [];
    }
    if (!in_array(trim($date[1] . ', ' . $date[2]), $data[$locationId][$class_index]['exclusions'])) {
      $data[$locationId][$class_index]['exclusions'][] = trim($date[1] . ', ' . $date[2]);
    }
  }

}
