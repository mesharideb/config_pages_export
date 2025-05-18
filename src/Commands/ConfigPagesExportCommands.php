<?php

namespace Drupal\config_pages_export\Commands;

use Drupal\config_pages_export\Service\ConfigPagesExportManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drush commands for Config Pages Export module.
 */
class ConfigPagesExportCommands extends DrushCommands {

  use StringTranslationTrait;

  /**
   * The Config Pages export manager.
   *
   * @var \Drupal\config_pages_export\Service\ConfigPagesExportManager
   */
  protected $exportManager;

  /**
   * Constructs a new ConfigPagesExportCommands object.
   *
   * @param \Drupal\config_pages_export\Service\ConfigPagesExportManager $export_manager
   *   The Config Pages export manager.
   */
  public function __construct(ConfigPagesExportManager $export_manager) {
    parent::__construct();
    $this->exportManager = $export_manager;
  }

  /**
   * Export Config Pages entities to configuration.
   *
   * @command config_pages:export
   * @aliases cp-export,cpex
   * @usage config_pages:export
   *   Export all Config Pages entities to configuration.
   *
   * @return int
   *   Return code: 0 for success, 1 for failure.
   */
  public function exportConfigPages(): int {
    try {
      $exported = $this->exportManager->exportConfigPages();

      if (!empty($exported)) {
        $count = count($exported);
        $this->logger()->success($this->t('Exported @count Config Pages to configuration.', ['@count' => $count])->render());
        $this->logger()->success($this->t('Use "drush config:export" to include them in your next configuration export.')->render());

        // In verbose mode, show all exported config names.
        if ($this->output()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
          $this->logger()->info($this->t('Exported the following Config Pages:')->render());
          foreach ($exported as $config_name) {
            $this->logger()->info($config_name);
          }
        }
      }
      else {
        $this->logger()->notice($this->t('No Config Pages found to export.')->render());
      }
    }
    catch (\Exception $e) {
      $this->logger()->error($this->t('Error exporting Config Pages: @message', ['@message' => $e->getMessage()])->render());
      return 1;
    }

    return 0;
  }

  /**
   * Import Config Pages from configuration.
   *
   * @command config_pages:import
   * @aliases cp-import,cpim
   * @usage config_pages:import
   *   Import all Config Pages from configuration.
   *
   * @return int
   *   Return code: 0 for success, 1 for failure.
   */
  public function importConfigPages(): int {
    try {
      $results = $this->exportManager->importConfigPages();

      if ($results['created'] > 0 || $results['updated'] > 0) {
        $this->logger()->success($this->t('Imported Config Pages: @created created, @updated updated, @skipped skipped.', [
          '@created' => $results['created'],
          '@updated' => $results['updated'],
          '@skipped' => $results['skipped'],
        ])->render());

        // In verbose mode, show all messages.
        if ($this->output()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
          foreach ($results['messages'] as $message) {
            $this->logger()->info($message);
          }
        }
      }
      else {
        $this->logger()->notice($this->t('No Config Pages imported.')->render());
      }
    }
    catch (\Exception $e) {
      $this->logger()->error($this->t('Error importing Config Pages: @message', ['@message' => $e->getMessage()])->render());
      return 1;
    }

    return 0;
  }

}
