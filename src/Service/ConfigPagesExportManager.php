<?php

namespace Drupal\config_pages_export\Service;

use Drupal\config_pages\Entity\ConfigPages;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for exporting and importing Config Pages content as configuration.
 */
class ConfigPagesExportManager {

  /**
   * Config prefix for exported config pages.
   */
  const CONFIG_PREFIX = 'config_pages_export.page';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The active configuration storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * The snapshot configuration storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $snapshotStorage;

  /**
   * Constructs a new ConfigPagesExportManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\StorageInterface $active_storage
   *   The active configuration storage.
   * @param \Drupal\Core\Config\StorageInterface $snapshot_storage
   *   The snapshot configuration storage.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StorageInterface $active_storage,
    StorageInterface $snapshot_storage,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->activeStorage = $active_storage;
    $this->snapshotStorage = $snapshot_storage;
  }

  /**
   * Exports all Config Pages entities to configuration.
   *
   * @return array
   *   Array of exported config names.
   */
  public function exportConfigPages(): array {
    $exported = [];

    // Get all config page types.
    $config_page_types = $this->entityTypeManager
      ->getStorage('config_pages_type')
      ->loadMultiple();

    if (empty($config_page_types)) {
      return $exported;
    }

    // Process each config page type.
    foreach ($config_page_types as $type) {
      // Load config pages of this type.
      $config_pages = $this->entityTypeManager
        ->getStorage('config_pages')
        ->loadByProperties(['type' => $type->id()]);

      if (empty($config_pages)) {
        continue;
      }

      // Process each config page.
      foreach ($config_pages as $config_page) {
        $this->exportConfigPage($config_page);
        $exported[] = $this->getConfigName($config_page);
      }
    }

    return $exported;
  }

  /**
   * Exports a single Config Page entity to configuration.
   *
   * @param \Drupal\config_pages\Entity\ConfigPages $config_page
   *   The Config Page entity to export.
   *
   * @return string
   *   The name of the exported configuration.
   */
  public function exportConfigPage(ConfigPages $config_page): string {
    $config_name = $this->getConfigName($config_page);
    $data = $this->prepareConfigPageData($config_page);

    // Save the data to the configuration.
    $config = $this->configFactory->getEditable($config_name);
    $config->setData($data)->save();

    return $config_name;
  }

  /**
   * Imports all Config Pages from configuration.
   *
   * @return array
   *   Results of the import.
   */
  public function importConfigPages(): array {
    $results = [
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'messages' => [],
    ];

    // Find all config objects with our prefix.
    $config_names = $this->activeStorage->listAll(self::CONFIG_PREFIX);

    foreach ($config_names as $config_name) {
      $data = $this->activeStorage->read($config_name);

      if (empty($data) || empty($data['uuid']) || empty($data['type'])) {
        $results['skipped']++;
        // Only record errors, not standard operations.
        $results['messages'][] = "Invalid data in config: {$config_name}";
        continue;
      }

      try {
        // Check if entity with this UUID already exists.
        $existing_entities = $this->entityTypeManager
          ->getStorage('config_pages')
          ->loadByProperties(['uuid' => $data['uuid']]);

        $existing_entity = reset($existing_entities);

        if ($existing_entity) {
          // Update existing entity.
          $this->updateConfigPage($existing_entity, $data);
          $results['updated']++;
          // Don't add normal operations to messages.
        }
        else {
          // Create new entity.
          $this->createConfigPage($data);
          $results['created']++;
          // Don't add normal operations to messages.
        }
      }
      catch (\Exception $e) {
        $results['skipped']++;
        $results['messages'][] = "Error processing {$config_name}: {$e->getMessage()}";
      }
    }

    return $results;
  }

  /**
   * Creates a new Config Page from configuration data.
   *
   * @param array $data
   *   The configuration data.
   *
   * @return \Drupal\config_pages\Entity\ConfigPages
   *   The created Config Page entity.
   */
  protected function createConfigPage(array $data): ConfigPages {
    // Create base entity with minimum required data.
    $values = [
      'type' => $data['type'],
      'uuid' => $data['uuid'],
      'langcode' => $data['langcode'] ?? 'en',
    ];

    $config_page = ConfigPages::create($values);

    // Set field values.
    $this->setConfigPageFields($config_page, $data);

    // Save and return the entity.
    $config_page->save();
    return $config_page;
  }

  /**
   * Updates an existing Config Page with configuration data.
   *
   * @param \Drupal\config_pages\Entity\ConfigPages $config_page
   *   The Config Page entity to update.
   * @param array $data
   *   The configuration data.
   *
   * @return \Drupal\config_pages\Entity\ConfigPages
   *   The updated Config Page entity.
   */
  protected function updateConfigPage(ConfigPages $config_page, array $data): ConfigPages {
    // Set field values.
    $this->setConfigPageFields($config_page, $data);

    // Save and return the entity.
    $config_page->save();
    return $config_page;
  }

  /**
   * Sets field values on a Config Page entity.
   *
   * @param \Drupal\config_pages\Entity\ConfigPages $config_page
   *   The Config Page entity.
   * @param array $data
   *   The configuration data.
   */
  protected function setConfigPageFields(ConfigPages $config_page, array $data): void {
    // Set field values from data.
    foreach ($data['fields'] as $field_name => $field_data) {
      if ($config_page->hasField($field_name)) {
        $config_page->set($field_name, $field_data);
      }
    }
  }

  /**
   * Prepares Config Page data for configuration export.
   *
   * @param \Drupal\config_pages\Entity\ConfigPages $config_page
   *   The Config Page entity.
   *
   * @return array
   *   The prepared data.
   */
  protected function prepareConfigPageData(ConfigPages $config_page): array {
    $data = [
      'uuid' => $config_page->uuid(),
      'type' => $config_page->bundle(),
      'langcode' => $config_page->language()->getId(),
      'fields' => [],
    ];

    // Extract field data.
    $field_names = array_keys($config_page->toArray());

    foreach ($field_names as $field_name) {
      // Skip computed fields and other fields that shouldn't be exported.
      if (in_array($field_name, ['uuid', 'id', 'type', 'langcode', 'context'])) {
        continue;
      }

      if ($config_page->hasField($field_name)) {
        $field_values = $config_page->get($field_name)->getValue();
        if (!empty($field_values)) {
          $data['fields'][$field_name] = $field_values;
        }
      }
    }

    return $data;
  }

  /**
   * Gets the configuration name for a Config Page entity.
   *
   * @param \Drupal\config_pages\Entity\ConfigPages $config_page
   *   The Config Page entity.
   *
   * @return string
   *   The configuration name.
   */
  public function getConfigName(ConfigPages $config_page): string {
    return self::CONFIG_PREFIX . '.' . $config_page->bundle() . '.' . $config_page->uuid();
  }

}
