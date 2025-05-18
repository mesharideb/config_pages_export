<?php

/**
 * @file
 * Post update functions for Config Pages Export module.
 */

/**
 * Import config pages from the exported data file.
 */
function config_pages_export_post_update_import_config_pages(&$sandbox) {
  $module_path = \Drupal::service('extension.list.module')->getPath('config_pages_export');
  $config_pages_data_file = $module_path . '/config_pages_data.php';

  if (!file_exists($config_pages_data_file)) {
    return 'Config pages data file not found: ' . $config_pages_data_file;
  }

  $config_pages_data = require $config_pages_data_file;

  if (empty($config_pages_data)) {
    return 'No config pages data to import.';
  }

  $results = \Drupal::service('config_pages_export.config_pages_handler')->importConfigPages($config_pages_data);

  return sprintf(
    'Config pages import complete: %d created, %d updated, %d skipped.',
    $results['created'],
    $results['updated'],
    $results['skipped']
  );
}
