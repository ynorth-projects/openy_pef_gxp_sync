<?php

namespace Drupal\openy_pef_gxp_sync\syncer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\openy_pef_gxp_sync\Entity\OpenYPefGxpMapping;
use Drupal\openy_pef_gxp_sync\OpenYPefGxpMappingRepository;

/**
 * Class Saver.
 *
 * @package Drupal\openy_pef_gxp_sync\syncer
 */
class Saver implements SaverInterface {

  /**
   * Default number of items to proceed in Demo mode.
   */
  const DEMO_MODE_ITEMS = 5;

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
   * Syncer config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $syncerConfig;

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
   * Saver constructor.
   *
   * @param \Drupal\openy_pef_gxp_sync\syncer\WrapperInterface $wrapper
   *   Wrapper.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel
   *   Logger channel.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   Syncer config.
   * @param \Drupal\openy_pef_gxp_sync\OpenYPefGxpMappingRepository $mappingRepository
   *   Mapping repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   */
  public function __construct(WrapperInterface $wrapper, LoggerChannelInterface $loggerChannel, ImmutableConfig $config, OpenYPefGxpMappingRepository $mappingRepository, EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $configFactory) {
    $this->wrapper = $wrapper;
    $this->logger = $loggerChannel;
    $this->syncerConfig = $config;
    $this->mappingRepository = $mappingRepository;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;

    $openyGxpConfig = $this->configFactory->get('openy_gxp.settings');
    $this->programSubcategory = $openyGxpConfig->get('activity');
  }

  /**
   * Check if we are in demo mode.
   *
   * @return bool
   *   TRUE if in demo mode.
   */
  protected function isDemo() {
    if (!$this->syncerConfig->get('is_production')) {
      // Demo mode is ON.
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    $data = $this->wrapper->getProcessedData();

    // In demo mode we publish only 5 locations and 5 classes for each.
    $locationsIncrement = 0;

    $demoModeCount = self::DEMO_MODE_ITEMS;
    if ($configDemoModeCount = $this->syncerConfig->get('demo_mode_items')) {
      $demoModeCount = $configDemoModeCount;
    }

    foreach ($data as $locationId => $locationData) {
      $classesIncrement = 0;

      // Exit if we've processed demo number of locations.
      if ($this->isDemo() && $locationsIncrement >= $demoModeCount) {
        return;
      }

      foreach ($locationData as $classId => $items) {
        foreach ($items as $item) {
          // Break if we've processed demo number of classes.
          if ($this->isDemo() && $classesIncrement >= $demoModeCount) {
            break 2;
          }

          try {
            $session = $this->createSession($item);
            $mapping = OpenYPefGxpMapping::create(
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
          $classesIncrement++;
        }
      }
      $locationsIncrement++;
    }
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
    catch (\Exception $exception) {
      $message = sprintf(
        'Failed to get class for Groupex class %s with message %s',
        $class['class_id'],
        $exception->getMessage()
      );
      throw new \Exception($message);
    }

    // Get session time paragraph.
    try {
      $sessionTime = $this->getSessionTime($class);
    }
    catch (\Exception $exception) {
      $message = sprintf(
        'Failed to get session time for Groupex class %s with message %s',
        $class['class_id'],
        $exception->getMessage()
      );
      throw new \Exception($message);
    }

    // Get session_exclusions.
    $sessionExclusions = $this->getSessionExclusions($class);

    $session = Node::create([
      'uid' => 1,
      'lang' => 'und',
      'type' => 'session',
      'title' => $class['title'],
    ]);

    $session->set('field_session_class', $sessionClass);
    $session->set('field_session_time', $sessionTime);
    $session->set('field_session_exclusions', $sessionExclusions);
    $session->set('field_session_location', ['target_id' => $class['location_id']]);
    $session->set('field_session_room', $class['studio']);
    $session->set('field_session_instructor', $class['instructor']);

    $session->setUnpublished();

    $session->save();
    $this->logger
      ->debug(
        'Session has been created. ID: @id',
        ['@id' => $session->id()]
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
    $exclusions = [];
    if (isset($class['exclusions'])) {
      foreach ($class['exclusions'] as $exclusion) {
        $exclusionStart = (new \DateTime($exclusion . '00:00:00'))->format('Y-m-d\TH:i:s');
        $exclusionEnd = (new \DateTime($exclusion . '24:00:00'))->format('Y-m-d\TH:i:s');
        $exclusions[] = [
          'value' => $exclusionStart,
          'end_value' => $exclusionEnd,
        ];
      }
    }

    return $exclusions;
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
    $times = $class['patterns'];

    // Convert to UTC timezone to save to database.
    $siteTimezone = new \DateTimeZone(drupal_get_user_timezone());
    $gmtTimezone = new \DateTimeZone('GMT');

    $startTime = new \DateTime($class['start_date'] . ' ' . $times['start_time'] . ':00', $siteTimezone);
    $startTime->setTimezone($gmtTimezone);

    $endTime = new \DateTime($class['end_date'] . ' ' . $times['end_time'] . ':00', $siteTimezone);
    $endTime->setTimezone($gmtTimezone);

    $startDate = $startTime->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    $endDate = $endTime->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);

    if (!isset($times['day']) || empty($times['day'])) {
      throw new \Exception(sprintf('Day was not found for the class %s', $class['class_id']));
    }

    $days[] = strtolower($times['day']);

    $paragraph = Paragraph::create(['type' => 'session_time']);
    $paragraph->set('field_session_time_days', $days);
    $paragraph->set('field_session_time_date', ['value' => $startDate, 'end_value' => $endDate]);
    $paragraph->isNew();
    $paragraph->save();

    return [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
  }

  /**
   * Create class or use existing.
   *
   * @param array $class
   *   Class properties.
   *
   * @return array
   *   Class.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function getClass(array $class) {
    // Try to get existing activity.
    $existingActivities = \Drupal::entityQuery('node')
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
      $activity = Node::load($activityId);
    }

    // Try to find class.
    $existingClasses = \Drupal::entityQuery('node')
      ->condition('title', $class['title'])
      ->condition('field_class_activity', $activity->id())
      ->condition('field_class_description', $class['description'])
      ->execute();

    if (!empty($existingClasses)) {
      $classId = reset($existingClasses);
      $class = Node::load($classId);
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
        'title' => $class['title'],
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

}
