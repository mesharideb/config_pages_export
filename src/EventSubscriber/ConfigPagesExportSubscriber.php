<?php

namespace Drupal\config_pages_export\EventSubscriber;

use Drupal\config_pages_export\Service\ConfigPagesExportManager;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\StorageTransformEvent;

/**
 * Event subscriber for Config Pages export/import events.
 */
class ConfigPagesExportSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The Config Pages export manager.
   *
   * @var \Drupal\config_pages_export\Service\ConfigPagesExportManager
   */
  protected $exportManager;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ConfigPagesExportSubscriber.
   *
   * @param \Drupal\config_pages_export\Service\ConfigPagesExportManager $export_manager
   *   The Config Pages export manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    ConfigPagesExportManager $export_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
  ) {
    $this->exportManager = $export_manager;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[ConfigEvents::STORAGE_TRANSFORM_EXPORT] = ['onConfigExport', 100];
    $events[ConfigEvents::IMPORT_VALIDATE] = ['onConfigImportValidate', 100];
    $events[ConfigEvents::IMPORT] = ['onConfigImport', 100];
    return $events;
  }

  /**
   * Reacts to configuration export event.
   *
   * This ensures Config Pages content is saved to configuration before export.
   *
   * @param \Drupal\Core\Config\StorageTransformEvent|null $event
   *   The storage transform event.
   */
  public function onConfigExport(?StorageTransformEvent $event): void {
    $logger = $this->loggerFactory->get('config_pages_export');

    try {
      // Export all Config Pages to configuration.
      $exported = $this->exportManager->exportConfigPages();

      if (!empty($exported)) {
        // Only log to system log, not to UI messages to keep output clean.
        $logger->debug('Exported @count Config Pages to configuration.', [
          '@count' => count($exported),
        ]);
      }
    }
    catch (\Exception $e) {
      $logger->error('Error exporting Config Pages: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Reacts to configuration import validation.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The configuration import event.
   */
  public function onConfigImportValidate(ConfigImporterEvent $event): void {
    // Validate the config pages configuration to import.
    $logger = $this->loggerFactory->get('config_pages_export');

    try {
      // Get the source storage from the config importer to check config pages.
      $config_importer = $event->getConfigImporter();
      $storage_comparer = $config_importer->getStorageComparer();
      $source_storage = $storage_comparer->getSourceStorage();

      // Find all config objects with our module's prefix.
      $config_names = $source_storage->listAll(ConfigPagesExportManager::CONFIG_PREFIX);

      if (!empty($config_names)) {
        // Debug level logging is only visible in verbose mode.
        $logger->debug('Validating @count Config Pages to be imported.', [
          '@count' => count($config_names),
        ]);
      }
    }
    catch (\Exception $e) {
      $logger->error('Error validating Config Pages: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Reacts to configuration import events.
   *
   * This ensures Config Pages content is imported after configuration import.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The configuration import event.
   */
  public function onConfigImport(ConfigImporterEvent $event): void {
    $logger = $this->loggerFactory->get('config_pages_export');

    try {
      // Import all Config Pages from configuration.
      $results = $this->exportManager->importConfigPages();

      if ($results['created'] > 0 || $results['updated'] > 0) {
        // Debug level logging only shows in verbose mode.
        $logger->debug('Imported Config Pages: @created created, @updated updated, @skipped skipped.', [
          '@created' => $results['created'],
          '@updated' => $results['updated'],
          '@skipped' => $results['skipped'],
        ]);
      }

      // Only log warnings and errors to the messages.
      foreach ($results['messages'] as $message) {
        // Store individual messages at debug level only.
        $logger->debug($message);
      }
    }
    catch (\Exception $e) {
      $logger->error('Error importing Config Pages: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Only show critical errors in the UI.
      $this->messenger->addError($this->t('Error importing Config Pages: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

}
