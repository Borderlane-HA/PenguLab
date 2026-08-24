# Changelog

## 2.0.0-alpha.2

- Added an interactive Proxmox VE 8/9 LXC installer with Debian 13 and Debian 12 fallback.
- Added separate Stable, Pre-release and exact-version installation modes for Proxmox.
- Added `pengulabctl` inside Proxmox LXC installations for status, updates, logs, backups, restarts and release-channel selection.
- Added automatic backups before Proxmox image updates and retention of the ten newest backups.
- Added documented side-by-side pre-release testing using a separate Proxmox LXC.
- Split Docker publishing into `latest` (stable) and `prerelease` (alpha/beta/RC) moving tags.
- Fixed the release workflow so GitHub pre-releases no longer overwrite the stable `latest` Docker tag.
- Added manual workflow inputs for republishing an exact source ref to a selected Docker channel.

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
