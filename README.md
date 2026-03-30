# PenguLab

PenguLab is a modern, self-hosted start page for your homelab, server tools, smart home, and web applications.

It is built with plain PHP and JSON, requires no database, and provides a clean tile-based dashboard with categories, configurable grid layouts, drag-and-drop sorting, dark/light mode, import/export, and language pack support.

## Features

- Modern tile-based start page
- Self-hosted, lightweight, and database-free
- Categories for grouping apps
- Shared UI settings stored in `apps.json`
- Configurable grid layout
- Dark mode / light mode
- Drag and drop sorting in edit mode
- Clone existing tiles
- JSON export / import
- Multi-language support through `lang` files
- Automatic detection of additional language packs
- Simple deployment on any standard PHP web server

## Screenshots

### Light Mode
![PenguLab Light Mode](screenshot1.png)

### Dark Mode
![PenguLab Dark Mode](screenshot2.png)

## Requirements

- PHP **8.1+**
- A web server that can run PHP, for example:
  - Apache
  - Nginx + PHP-FPM
  - Lighttpd
  - shared hosting with PHP support
- Write permissions for:
  - `apps.json`
  - the PenguLab project directory, if `apps.json` does not exist yet

## Project Structure

```text
PenguLab/
├── index.php
├── apps.json
└── lang/
    ├── de.json
    └── en.json
```

## Installation

### 1. Upload the files

Upload the following files to your web server:

- `index.php`
- `apps.json`
- the folder `lang/` including at least:
  - `lang/de.json`
  - `lang/en.json`

### 2. Make sure `apps.json` is writable

PenguLab stores app data and shared UI settings in `apps.json`.

On Linux systems, for example:

```bash
chmod 664 apps.json
```

If needed, also ensure the web server user can write to the project directory.

### 3. Open PenguLab in your browser

Navigate to the folder or domain where you uploaded the project.

Example:

```text
https://your-local-domain.example/pengulab/
```

That is all. No database setup is required.

## First Start

When PenguLab starts for the first time, it reads its data from `apps.json`.

The application stores the following shared settings there:

- selected category
- grid columns and rows
- dark/light mode
- selected language

Because these settings are stored in JSON, they are shared across browsers and devices.

## How PenguLab Stores Data

PenguLab stores both application tiles and UI settings inside `apps.json`.

Example structure:

```json
{
  "settings": {
    "selectedCategory": "all",
    "viewMode": "custom",
    "rows": 3,
    "cols": 5,
    "theme": "light",
    "language": "de"
  },
  "apps": [
    {
      "id": "a1b2c3d4",
      "name": "Home Assistant",
      "url": "https://ha.example.local",
      "description": "Smart home dashboard",
      "category": "Smart Home",
      "image": ""
    }
  ]
}
```

### Backward compatibility

Older `apps.json` files that only contain a plain app array are still read correctly.

After the next save operation, PenguLab writes the newer object-based structure automatically.

## Usage

### Add a new tile

1. Open the settings panel
2. Click **New App**
3. Fill in:
   - app name
   - URL
   - description
   - category
   - image/logo
4. Click **Save**

### Edit a tile

1. Open the settings panel
2. Click **Edit**
3. Use the edit button on a tile
4. Change the values
5. Click **Save**

### Clone a tile

1. Edit an existing tile
2. Click **Clone**
3. Change the values you want
4. Save it as a new tile

### Reorder tiles

1. Open the settings panel
2. Click **Edit**
3. Drag and drop the tiles into the order you want
4. The order is saved automatically

### Change the grid size

In the settings panel, change the grid row and column values.

PenguLab saves the new grid immediately when the inputs lose focus or when you confirm with Enter.

### Change the language

Open the settings panel and choose a language in the language dropdown.

The selected language is stored in `apps.json`.

### Change the theme

Use the sun/moon buttons in the settings panel to switch between light mode and dark mode.

The selected theme is stored in `apps.json`.

## Language Packs

PenguLab loads language packs from the `lang/` folder.

Any additional language file placed there is detected automatically and becomes selectable in the language dropdown.

### File naming

Use this format:

```text
lang/<language-code>.json
```

Examples:

```text
lang/de.json
lang/en.json
lang/fr.json
lang/es.json
```

### Recommended language file format

```json
{
  "_meta": {
    "label": "English"
  },
  "brand": "PenguLab",
  "apps": "Apps",
  "settings": "Settings",
  "settings_language": "Language",
  "settings_category": "Category",
  "settings_grid": "Grid",
  "settings_design": "Appearance",
  "settings_actions": "Actions",
  "settings_configuration": "Configuration",
  "all_categories": "All categories",
  "uncategorized": "Uncategorized",
  "edit": "Edit",
  "done": "Done",
  "new_app": "New App",
  "export": "Export",
  "import": "Import",
  "dialog_create": "Create app",
  "dialog_edit": "Edit app",
  "dialog_clone": "Clone tile",
  "field_name": "App name",
  "field_url": "URL",
  "field_description": "Description",
  "field_category": "Category",
  "field_image": "Logo / Image",
  "image_hint": "Uploaded logos are automatically checked for transparent borders so they appear larger and more consistent.",
  "save": "Save",
  "cancel": "Cancel",
  "remove_image": "Remove logo",
  "clone": "Clone",
  "delete": "Delete app",
  "prev": "← Back",
  "next": "Next →",
  "no_description": "No description available.",
  "confirm_delete": "Really delete “{name}”?",
  "light": "Light mode",
  "dark": "Dark mode",
  "page_of": "Page {current} / {total}"
}
```

### Notes

- `_meta.label` is used as the human-readable language name in the dropdown
- the filename (for example `en.json`) is used as the language code
- if a selected language file is missing, PenguLab falls back to German (`de`)

## Import and Export

PenguLab supports JSON export and import through the settings panel.

### Export

Exports the current:

- apps
- categories
- grid settings
- theme
- language
- tile order

### Import

Imports a previously exported PenguLab JSON file.

This makes migration or backup very easy.

## Logo Handling

When you upload a logo, PenguLab automatically checks transparent borders and trims them where possible so the logo appears larger and more consistent inside the tile.

This helps especially with PNG logos that contain a lot of empty transparent space.

## Updating PenguLab

To update PenguLab:

1. Back up your current files
2. Replace `index.php`
3. Keep your existing:
   - `apps.json`
   - `lang/` folder
4. Reload the page

## Customization Ideas

PenguLab is intentionally simple and easy to extend.

Possible future enhancements:

- per-user settings
- authentication
- custom themes
- app health checks
- search
- icons from URLs or favicon lookup
- Docker image / container deployment

## Troubleshooting

### Changes are not saved

Check write permissions for `apps.json`.

### The language dropdown is empty

Make sure the `lang/` folder exists and contains valid JSON files.

### A newly added language does not appear

Check:

- file extension is `.json`
- JSON is valid
- `_meta.label` is set
- the file is placed directly inside `lang/`

### The page looks broken after an update

Make sure all required files were uploaded together:

- `index.php`
- `apps.json`
- `lang/de.json`
- `lang/en.json`

## License

License: **MIT**

## About the Project

PenguLab was designed as a clean, modern, self-hosted launcher for homelab and infrastructure services, while staying simple enough to deploy on almost any PHP-capable web server.
