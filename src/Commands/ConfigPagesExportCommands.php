<?php

namespace Drupal\config_pages_export\Commands;

use Drupal\config_pages_export\Service\ConfigPagesHandler;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Config Pages Export module.
 */
class ConfigPagesExportCommands extends DrushCommands {

  /**
   * The config pages handler service.
   *
   * @var \Drupal\config_pages_export\Service\ConfigPagesHandler
   */
  protected $configPagesHandler;

  /**
   * Constructs a new ConfigPagesExportCommands object.
   *
   * @param \Drupal\config_pages_export\Service\ConfigPagesHandler $config_pages_handler
   *   The config pages handler service.
   */
  public function __construct(ConfigPagesHandler $config_pages_handler) {
    parent::__construct();
    $this->configPagesHandler = $config_pages_handler;
  }

  /**
   * Export all ConfigPages entities to a PHP file.
   *
   * @command config-pages-export:export
   * @aliases cpex
   * @usage config-pages-export:export
   *   Export all ConfigPages entities to a PHP file.
   */
  public function exportConfigPages() {
    if ($this->configPagesHandler->exportConfigPages()) {
      $this->logger()->success('ConfigPages entities exported successfully.');
    }
    else {
      $this->logger()->error('Failed to export ConfigPages entities.');
    }
  }

  /**
   * Import ConfigPages entities from the export file.
   *
   * @command config-pages-export:import
   * @aliases cpim
   * @usage config-pages-export:import
   *   Import ConfigPages entities from the export file.
   */
  public function importConfigPages() {
    try {
      // Load the config pages data from the file first.
      $module_path = \Drupal::service('extension.list.module')->getPath('config_pages_export');
      $config_pages_data_file = $module_path . '/config_pages_data.php';

      if (!file_exists($config_pages_data_file)) {
        $this->logger()->error('Config pages data file not found: ' . $config_pages_data_file);
        return;
      }

      $config_pages_data = require $config_pages_data_file;

      if (empty($config_pages_data)) {
        $this->logger()->warning('No config pages data to import.');
        return;
      }

      $results = $this->configPagesHandler->importConfigPages($config_pages_data);
      $this->logger()->success(sprintf(
        'Config pages import complete: %d created, %d updated, %d skipped.',
        $results['created'],
        $results['updated'],
        $results['skipped']
      ));

      foreach ($results['messages'] as $message) {
        $this->logger()->notice($message);
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Error importing config pages: ' . $e->getMessage());
    }
  }

}
