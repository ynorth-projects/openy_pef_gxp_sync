<?php

namespace Drupal\openy_pef_gxp_sync\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\openy_mappings\LocationMappingRepository;

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
   * Mapping repository.
   *
   * @var \Drupal\ymca_mappings\LocationMappingRepository
   */
  protected $mappingRepository;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\openy_mappings\LocationMappingRepository $mappingRepository
   *   Location mapping repo.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, LocationMappingRepository $mappingRepository) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->mappingRepository = $mappingRepository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('openy_mappings.location_repository')
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

    $locations = $this->mappingRepository->loadAllLocationsWithGroupExId();

    // Build options list.
    $options = [];
    foreach ($locations as $location_id => $nodeType) {
      $location_id = $nodeType->toArray();
      $gxp_id = $location_id['field_groupex_id'][0]['value'];
      $options[$gxp_id] = $nodeType->label();
    }

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => $this
        ->t('This configuration form enables locations to be displayed on the New Schedules experience for GroupEx classes page (/y-schedules-locations). If none locations are selected, New Schedules will display all locations by default. See <a target=_blank href="https://youtu.be/SBdcWi8Xr-U">video overview</a> '),
    ];

    $form['locations'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled Locations'),
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
