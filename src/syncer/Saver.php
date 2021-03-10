<?php

namespace Drupal\openy_pef_gxp_sync\syncer;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\openy_pef_gxp_sync\OpenYPefGxpMappingRepository;

/**
 * Class Saver.
 *
 * @package Drupal\openy_pef_gxp_sync\syncer
 */
class Saver implements SaverInterface {

  /**
   * Debug mode fore development.
   */
  const DEBUG = 0;

  /**
   * Static API timezone.
   *
   * Introduced to avoid errors with daylight savings.
   */
  const API_TIMEZONE = 'America/Chicago';

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
   * Program subcategory.
   *
   * @var int
   */
  protected $programSubcategory;

  /**
   * Mapping repository.
   *
   * @var \Drupal\openy_pef_gxp_sync\OpenYPefGxpMappingRepository
   */
  protected $mappingRepository;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Time.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Saver constructor.
   *
   * @param \Drupal\openy_pef_gxp_sync\syncer\WrapperInterface $wrapper
   *   Wrapper.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel
   *   Logger channel.
   * @param \Drupal\openy_pef_gxp_sync\OpenYPefGxpMappingRepository $mappingRepository
   *   Mapping repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time.
   */
  public function __construct(WrapperInterface $wrapper, LoggerChannelInterface $loggerChannel, OpenYPefGxpMappingRepository $mappingRepository, EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $configFactory, TimeInterface $time) {
    $this->wrapper = $wrapper;
    $this->logger = $loggerChannel;
    $this->mappingRepository = $mappingRepository;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;
    $this->time = $time;

    $openyGxpConfig = $this->configFactory->get('openy_gxp.settings');
    $this->programSubcategory = $openyGxpConfig->get('activity');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function save() {
    $this->logger->info('%name started.', ['%name' => get_class($this)]);
    $this->wrapper->setSavedHashes();

    $data = $this->wrapper->getDataToCreate();

    // Use this code debug specific items.
    if (self::DEBUG) {
      // Use for pinpoint hard items.
      foreach ($data as $locationId => $locationData) {
//        if ($locationId != 5) {
//          unset($data[$locationId]);
//          continue;
//        }

        foreach ($locationData as $classId => $classItems) {
          foreach ($classItems as $sessionId => $class) {
//            if ($class['recurring'] != 'biweekly') {
//              unset($data[$locationId][$classId][$sessionId]);
//            }

//            if (!isset($class['exclusions'])) {
//              unset($data[$locationId][$classId][$sessionId]);
//            }

            if ($class['patterns']['day'] != 'Friday') {
              unset($data[$locationId][$classId][$sessionId]);
            }

            if ($class['studio'] != 'Studio 3') {
              unset($data[$locationId][$classId][$sessionId]);
            }

            if ($class['patterns']['start_time'] != '13:30') {
              unset($data[$locationId][$classId][$sessionId]);
            }

//            if ($class['instructor'] != 'Bruce Tyler') {
//              unset($data[$locationId][$classId][$sessionId]);
//            }

//            if ($class['instructor'] != 'Sandra Breuer (Sub For: Rachael Crew-Reyes)') {
//              unset($data[$locationId][$classId][$sessionId]);
//            }

            if (empty($data[$locationId][$classId])) {
              unset($data[$locationId][$classId]);
            }

            if (empty($data[$locationId])) {
              unset($data[$locationId]);
            }
          }
        }
      }
    }

    // GroupEx can return complete duplicates for some items. Checking here.
    $duplicates = [];

    foreach ($data as $locationId => $locationData) {
      foreach ($locationData as $classId => $classItems) {
        foreach ($classItems as $class) {
          $hash = md5(serialize($class));
          if (in_array($hash, $duplicates)) {
            continue;
          }

          $duplicates[] = $hash;

          // Check if class is corrupted.
          if (!array_key_exists('patterns', $class)) {
            $message = 'Got corrupted class (Unknown data): String: %string';
            $this->logger->error($message, ['%string' => serialize($class)]);
            continue;
          }

          try {
            $session = $this->createSession($class);
            $mapping = $this->entityTypeManager->getStorage('openy_pef_gxp_mapping')->create(
              [
                'session' => $session,
                'product_id' => $classId,
                'location_id' => $locationId,
              ]
            );
            $mapping->save();
          }
          catch (\Exception $exception) {
            $this->logger
              ->error(
                'Failed to create a session with error message: @message',
                ['@message' => $exception->getMessage()]
              );
            continue;
          }
        }
      }
    }

    $msg = '%name finished successfully.';
    if (empty($data)) {
      $msg .= ' Nothing to create.';
    }
    $this->logger->info($msg, ['%name' => get_class($this)]);
  }

  /**
   * Create session.
   *
   * @param array $class
   *   Class properties.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Session node.
   *
   * @throws \Exception
   */
  private function createSession(array $class) {
    // Get/Create class.
    try {
      $sessionClass = $this->getClass($class);
    }
    catch (Exception $exception) {
      $message = sprintf(
        'Failed to get class for Groupex class %s with message %s',
        $class['class_id'],
        $exception->getMessage()
      );
      throw new Exception($message);
    }

    // Get session time paragraph.
    try {
      $sessionTimes = $this->getSessionTime($class);
    }
    catch (Exception $exception) {
      $message = sprintf(
        'Failed to get session time for Groupex class %s with message %s',
        $class['class_id'],
        $exception->getMessage()
      );
      throw new Exception($message);
    }

    // Get session_exclusions.
    $sessionExclusions = $this->getSessionExclusions($class);

    // Get register link.
    $sessionRegLink = $this->getSessionRegLink($class);

    $session = Node::create([
      'uid' => 1,
      'lang' => 'und',
      'type' => 'session',
      'title' => $class['title'],
    ]);

    $session->set('field_session_class', $sessionClass);
    $session->set('field_session_time', $sessionTimes);
    $session->set('field_session_exclusions', $sessionExclusions);
    $session->set('field_session_location', ['target_id' => $class['location_id']]);
    $session->set('field_session_room', $class['studio']);
    $session->set('field_session_instructor', $class['instructor']);
    $session->set('field_session_reg_link', $sessionRegLink);
    $session->set('field_productid', isset($class['reservation_id']) ? $class['reservation_id'] : '');

    $session->setUnpublished();

    $session->save();
    $this->logger
      ->debug(
        'Session has been created. ID: %id, Location ID: %loc_id, Class ID: %class_id',
        [
          '%id' => $session->id(),
          '%loc_id' => $class['location_id'],
          '%class_id' => $class['class_id'],
        ]
      );

    return $session;
  }

  /**
   * Get session exclusions.
   *
   * @param array $class
   *   Class properties.
   *
   * @return array
   *   Exclusions.
   *
   * @throws \Exception
   */
  private function getSessionExclusions(array $class) {
    $exclusionDateFormat = 'Y-m-d\TH:i:s';
    $exclusions = [];

    if (isset($class['exclusions'])) {
      foreach ($class['exclusions'] as $exclusion) {
        $exclusionStart = new \DateTime($exclusion . '00:00:00', new \DateTimeZone(self::API_TIMEZONE));
        $exclusionStart->setTimezone(new \DateTimeZone('GMT'));
        $exclusionEnd = new \DateTime($exclusion . '00:00:00', new \DateTimeZone(self::API_TIMEZONE));
        $exclusionEnd->modify('+1 day');
        $exclusionEnd->setTimezone(new \DateTimeZone('GMT'));
        $exclusions[] = [
          'value' => $exclusionStart->format($exclusionDateFormat),
          'end_value' => $exclusionEnd->format($exclusionDateFormat),
        ];
      }
    }

    if ($class['recurring'] == 'monthly') {
      $patterns = $class['patterns'];
      $startTime = $this->convertDateToDateTime($class['start_date'], $patterns['start_time']);
      $endTime = $this->convertDateToDateTime($class['end_date'], $patterns['end_time']);
      $monthlyExclusions = $this->createExclusionsMonthly($startTime, $endTime, $exclusionDateFormat);
      $exclusions = array_merge($exclusions, $monthlyExclusions);
    }

    // Generate custom exclusions for biweekly classes.
    if ($class['recurring'] == 'biweekly') {
      $interval = new \DateInterval('P2W');
      $patterns = $class['patterns'];

      $startTime = $this->convertDateToDateTime($class['start_date'], $patterns['start_time']);
      $startTime->modify('+1 week');

      $endTime = $this->convertDateToDateTime($class['end_date'], $patterns['end_time']);

      $period = new \DatePeriod($startTime, $interval, $endTime);
      $today = new \DateTime('T00:00:00');
      $maxDate = clone $today;
      $maxDate->modify('+2 month');

      /** @var \DateTime $delta */
      foreach ($period as $delta) {
        if ($delta < $today) {
          continue;
        }

        if ($delta > $maxDate) {
          continue;
        }

        /** @var \DateTime $exclusionStartDate */
        $exclusionStartDate = clone $delta;
        $exclusionStartDate->setTimezone(new \DateTimeZone(self::API_TIMEZONE));
        $exclusionStartDate->setTime(0, 0, 0);

        /** @var \DateTime $exclusionEndDate */
        $exclusionEndDate = clone $exclusionStartDate;
        $exclusionEndDate->modify('+1 day');

        $exclusions[] = [
          'value' => $exclusionStartDate->setTimezone(new \DateTimeZone('GMT'))->format($exclusionDateFormat),
          'end_value' => $exclusionEndDate->setTimezone(new \DateTimeZone('GMT'))->format($exclusionDateFormat),
        ];
      }
    }

    return $exclusions;
  }

  /**
   * Get register link.
   *
   * @param array $class
   *   Class properties.
   *
   * @return array
   *   Link field array.
   *
   * @throws \Exception
   */
  private function getSessionRegLink(array $class) {
    $regLink = [];

    if (isset($class['reservation_id']) && !empty($class['reservation_id'])) {
      $regLink = [
        'uri' => 'https://www.groupexpro.com/gxp/reservations/start/index/' . $class['reservation_id'],
        'title' => 'Sign Up',
      ];
    }

    return $regLink;
  }

  /**
   * Convert date & time string into GMT timezone DateTime object.
   *
   * @param string $dateString
   *   Date string.
   * @param string $timeString
   *   Time string.
   *
   * @return \DateTime
   *   Object.
   *
   * @throws \Exception
   */
  private function convertDateToDateTime($dateString, $timeString) {
    $dateParts = date_parse($dateString);
    $timeParts = date_parse($timeString);
    $dateTime = new \DateTime('now', new \DateTimeZone(self::API_TIMEZONE));
    $dateTime->setDate($dateParts['year'], $dateParts['month'], $dateParts['day']);
    $dateTime->setTime($timeParts['hour'], $timeParts['minute'], $timeParts['second']);
    $dateTime->setTimezone(new \DateTimeZone('GMT'));
    return $dateTime;
  }

  /**
   * Create session time paragraph.
   *
   * @param array $class
   *   Class properties.
   *
   * @return array
   *   Paragraph ID & Revision ID.
   *
   * @throws \Exception
   */
  private function getSessionTime(array $class) {
    $patterns = $class['patterns'];

    $startDateTime = $this->convertDateToDateTime($class['start_date'], $patterns['start_time']);
    $endDateTime = $this->convertDateToDateTime($class['end_date'], $patterns['end_time']);

    $startDate = $startDateTime->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    $endDate = $endDateTime->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);

    $days = [];
    if (isset($patterns['day']) && !empty($patterns['day'])) {
      $days[] = strtolower($patterns['day']);
    }

    // Check if we got not standard pattern.
    $standardPatternItems = [
      'monday',
      'tuesday',
      'wednesday',
      'thursday',
      'friday',
      'saturday',
      'sunday',
    ];

    // Set repeat for monthly recurring every day.
    if ($class['recurring'] == 'monthly') {
      $days = $standardPatternItems;
    }

    // Set repeat for daily recurring every day.
    if ($class['recurring'] == 'daily') {
      $days = $standardPatternItems;
    }

    if (!in_array(strtolower($class['patterns']['day']), $standardPatternItems)) {
      $message = sprintf(
        'Non supported day string was found. Class ID: %s, string: %s.',
        $class['class_id'],
        $class['patterns']['day']
      );
      throw new \Exception($message);
    }

    $paragraphTimes = [
      [
        'startTime' => $startDate,
        'endTime' => $endDate,
      ],
    ];

    $paragraphs = $this->createSessionTimeParagraphs($days, $paragraphTimes);
    return $paragraphs;
  }

