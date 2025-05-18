# Config Pages Export

This module provides functionality to export and import Config Pages content entities between environments using Drupal's core Configuration Management system.

## Overview

Config Pages Export seamlessly integrates with Drupal's configuration management workflow to:

1. Export Config Pages content entities as configuration objects
2. Import Config Pages content entities from configuration
3. Automatically sync Config Pages during standard configuration exports/imports
4. Provide Drush commands for dedicated export/import operations

Unlike traditional approaches that export to PHP or custom YAML files, this module leverages Drupal's built-in configuration system, ensuring that content from Config Pages is properly versioned and deployed with your other configuration.

## Features

- Fully integrates with Drupal's configuration management system
- Automatically exports Config Pages during configuration exports
- Automatically imports Config Pages during configuration imports
- Supports standard workflows using `drush config:export` and `drush config:import`
- Provides dedicated Drush commands for granular control
- Compatible with Config Split for environment-specific variations
- Preserves all field data and relationships
- Compatible with Drupal 9.3+ and Drupal 10
- PHP 8.1+ support

## Requirements

- Drupal 9.3+ or Drupal 10
- PHP 8.1 or higher
- Config Pages module (`config_pages:config_pages`)
- Configuration Manager module (core)

## Installation

1. Install the module using Composer:
   ```bash
   composer require drupal/config_pages_export
   ```

   Or manually download and place in `/web/modules/custom/modules/`.

2. Enable the module:
   ```bash
   drush en config_pages_export -y
   ```

3. After installation, the module will automatically export any existing Config Pages content.

## Usage

### Standard Workflow

After installing the module, Config Pages content will automatically be included in your configuration exports and imports. Simply use the standard Drupal configuration management workflows:

```bash
# Export configuration (includes Config Pages content)
drush config:export

# Import configuration (includes Config Pages content)
drush config:import
```

### Dedicated Commands

For specific Config Pages export/import operations, use these commands:

```bash
# Export only Config Pages content to configuration
drush config_pages:export
# or shorthand
drush cpex

# Import only Config Pages content from configuration
drush config_pages:import
# or shorthand
drush cpim
```

### Deployment Considerations

During deployment between environments, Config Pages content will be automatically imported along with other configuration. The module handles:

- Creation of new Config Pages that don't exist in the target environment
- Updates to existing Config Pages that have changed
- Preservation of field data including complex fields (references, media, etc.)

## How It Works

The module works by:

1. Converting Config Pages content entities to configuration objects with the prefix `config_pages_export.page`
2. Using event subscribers to integrate with Drupal's configuration workflow:
   - `STORAGE_TRANSFORM_EXPORT`: Exports Config Pages to configuration
   - `IMPORT_VALIDATE`: Validates Config Pages before import
   - `IMPORT`: Imports Config Pages from configuration
3. Storing content data with consistent, predictable configuration names based on type and UUID
4. Preserving relationships and complex field data
5. Automatically running during config imports through update hooks

## Troubleshooting

If you encounter issues:

1. Ensure Config Pages types exist before trying to import content
2. Check Drupal logs for detailed error messages
3. Run with drush verbose flag for more information:
   ```bash
   drush cpex -v
   drush cpim -v
   ```
4. Verify that config_pages modules are properly installed and enabled

## Technical Details

- **Configuration Storage**: Config Pages are stored in the configuration system with the pattern `config_pages_export.page.[type].[uuid]`
- **Field Handling**: Complex fields (entity references, files, etc.) are properly serialized to maintain relationships
- **Event System**: The module leverages Drupal's config events system for seamless integration
- **Error Handling**: Robust error handling ensures graceful failure and clear error messages

## Contributing

Contributions are welcome! Please feel free to submit issues and pull requests to improve the module.
