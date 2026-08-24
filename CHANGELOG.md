# Changelog

## 2.1.1

### Fixed

- Fixed the sidebar/footer version label still showing `2.0 alpha`; UI version labels now come from the central `VERSION` file.
- Fixed dashboard edit mode not showing drag handles, widget remove/settings controls, or resize grips after entering `Layout bearbeiten`.
- Fixed new Proxmox LXC installs placing `pengulabctl` only in `/usr/local/sbin`; it is now installed in `/usr/local/bin` with a compatibility symlink in `/usr/local/sbin`.

## 2.1.0

### Added
- Added automatic OPNsense interface discovery for traffic widgets. Users select from their own configured interfaces instead of entering `wan`, `vtnet0`, `igb0`, VLAN or PPPoE device names manually.
- Added an `Automatisch (WAN erkennen)` traffic option with explicit interface selection for multi-WAN and custom interface layouts.
- Added optional recent blocked-domain lists to Pi-hole and AdGuard Home widgets with configurable 3, 5 or 10 entries.

### Fixed
- Fixed OPNsense traffic parsing for current `get_interface_statistics` responses under the `statistics` map and the `received-bytes` / `sent-bytes` counter names.
- Fixed OPNsense widgets falsely rendering `0 bit/s` when traffic counters were missing or could not be parsed.
- Fixed dashboard widgets occasionally moving after save. Dragging and resizing now use a local draft and only one atomic layout snapshot is written when `Speichern` is pressed.
- Removed automatic top-left relocation for manually moved widgets. A colliding drop is rejected and the widget returns to its previous position.
- Added server-side overlap validation so an invalid or stale layout cannot silently rearrange the dashboard.

### Changed
- Changing the selected OPNsense traffic interface resets only that integration's traffic history, preventing counters from two interfaces being mixed in one graph.
- DNS recent-block data uses the same persistent widget cache as the rest of the integration summary, so it appears immediately after dashboard navigation or reload.

## 2.0.0

### Added
- Added the Home Assistant PenguHub integration using Home Assistant's REST API and server-side Long-Lived Access Token storage.
- Added configurable Home Assistant dashboard widgets for up to eight `sensor`, `switch`, `light` and `cover` entities.
- Added compact/tile layouts, optional domain/device-class icons, sensor units and percentage bars.
- Added switch/light toggle controls and cover open/stop/close controls through PenguLab's server-side API proxy.
- Added persistent per-widget Home Assistant snapshots so the last known state renders immediately after navigation or reload.
- Fixed the Docker healthcheck for authenticated PenguLab 2.0 installations by checking the login/start page instead of the protected bootstrap API.

### Stable milestone
- Promoted the PenguLab 2.0 architecture from alpha to stable after the dashboard, PenguHub, SQLite migration, app library, IP Manager, user management, DNS controls, OPNsense monitoring and Proxmox LXC workflow were exercised through the alpha series.
- Stable Docker releases publish both the exact version tag and `latest`; future pre-releases remain isolated on `prerelease`.

## 2.0.0-alpha.7

- Added local authentication and user management.
- New installations create `admin` / `admin`; README and UI warn to change the default password.
- Added 90-day HttpOnly remember-login tokens stored server-side as hashes.
- Added per-user permissions for individual integrations and the IP Manager.
- Added a fully collapsible sidebar with per-user persistence and a reopen button in the top bar.
- Restricted PenguHub, integration/app configuration, dashboard editing, backups and user management to administrators.
- Fixed Pi-hole protection-state parsing for boolean as well as `enabled`/`disabled` API responses.
- Added Pi-hole CSRF header alongside SID authentication when available.
- AdGuard Home protection state now also consults `/control/dns_info`.
- DNS control actions now immediately re-read the actual service state, overwrite the widget cache, and fail visibly if the requested state did not change.

## 2.0.0-alpha.6

### Added
- Added persistent SQLite snapshots and time-series samples for integration widgets. Cached Pi-hole, AdGuard Home and OPNsense values/graphs render immediately after dashboard navigation or a browser reload, then update in the background.
- Added a selectable live refresh interval (5, 10, 15, 30 or 60 seconds) per Pi-hole, AdGuard Home and OPNsense integration.
- Added a local-only self-signed TLS fallback for automatic favicon discovery. Public HTTPS targets still require valid certificates.
- Added dense 1×1 app shortcuts that show a smaller icon plus the app name, allowing four shortcuts in the area previously used by one 2×2 app tile.

### Changed
- App widget labels now use one consistent text size across normal dashboard tile sizes; icon size, spacing and secondary information adapt instead.
- New app shortcuts added directly to the dashboard default to 1×1 and icon-over-text layout.
- Integration polling no longer allows overlapping requests for the same widget.
- Empty graph state is limited to the first measurement interval; once one rate sample exists a flat trace is rendered and then grows with future samples.

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
