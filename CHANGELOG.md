# Changelog

## 2.6.1

- Doubled the desktop/tablet dashboard grid from 12 to 24 columns for finer horizontal resizing while preserving existing layouts.
- Fixed integration settings opened from dashboard widgets returning/rendering the Integrations page after save.
- Simplified the top-bar logout action to icon-only and added a confirmation prompt.
- Added automatic horizontal grid migration and portable-import scaling for layouts created before 2.6.1.

## 2.6.0

- Made the vertical dashboard grid four times finer while preserving existing widget positions and sizes during migration.
- Improved AdGuard Home metric layout so three metrics use the full widget width and no invisible fourth column stretches the card.
- Expanded the OPNsense widget with optional CPU, RAM, disk, temperature, uptime, firewall states, gateway loss/jitter, interface errors, WireGuard peer traffic, services and CARP/VIP status.
- Added automatic OPNsense gateway discovery and gateway selection for Multi-WAN setups.
- Changed new built-in integrations to start with TLS certificate verification disabled for common self-signed homelab certificates; existing integration settings are preserved.
- Updated portable import handling so layouts exported before 2.6 are migrated to the finer grid automatically.

## 2.5.3

- Fixed integration widget headers still appearing when the title was only the automatically stored integration name.
- Pi-hole, AdGuard Home, OPNsense, Proxmox, PBS, Zabbix, Docker, Portainer and other integration widgets now correctly hide the redundant header in normal dashboard mode.
- Explicitly customized widget titles remain visible. RSS/News remains unchanged.

## 2.5.2

- Removed redundant dashboard title bars from clock and integration widgets when no custom widget title is set.
- Integration names remain visible inside their cards, saving vertical space and avoiding duplicated labels.
- RSS/News keeps its header because it is useful as a section title.
- Edit mode still shows widget headers so drag, settings and remove controls remain accessible.
- Custom widget titles continue to be shown.

## 2.5.1

- Added a real smartphone layout editor: drag widgets vertically on iPhone-sized screens and choose compact, standard or large mobile widget sizes.
- Kept free-grid drag/resize for iPad, tablets and desktop while improving touch targets.
- Added the settings button to every integration-summary widget so its integration can be opened directly from dashboard edit mode.
- Reduced the visual weight of edit controls while keeping remove/settings accessible on small widgets.
- Responsive mobile order and size are stored with the dashboard layout and can be cancelled before saving.

## 2.5.0

- Added a touch-first dashboard editor: drag from the entire tile, larger edit controls and temporary collision reflow while moving/resizing widgets.
- Added a visible logout action to the dashboard top bar.
- Simplified and polished the login screen.
- Split Proxmox Backup Server task categories into individual display toggles, including separate Tape Backup and Tape Restore controls.
- Added Docker Engine monitoring integration.
- Added Portainer monitoring integration using API access tokens.
- Added a standalone `pengulabctl` installer/repair helper for existing Proxmox LXCs.

## 2.4.0

- Added admin-only PenguHub ZIP uploads for custom integration/add-on packages. Uploaded packages are validated and stored under `/data/addons` so they survive container updates.
- Added package update/delete controls for uploaded PenguHub packages and a generic metrics/rows renderer for simple third-party connectors.
- Added Proxmox Backup Server integration with API-token authentication and a selectable 7/30/90-day Task Summary.
- PBS Task Summary groups backups, prunes, garbage collection, syncs, verify jobs and tape backup/restore tasks into error, warning and OK counts.
- Added ZIP support and upload-size configuration to the Docker image.

## 2.3.0

- Added the new PenguLab penguin logo to the real application, login page and browser favicon.
- Removed the version badge from the top-left brand area; version remains available in the sidebar footer.
- Refreshed the login screen with the new dark PenguLab visual language.
- Home Assistant entity widgets can now shrink to 1×1 and automatically switch between compact and tile layouts.
- Zabbix widgets can configure how many recent problems are displayed (3, 5, 10, 15 or 20).
- OPNsense gateway latency now uses the current gateway status API with legacy fallback.
- OPNsense WireGuard monitoring now shows peer counts, peer names, status and latest handshake information.
- Browser favicon URLs are versioned to avoid stale browser cache after upgrades.

## 2.2.1

- Refined the README with a more inviting, user-friendly structure and updated hero presentation.
- Updated the bundled header image to match the new PenguLab brand presentation without hard-coded version numbers.
- Adjusted documentation wording to be more realistic and less overpromising.
- Version bump and release packaging cleanup.


## 2.2.0

- Added Proxmox VE PenguHub integration with read-only node, guest, CPU, RAM and storage status.
- Added Zabbix PenguHub integration with host and problem overview through API tokens.
- Home Assistant widgets without a custom title no longer show a redundant Home Assistant header.
- Fixed `pengulabctl` availability after `pct enter` by adding a `/usr/bin` compatibility link in new Proxmox installations.
- Reworked README into a shorter, user-focused introduction with a generated header and current dashboard screenshot.

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
