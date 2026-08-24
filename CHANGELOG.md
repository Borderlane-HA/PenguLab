# Changelog

## 2.0.0-alpha.5

### Added
- Added per-network IP Manager discovery with an active Nmap scan and optional OPNsense ARP enrichment.
- Added one-click import of discovered devices into the normal device editor with IP, hostname and MAC prefilled.
- Added per-device gateway, DNS and DHCP-reservation documentation.
- Added configurable integration widget contents. Pi-hole/AdGuard can toggle stats, graph, clients and controls; OPNsense can toggle system, gateway, RAM, traffic graph and WireGuard.
- Added a sampled OPNsense WAN traffic mini-graph and gateway/RAM status metrics.
- Added per-app-widget layout selection: automatic, icon above text, icon beside text, or icon-only.
- Added Proxmox local/web-console root auto-login for new appliance installs; SSH service is disabled by the installer.

### Changed
- OPNsense connector now uses the diagnostics ARP/interface APIs and gateway/WireGuard status APIs when the API user has matching privileges.
- IP Manager device table now shows gateway, DNS and DHCP-reservation state.
- New app widgets default to a vertical icon-over-text layout.
- Docker image now includes `nmap` for network discovery.


## 2.0.0-alpha.4

### Added
- Added Pi-hole v6 and AdGuard Home protection controls directly to dashboard widgets: resume, pause for 5 minutes, and pause indefinitely.
- Restored server-side automatic favicon discovery for apps, with a manual “reload favicon” action in the app editor.
- Added a compact app-library view for larger Homelabs, category chips, search, and a compact/details view switch.
- Added adaptive app shortcut widgets that can be resized down to 1×1.

### Fixed
- Fixed app dashboard widgets snapping back to a wider size after saving; app widgets now support a minimum width of one grid column on both client and server.
- Pi-hole widgets now read the real blocking state from `/api/dns/blocking` instead of assuming protection is active.
- Proxmox installer now installs the Debian `docker-cli` package explicitly and uses `C.UTF-8` during package installation to avoid locale warnings.

### Changed
- New app dashboard shortcuts default to a compact 2×1 size instead of 3×2.
- DNS control actions are explicit PenguHub permissions (`service.control`) and are routed server-side so credentials never reach the browser.

## 2.0.0-alpha.3

### Fixed
- Fixed startup failure on Docker bind mounts (`SQLSTATE[HY000] [14] unable to open database file`).
- Added a small container entrypoint that repairs ownership of the persistent data directory before dropping privileges to the unprivileged `pengulab` user.
- This makes `/app/data` work consistently with the Proxmox LXC installer and normal Docker Compose bind mounts.

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
