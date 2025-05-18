<?php

namespace Drupal\config_pages_export\Service;

use Drupal\config_pages\Entity\ConfigPages;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Service for handling config pages import/export operations.
 */
class ConfigPagesHandler {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

  /**
   * Constructs a new ConfigPagesHandler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module extension list.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    ModuleExtensionList $module_list,
    TranslationInterface $string_translation,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->moduleList = $module_list;
    $this->setStringTranslation($string_translation);
  }

  /**
   * Export all ConfigPages entities to a PHP file.
   *
   * @return bool
   *   Returns TRUE if the export was successful.
   */
  public function exportConfigPages() {
    try {
      $config_pages_types = $this->entityTypeManager
        ->getStorage('config_pages_type')
        ->loadMultiple();

      if (empty($config_pages_types)) {
        return FALSE;
      }

      $config_pages_data = [];

      foreach ($config_pages_types as $type) {
        $type_id = $type->id();

        // Load config pages of this type.
        $config_pages = $this->entityTypeManager
          ->getStorage('config_pages')
          ->loadByProperties(['type' => $type_id]);

        if (!empty($config_pages)) {
          foreach ($config_pages as $id => $config_page) {
            $data = [];

            // Get all fields for the entity.
            $field_names = array_keys($config_page->toArray());

            // Process each field.
            foreach ($field_names as $field_name) {
              // Skip computed fields and other fields that shouldn't be exported.
              if ($field_name === 'uuid' || $field_name === 'id') {
                continue;
              }

              if ($config_page->hasField($field_name)) {
                $field_values = $config_page->get($field_name)->getValue();
                if (!empty($field_values)) {
                  $data[$field_name] = $field_values;
                }
              }
            }

            $config_pages_data[$type_id][$id] = [
              'uuid' => $config_page->uuid(),
              'type' => $config_page->bundle(),
              'langcode' => $config_page->language()->getId(),
              'data' => $data,
            ];
          }
        }
      }

      if (empty($config_pages_data)) {
        return FALSE;
      }

      // Generate the PHP file with the exported data.
      $export_dir = $this->moduleList->getPath('config_pages_export');

      // Validate and sanitize the export directory.
      if (!$this->fileSystem->prepareDirectory($export_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        return FALSE;
      }

      $export_file = $export_dir . '/config_pages_data.php';

      $content = "<?php\n\n/**\n * @file\n * Contains exported ConfigPages entities.\n */\n\n";
      $content .= '$config_pages_data = ' . var_export($config_pages_data, TRUE) . ";\n\n";
      $content .= "return \$config_pages_data;\n";

      $this->fileSystem->saveData($content, $export_file, FileSystemInterface::EXISTS_REPLACE);
      return TRUE;
    }
    catch (\Exception $e) {
      \Drupal::logger('config_pages_export')->error('Failed to export ConfigPages: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Import config pages from the export data.
   *
   * @param array $config_pages_data
   *   Array of config pages data to import.
   *
   * @return array
   *   Array of results about the import process.
   */
  public function importConfigPages(array $config_pages_data) {
    $results = [
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'messages' => [],
    ];

    foreach ($config_pages_data as $type_id => $config_pages) {
      foreach ($config_pages as $id => $page_data) {
        // Check if entity with this UUID already exists.
        $existing_entities = $this->entityTypeManager
          ->getStorage('config_pages')
          ->loadByProperties(['uuid' => $page_data['uuid']]);

        $existing_entity = reset($existing_entities);

        try {
          if ($existing_entity) {
            // Update existing entity.
            foreach ($page_data['data'] as $field_name => $values) {
              if ($existing_entity->hasField($field_name)) {
                $existing_entity->set($field_name, $values);
              }
            }
            $existing_entity->save();
            $results['updated']++;
            $results['messages'][] = $this->t('Updated config page: @id', ['@id' => $id]);
          }
          else {
            // Create new entity.
            $config_page = ConfigPages::create([
              'type' => $page_data['type'],
              'langcode' => $page_data['langcode'],
              'uuid' => $page_data['uuid'],
            ]);

            foreach ($page_data['data'] as $field_name => $values) {
              if ($config_page->hasField($field_name)) {
                $config_page->set($field_name, $values);
              }
            }

            $config_page->save();
            $results['created']++;
            $results['messages'][] = $this->t('Created config page: @id', ['@id' => $id]);
          }
        }
        catch (\Exception $e) {
          $results['skipped']++;
          $results['messages'][] = $this->t('Error importing config page @id: @error', [
            '@id' => $id,
            '@error' => $e->getMessage(),
          ]);
        }
      }
    }

    return $results;
  }

}
