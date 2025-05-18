# Config Pages Export

This module provides functionality to export and import config_pages content entities between environments.

## Features

- Export all config_pages content entities to a PHP file
- Import config_pages content entities from the exported file
- Post-update hook for importing during deployments
- Drush commands for manual export/import operations

## Usage

### Exporting Config Pages

To export all config_pages entities to a PHP file, use the following Drush command:

```bash
drush config-pages-export:export
# or using the alias
drush cpex
```

This will create a file `config_pages_data.php` in the module's root directory containing all config_pages entities.

### Importing Config Pages

To import config_pages entities from the exported file, you can:

1. Use the Drush command:

   ```bash
   drush config-pages-export:import
   # or using the alias
   drush cpim
   ```

2. When deploying to an environment, the post-update hook will automatically run and import all config_pages from the file:

   ```bash
   drush updb
   ```

## Implementation Details

The module exports config_pages content including their field values and preserves UUIDs to ensure proper entity updates.
The exported data can be committed to version control, allowing configuration and content to be deployed together between environments.
