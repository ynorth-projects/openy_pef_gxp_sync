<?php

namespace Drupal\openy_pef_gxp_sync\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure OpenY PEF GXP Sync settings for this site.
 */
class PefGxpSyncSettingsForm extends ConfigFormBase implements ContainerInjectionInterface {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'openy_pef_gxp_sync_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['openy_pef_gxp_sync.enabled_locations'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $enableLocations = $this->config('openy_pef_gxp_sync.enabled_locations');

    $mapping_ids = $this->entityTypeManager->getStorage('mapping')
      ->getQuery()
      ->condition('type', 'location')
      ->condition('field_groupex_id.value', 0, '>')
      ->sort('name', 'ASC')
      ->execute();

    $locations_ids = $this->entityTypeManager->getStorage('mapping')->loadMultiple($mapping_ids);

    // Build options list.
    $options = [];
    foreach ($locations_ids as $location_id => $nodeType) {
      $location_id = $nodeType->toArray();
      $gxp_id = $location_id['field_groupex_id'][0]['value'];
      $options[$gxp_id] = $nodeType->label();
    }

    $form['locations'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Locations Name'),
      '#description' => $this->t('Please, select location, which should be in the Schedules page'),
      '#options' => $options,
      '#default_value' => $enableLocations->get('locations') ?: [],
    ];

    return parent::buildForm($form, $form_state);

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save the config.
    $enabledLocations = array_filter($form_state->getValue('locations'));
    $result = [];
    foreach ($enabledLocations as $gxpLocationId) {
      $result[] = (int) $gxpLocationId;
    }
    $this->config('openy_pef_gxp_sync.enabled_locations')
      ->set('locations', $result)
      ->save();
    $this->configFactory()->reset('openy_pef_gxp_sync.enabled_locations');
    parent::submitForm($form, $form_state);
  }

}