  /**
   * Create time paragraphs.
   *
   * @param array $days
   *   Array with days.
   * @param array $times
   *   Start & End times.
   *
   * @return array
   *   Array with time paragraphs.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function createSessionTimeParagraphs(array $days, array $times) {
    $paragraphs = [];
    foreach ($times as $item) {
      $paragraph = Paragraph::create(['type' => 'session_time']);
      $paragraph->set('field_session_time_days', $days);
      $paragraph->set('field_session_time_date',
        [
          'value' => $item['startTime'],
          'end_value' => $item['endTime'],
        ]
      );
      $paragraph->isNew();
      $paragraph->save();

      $paragraphs[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }

    return $paragraphs;
  }

  /**
   * Create class or use existing.
   *
   * @param array $class
   *   Class data.
   *
   * @return array
   *   Class references.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function getClass(array $class) {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Try to get existing activity.
    $existingActivities = $nodeStorage->getQuery()
      ->condition('title', $class['category'])
      ->condition('type', 'activity')
      ->condition('field_activity_category', $this->programSubcategory)
      ->execute();

    if (!$existingActivities) {
      // No activities found. Create one.
      $activity = Node::create([
        'uid' => 1,
        'lang' => 'und',
        'type' => 'activity',
        'title' => $class['category'],
        'field_activity_description' => [
          [
            'value' => $class['description'],
            'format' => 'full_html',
          ],
        ],
        'field_activity_category' => [['target_id' => $this->programSubcategory]],
      ]);

      $activity->save();
    }
    else {
      // Use the first found existing one.
      $activityId = reset($existingActivities);
      $activity = $nodeStorage->load($activityId);
    }

    // Remove CANCELLED from class.
    $title = trim($class['title']);
    $titleArray = explode(' ', trim($class['title']));
    if ($titleArray[0] === 'CANCELLED:') {
      unset($titleArray[0]);
      $title = implode(' ', $titleArray);
    }

    // Try to find class.
    $existingClasses = $nodeStorage->getQuery()
      ->condition('type', 'class')
      ->condition('title', $title)
      ->condition('field_class_activity', $activity->id())
      ->execute();

    if (!empty($existingClasses)) {
      $description = trim($class['description']);
      $classId = reset($existingClasses);
      /* @var Node $class*/
      $class = $nodeStorage->load($classId);
      if (strcmp($class->get('field_class_description')->getString(), $description) !== 0) {
        $class->setNewRevision(TRUE);
        $class->setRevisionLogMessage('Update class description from api for node:' . $class->id());
        $class->setRevisionCreationTime($this->time->getRequestTime());
        $class->set('field_class_description', $description);
        $class->save();
      }
    }
    else {
      $paragraphs = [];
      foreach (['class_sessions', 'branches_popup_class'] as $type) {
        $paragraph = Paragraph::create(['type' => $type]);
        $paragraph->isNew();
        $paragraph->save();
        $paragraphs[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
      }
      $class = Node::create([
        'uid' => 1,
        'lang' => 'und',
        'type' => 'class',
        'title' => $title,
        'field_class_description' => [
          [
            'value' => $class['description'],
            'format' => 'full_html',
          ],
        ],
        'field_class_activity' => [
          [
            'target_id' => $activity->id(),
          ],
        ],
        'field_content' => $paragraphs,
      ]);

      $class->save();
    }

    return [
      'target_id' => $class->id(),
      'target_revision_id' => $class->getRevisionId(),
    ];
  }

  /**
   * Create exclusions for monthly.
   *
   * @param \DateTime $begin
   *   Time.
   * @param \DateTime $end
   *   Time.
   * @param string $exclusionDateFormat
   *   String.
   *
   * @return array
   *   Exclusions.
   *
   * @throws \Exception
   */
  private function createExclusionsMonthly(\DateTime $begin, \DateTime $end, string $exclusionDateFormat) {
    $now = new \DateTime('now');

    // Case for a completed session.
    if ($now > $end) {
      return [];
    };

    $rangeStart = clone $begin;
    $rangeStart->setDate($now->format('Y'), $now->format('m'), 1);
    $interval = new \DateInterval('P1M');
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+4 month');
    $rangeEnd->setDate($rangeEnd->format('Y'), $rangeEnd->format('m'), $begin->format('d'));
    $dateRange = new \DatePeriod($rangeStart, $interval, $rangeEnd);

    $dates = [];

    // Create range for monthly.
    foreach ($dateRange as $date) {
      // Case for feb.
      if ($date->format('m') == '02' && (int) $begin->format('d') > 28) {
        $date->modify("last day of this month");
        $dates[] = $date;
        continue;
      }
      // Case for last day of month.
      if ((int) $begin->format('d') > 30) {
        $date->modify("last day of this month");
        $dates[] = $date;
        continue;
      }
      $date->setDate($date->format('Y'), $date->format('m'), $begin->format('d'));
      $dates[] = $date;
    }


    // Create exclusions for monthly.
    $exclusions = [];
    $firstExclusionsStart = clone $dates[0];
    $firstExclusionsStart->modify('-1 month');
    $firstExclusionsStart->setTime(0, 0, 0);
    $firstExclusionsEnd = clone $dates[0];
    $firstExclusionsEnd->modify('-1 day');
    $firstExclusionsEnd->setTime(23, 59, 0);
    $exclusions[] = [
      'value' => $firstExclusionsStart->setTimezone(new \DateTimeZone('GMT'))->format($exclusionDateFormat),
      'end_value' => $firstExclusionsEnd->setTimezone(new \DateTimeZone('GMT'))->format($exclusionDateFormat),
    ];
    foreach ($dates as $date) {
      $startExclusions = clone $date;
      $startExclusions->modify('+1 day');
      $startExclusions->setTime(0, 0, 0);

      $endExclusions = clone $date;
      $endExclusions->modify('+1 month');
      $endExclusions->modify('-1 day');
      $endExclusions->setTime(23, 59, 0);
      // Case for feb.
      if ($date->format('m') == '01' && (int) $begin->format('d') > 28) {
        $endExclusions = clone $date;
        $endExclusions->modify("last day of next month");
        $endExclusions->modify('-1 day');
        $endExclusions->setTime(23, 59, 0);
      }
      // Case for last day of month.
      if ((int) $begin->format('d') > 30) {
        $endExclusions = clone $date;
        $endExclusions->modify("last day of next month");
        $endExclusions->modify('-1 day');
        $endExclusions->setTime(23, 59, 0);
      }

      $exclusions[] = [
        'value' => $startExclusions->setTimezone(new \DateTimeZone('GMT'))->format($exclusionDateFormat),
        'end_value' => $endExclusions->setTimezone(new \DateTimeZone('GMT'))->format($exclusionDateFormat),
      ];
    }
    return $exclusions;
  }

}
