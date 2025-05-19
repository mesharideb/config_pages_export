<?php

namespace Drupal\config_pages_export\Service;

use Drupal\config_pages\Entity\ConfigPages;
use Drupal\config_pages\Entity\ConfigPagesType;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Tracking array for entity ID mappings during import.
   *
   * @var array
   */
  protected $entityIdMap = [];

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
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StorageInterface $active_storage,
    StorageInterface $snapshot_storage,
    Connection $database,
    EntityRepositoryInterface $entity_repository,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->activeStorage = $active_storage;
    $this->snapshotStorage = $snapshot_storage;
    $this->database = $database;
    $this->entityRepository = $entity_repository;
    $this->logger = $logger_factory->get('config_pages_export');
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
    $config_page_data = $this->prepareConfigPageData($config_page);

    // Save the data to the configuration.
    $config = $this->configFactory->getEditable($config_name);
    $config->setData($config_page_data)->save();

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
      'references_created' => 0,
      'references_updated' => 0,
    ];

    // Reset entity ID map for this import.
    $this->entityIdMap = [];

    // Find all config objects with our prefix.
    $config_names = $this->activeStorage->listAll(self::CONFIG_PREFIX);

    try {
      // Start a database transaction for the entire import process
      $transaction = $this->database->startTransaction();

      foreach ($config_names as $config_name) {
        $config_data = $this->activeStorage->read($config_name);

        if (empty($config_data) || empty($config_data['uuid']) || empty($config_data['type'])) {
          $results['skipped']++;
          $results['messages'][] = "Invalid data in config: {$config_name}";
          continue;
        }

        try {
          // Create or import referenced entities first to establish ID mappings.
          if (!empty($config_data['references'])) {
            $created_count = $this->importReferencedEntities($config_data['references']);
            $results['references_created'] += $created_count;
          }

          // Update entity reference fields with new entity IDs.
          if (!empty($config_data['fields']) && !empty($this->entityIdMap)) {
            $this->updateEntityReferenceFields($config_data['fields']);
          }

          // Check if entity with this UUID already exists.
          $existing_entity = $this->entityRepository->loadEntityByUuid('config_pages', $config_data['uuid']);

          if ($existing_entity) {
            // Update existing entity.
            $this->updateConfigPage($existing_entity, $config_data);
            $results['updated']++;
          }
          else {
            // Create new entity.
            $this->createConfigPage($config_data);
            $results['created']++;
          }
        }
        catch (\Exception $e) {
          $results['skipped']++;
          $results['messages'][] = "Error processing {$config_name}: {$e->getMessage()}";
          $this->logger->error("Error processing {$config_name}: {$e->getMessage()}");

          // Don't throw here to allow processing of other config items
        }
      }
    }
    catch (\Exception $e) {
      // If any exception was thrown that wasn't caught above, roll back the transaction
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $this->logger->error('Fatal error during import: @message', ['@message' => $e->getMessage()]);
      $results['messages'][] = 'Import failed: ' . $e->getMessage();
      return $results;
    }

    return $results;
  }

  /**
   * Creates a new Config Page from configuration data.
   *
   * @param array $config_data
   *   The configuration data.
   *
   * @return \Drupal\config_pages\Entity\ConfigPages
   *   The created Config Page entity.
   *
   * @throws \Exception
   *   Throws exception if there's an issue creating the entity.
   */
  protected function createConfigPage(array $config_data): ConfigPages {
    // Create base entity with minimum required data.
    $values = [
      'type' => $config_data['type'],
      'uuid' => $config_data['uuid'],
      'langcode' => $config_data['langcode'] ?? 'en',
    ];

    // Add label if available.
    if (!empty($config_data['fields']['label'][0]['value'])) {
      $values['label'] = $config_data['fields']['label'][0]['value'];
    }

    try {
      // Get the context data BEFORE entity creation
      $type = ConfigPagesType::load($config_data['type']);
      if ($type) {
        $serialized_context = $type->getContextData();
        // Add context to the initial values
        $values['context'] = $serialized_context;
      }

      // Create the entity with context already set
      $config_page = ConfigPages::create($values);

      // Set field values.
      $this->setConfigPageFields($config_page, $config_data);

      // Save the entity.
      $config_page->save();

      return $config_page;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create config page: @message', ['@message' => $e->getMessage()]);
      throw new \Exception("Failed to create config page: " . $e->getMessage());
    }
  }

  /**
   * Updates an existing Config Page with configuration data.
   *
   * @param \Drupal\config_pages\Entity\ConfigPages $config_page
   *   The Config Page entity to update.
   * @param array $config_data
   *   The configuration data.
   *
   * @return \Drupal\config_pages\Entity\ConfigPages
   *   The updated Config Page entity.
   *
   * @throws \Exception
   *   Throws exception if there's an issue updating the entity.
   */
  protected function updateConfigPage(ConfigPages $config_page, array $config_data): ConfigPages {
    try {
      // Set field values.
      $this->setConfigPageFields($config_page, $config_data);

      // Update the context value
      $type = ConfigPagesType::load($config_data['type']);
      if ($type) {
        $serialized_context = $type->getContextData();
        $config_page->set('context', $serialized_context);
      }

      // Save the entity.
      $config_page->save();

      return $config_page;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update config page: @message', ['@message' => $e->getMessage()]);
      throw new \Exception("Failed to update config page: " . $e->getMessage());
    }
  }

  /**
   * Sets field values on a Config Page entity.
   *
   * @param \Drupal\config_pages\Entity\ConfigPages $config_page
   *   The Config Page entity.
   * @param array $config_data
   *   The configuration data.
   */
  protected function setConfigPageFields(ConfigPages $config_page, array $config_data): void {
    // Set field values from data.
    if (!empty($config_data['fields'])) {
      foreach ($config_data['fields'] as $field_name => $field_data) {
        if ($config_page->hasField($field_name)) {
          $config_page->set($field_name, $field_data);
        }
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
    $export_data = [
      'uuid' => $config_page->uuid(),
      'type' => $config_page->bundle(),
      'langcode' => $config_page->language()->getId(),
      'fields' => [],
      'references' => [],
    ];

    // Extract field data.
    $field_definitions = $config_page->getFieldDefinitions();

    foreach ($field_definitions as $field_name => $field_definition) {
      // Skip computed fields and other fields that shouldn't be exported.
      if (in_array($field_name, ['uuid', 'id', 'type', 'langcode', 'context'])) {
        continue;
      }

      if ($config_page->hasField($field_name)) {
        $field_values = $config_page->get($field_name)->getValue();
        if (!empty($field_values)) {
          $export_data['fields'][$field_name] = $field_values;

          // Handle entity reference fields of any type
          $this->processEntityReferenceField($field_definition, $field_values, $export_data['references']);
        }
      }
    }

    return $export_data;
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

  /**
   * Processes entity reference fields, exporting referenced entities.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $field_values
   *   The field values.
   * @param array &$references
   *   Array to store referenced entity data.
   */
  protected function processEntityReferenceField(FieldDefinitionInterface $field_definition, array $field_values, array &$references): void {
    // Only process entity reference fields.
    if (!$field_definition) {
      return;
    }

    $field_type = $field_definition->getType();
    $is_entity_reference = (
      $field_type === 'entity_reference' ||
      $field_type === 'entity_reference_revisions'
    );

    if (!$is_entity_reference) {
      return;
    }

    $target_type = $field_definition->getSetting('target_type');
    $field_name = $field_definition->getName();

    foreach ($field_values as $delta => $value) {
      if (!empty($value['target_id'])) {
        try {
          $entity = $this->entityTypeManager->getStorage($target_type)
            ->load($value['target_id']);

          if ($entity) {
            // Export the referenced entity data.
            $references[$field_name][$delta] = $this->exportReferencedEntity($entity);
          }
        }
        catch (\Exception $e) {
          $this->logger->error('Failed to export @type with ID @id: @message', [
            '@type' => $target_type,
            '@id' => $value['target_id'],
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
  }

  /**
   * Exports a referenced entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to export.
   *
   * @return array
   *   The exported entity data.
   */
  protected function exportReferencedEntity(EntityInterface $entity): array {
    // Basic entity data
    $entity_data = [
      'uuid' => $entity->uuid(),
      'entity_type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'original_id' => $entity->id(),
      'fields' => [],
      'nested_references' => [],
    ];

    // Only process content entities that have fields
    if ($entity instanceof ContentEntityInterface) {
      // Extract field data
      $field_definitions = $entity->getFieldDefinitions();

      foreach ($field_definitions as $field_name => $field_definition) {
        // Skip computed fields and internal fields
        if (in_array($field_name, [
          'uuid', 'id', 'type', 'parent_id', 'parent_type',
          'parent_field_name', 'revision_id', 'revision_default'
        ])) {
          continue;
        }

        if ($entity->hasField($field_name)) {
          $field_values = $entity->get($field_name)->getValue();
          if (!empty($field_values)) {
            $entity_data['fields'][$field_name] = $field_values;

            // Handle nested entity references
            $this->processEntityReferenceField(
              $field_definition,
              $field_values,
              $entity_data['nested_references']
            );
          }
        }
      }
    }

    return $entity_data;
  }

  /**
   * Imports referenced entities from exported data.
   *
   * @param array $references_data
   *   The references data from the export.
   *
   * @return int
   *   Number of entities created.
   */
  protected function importReferencedEntities(array $references_data): int {
    $created_count = 0;

    // Process each field containing references
    foreach ($references_data as $field_name => $field_references) {
      foreach ($field_references as $delta => $entity_data) {
        // First handle nested references to have them available for the parent
        if (!empty($entity_data['nested_references'])) {
          $created_count += $this->importReferencedEntities($entity_data['nested_references']);
        }

        // Create the referenced entity
        try {
          $entity_type = $entity_data['entity_type'];
          $entity = $this->createReferencedEntity($entity_data);
          $created_count++;

          // Store the mapping from original ID to new ID
          if (!empty($entity_data['original_id'])) {
            $this->storeEntityMapping($entity_type, $entity_data['original_id'], $entity);
          }
        }
        catch (\Exception $e) {
          $this->logger->error('Failed to import @type of bundle @bundle: @message', [
            '@type' => $entity_data['entity_type'] ?? 'unknown',
            '@bundle' => $entity_data['bundle'] ?? 'unknown',
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    return $created_count;
  }

  /**
   * Creates a referenced entity from exported data.
   *
   * @param array $entity_data
   *   The entity data.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The created entity.
   */
  protected function createReferencedEntity(array $entity_data): EntityInterface {
    // Check if entity with this UUID already exists
    $existing_entity = $this->entityRepository->loadEntityByUuid(
      $entity_data['entity_type'],
      $entity_data['uuid']
    );

    if ($existing_entity) {
      return $this->updateReferencedEntity($existing_entity, $entity_data);
    }

    $values = [
      'type' => $entity_data['bundle'],
      'uuid' => $entity_data['uuid'],
    ];

    // Create the entity
    $entity_type = $entity_data['entity_type'];
    $entity = $this->entityTypeManager->getStorage($entity_type)->create($values);

    // Set field values - only if it's a content entity
    if ($entity instanceof ContentEntityInterface && !empty($entity_data['fields'])) {
      foreach ($entity_data['fields'] as $field_name => $field_values) {
        if ($entity->hasField($field_name)) {
          // Update entity reference values to use new entity IDs
          if ($this->isEntityReferenceField($entity, $field_name)) {
            $field_definition = $entity->getFieldDefinition($field_name);
            $updated_values = $this->updateEntityReferenceValues($field_values, $field_definition);
            $entity->set($field_name, $updated_values);
          }
          else {
            $entity->set($field_name, $field_values);
          }
        }
      }
    }

    // Save the entity
    $entity->save();

    // Store mapping
    if (!empty($entity_data['original_id'])) {
      $this->storeEntityMapping($entity_type, $entity_data['original_id'], $entity);
    }

    return $entity;
  }

  /**
   * Updates a referenced entity with exported data.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to update.
   * @param array $entity_data
   *   The entity data.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The updated entity.
   */
  protected function updateReferencedEntity(EntityInterface $entity, array $entity_data): EntityInterface {
    // Set field values - only if it's a content entity
    if ($entity instanceof ContentEntityInterface && !empty($entity_data['fields'])) {
      foreach ($entity_data['fields'] as $field_name => $field_values) {
        if ($entity->hasField($field_name)) {
          // Update entity reference values to use new entity IDs
          if ($this->isEntityReferenceField($entity, $field_name)) {
            $field_definition = $entity->getFieldDefinition($field_name);
            $updated_values = $this->updateEntityReferenceValues($field_values, $field_definition);
            $entity->set($field_name, $updated_values);
          }
          else {
            $entity->set($field_name, $field_values);
          }
        }
      }
    }

    // Save the entity
    $entity->save();

    return $entity;
  }

  /**
   * Checks if the field is an entity reference field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if it is an entity reference field, FALSE otherwise.
   */
  protected function isEntityReferenceField(EntityInterface $entity, string $field_name): bool {
    try {
      // Only process if it's a content entity that has field methods
      if (!($entity instanceof ContentEntityInterface)) {
        return FALSE;
      }

      // Check if field exists
      if (!$entity->hasField($field_name)) {
        return FALSE;
      }

      $field_definition = $entity->getFieldDefinition($field_name);
      if (!$field_definition) {
        return FALSE;
      }

      $field_type = $field_definition->getType();
      return $field_type === 'entity_reference' || $field_type === 'entity_reference_revisions';
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Updates entity reference fields with new entity IDs.
   *
   * @param array &$fields
   *   The fields data to update.
   */
  protected function updateEntityReferenceFields(array &$fields): void {
    foreach ($fields as $field_name => &$field_values) {
      foreach ($field_values as $delta => &$value) {
        // Skip if no target_id or if we don't have entity type information
        if (!isset($value['target_id']) || !isset($value['entity_type'])) {
          continue;
        }

        $entity_type = $value['entity_type'];
        $original_id = $value['target_id'];

        // Check if this is a reference that needs updating
        if (isset($this->entityIdMap[$entity_type][$original_id])) {
          // Update with the new ID
          $value['target_id'] = $this->entityIdMap[$entity_type][$original_id]['id'];

          // Update revision ID if present and we have a mapping
          if (isset($value['target_revision_id']) &&
              isset($this->entityIdMap[$entity_type][$original_id]['revision_id'])) {
            $value['target_revision_id'] = $this->entityIdMap[$entity_type][$original_id]['revision_id'];
          }
        }
      }
    }
  }

  /**
   * Updates entity reference values with new IDs.
   *
   * @param array $field_values
   *   The field values to update.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return array
   *   The updated field values.
   */
  protected function updateEntityReferenceValues(array $field_values, FieldDefinitionInterface $field_definition): array {
    $updated_values = $field_values;
    $target_type = $field_definition->getSetting('target_type');

    foreach ($updated_values as $delta => &$value) {
      // Skip if no target_id
      if (!isset($value['target_id'])) {
        continue;
      }

      $original_id = $value['target_id'];

      // Check if this is a reference that needs updating
      if (isset($this->entityIdMap[$target_type][$original_id])) {
        // Update with the new ID
        $value['target_id'] = $this->entityIdMap[$target_type][$original_id]['id'];

        // Update revision ID if present and applicable to this entity type
        if (isset($value['target_revision_id']) &&
            isset($this->entityIdMap[$target_type][$original_id]['revision_id'])) {
          $value['target_revision_id'] = $this->entityIdMap[$target_type][$original_id]['revision_id'];
        }
      }
    }

    return $updated_values;
  }

  /**
   * Stores entity mapping in the entity ID map.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $original_id
   *   The original entity ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to store.
   */
  protected function storeEntityMapping(string $entity_type, string $original_id, EntityInterface $entity) {
    $this->entityIdMap[$entity_type][$original_id] = [
      'id' => $entity->id(),
      'revision_id' => $entity instanceof ContentEntityInterface ? $entity->getRevisionId() : NULL,
    ];
  }

}
