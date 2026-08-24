# Changelog

## 2.0.0-alpha.1

- Rebuilt PenguLab around a small core plus PenguHub packages.
- Added SQLite persistence and automatic PenguLab 1.x migration.
- Moved IP Manager from a monolithic PHP add-on into an isolated package with its own tables, API, page and dashboard widget.
- Reworked IP Manager UX around assigned addresses and free-IP suggestions.
- Added draggable/resizable dashboard widgets.
- Added Apps, Integrations, PenguHub and Settings views.
- Added global Ctrl+K search across apps, integrations and IP Manager data.
- Added Pi-hole v6, AdGuard Home and OPNsense read-only integration connectors.
- Added RSS and Generic API packages.
- Added encrypted server-side credential storage and TLS verification by default.
- Added JSON export/import without integration secrets.
- Preserved legacy 1.x source files under `legacy/` for migration reference.
