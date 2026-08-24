# PenguLab 2.0

**PenguLab is a self-hosted Homelab Control Center.**

Instead of being only a start page, PenguLab 2.0 combines fast app shortcuts, a flexible dashboard, service integrations and installable PenguHub packages in one lightweight self-hosted interface.

> **Status:** `2.0.0-alpha.1` — this branch is an architectural preview and migration build. Back up your existing `apps.json` before testing an upgrade.

## What is new in 2.0

- Flexible dashboard with resizable and draggable widgets
- Separate **Apps**, **Integrations**, **PenguHub** and **Settings** areas
- Global search / command palette with `Ctrl + K`
- SQLite instead of using one JSON file as the application database
- Server-side integration requests: credentials are never sent back to dashboard widgets
- Encrypted integration secrets using PHP Sodium
- TLS verification enabled by default
- Automatic migration of PenguLab 1.x apps, settings and IP Manager data
- JSON configuration export/import without secrets
- Responsive light/dark/system UI
- Add-on architecture that keeps optional features outside the PenguLab core

## PenguHub

PenguHub is the extension layer of PenguLab. The alpha ships with a small curated local catalog:

| Package | Type | Purpose |
| --- | --- | --- |
| **IP Manager** | Add-on + widget | Networks, VLANs and assigned IP addresses |
| **Pi-hole** | Integration + widget | Pi-hole v6 query/blocking summary |
| **AdGuard Home** | Integration + widget | DNS query, blocking and protection status |
| **OPNsense** | Integration + widget | Read-only firewall/system health |
| **RSS** | Widget | RSS/Atom news feeds on the dashboard |
| **Generic API** | Integration + widget | Display simple values from an arbitrary JSON API |

In this first alpha the packages are **bundled with PenguLab and activated from PenguHub**. A remote, signed package repository is deliberately not part of the first build. This keeps the trust and update model small while the add-on API stabilizes.

## IP Manager 2.0

The IP Manager is no longer part of the PenguLab core. It is an installable PenguHub package.

Its UI has been redesigned around the things you actually use:

- create a network with only **name + CIDR**; VLAN is optional
- gateway, DNS and DHCP ranges are under **Advanced network settings**
- only assigned/documented addresses are listed
- search by IP, hostname or MAC
- suggest a free address automatically
- distinguish Static, Reservation and observed DHCP addresses
- show used/free capacity instead of rendering every empty IP in a subnet
- optionally add an IP Manager summary widget to the main dashboard

The data model also keeps a `source` field, so a future OPNsense/Kea sync can distinguish imported data from PenguLab documentation.

## Integrations

An **App**, an **Integration** and a **Widget** are separate objects:

- **App** — a shortcut to a service
- **Integration** — a server-side API connection to that service
- **Widget** — a dashboard view using an app, integration or add-on

This means one OPNsense or AdGuard Home connection can later power several widgets without duplicating credentials.

Integration traffic follows this path:

```text
Browser
  -> PenguLab API
      -> Integration connector
          -> Pi-hole / AdGuard Home / OPNsense / custom API
```

Secrets are encrypted in SQLite and are not included in normal JSON exports.

## Docker

Create a directory for the persistent PenguLab data:

```bash
mkdir -p pengulab/data
cd pengulab
```

Example `compose.yml`:

```yaml
services:
  pengulab:
    image: ghcr.io/borderlane-ha/pengulab:latest
    container_name: pengulab
    restart: unless-stopped
    ports:
      - "19961:8080"
    volumes:
      - ./data:/app/data
```

Then start it:

```bash
docker compose up -d
```

Open:

```text
http://YOURDOCKERHOST:19961
```

### Testing this alpha from the source tree

```bash
docker build -t pengulab:2.0-alpha .
docker run --rm -p 19961:8080 -v ./data:/app/data pengulab:2.0-alpha
```

## Upgrading from PenguLab 1.x

PenguLab 2.0 can import the old `apps.json` on first start.

1. Back up the old file.
2. Use the new persistent `/app/data` directory.
3. Make the old `apps.json` available at `/app/apps.json` for the first 2.0 start.
4. Start PenguLab 2.0.
5. PenguLab creates `/app/data/pengulab.sqlite` and imports the legacy data once.

Example migration compose:

```yaml
services:
  pengulab:
    image: pengulab:2.0-alpha
    ports:
      - "19961:8080"
    volumes:
      - ./data:/app/data
      - ./apps.json:/app/apps.json:ro
```

The migration imports:

- app shortcuts
- light/dark language settings that still map to 2.0
- existing IP Manager networks and devices
- an existing `?addon=ipmanager` shortcut also activates the IP Manager package

After you have verified the migration, the read-only `apps.json` mount can be removed. PenguLab 2.0 then operates from `data/pengulab.sqlite`.

## Backups

For a complete backup, back up the entire `data/` directory. It contains:

```text
data/
├── pengulab.sqlite
└── secret.key
```

The in-app JSON export is intended for portable dashboard/app configuration and **does not export integration credentials**.

Keep `secret.key` together with the database when restoring encrypted integration credentials.

## Self-hosting without Docker

PenguLab 2.0 requires:

- PHP 8.3+
- PDO SQLite (`pdo_sqlite`)
- cURL (`curl`)
- SimpleXML (`simplexml`)
- Sodium (`sodium`)
- Multibyte String (`mbstring`)
- write permission for the configured data directory

Set `PENGULAB_DATA_DIR` if your writable data directory is not `./data`.

## Project structure

```text
PenguLab/
├── index.php                 # UI shell and add-on routing
├── api.php                   # Core JSON API
├── bootstrap.php             # Core services / autoloading
├── assets/
│   ├── css/app.css
│   └── js/app.js
├── src/                      # Core services
│   ├── AddonManager.php
│   ├── Database.php
│   ├── IntegrationManager.php
│   └── Secrets.php
├── addons/                   # Bundled PenguHub packages
│   ├── ipmanager/
│   ├── pihole/
│   ├── adguardhome/
│   ├── opnsense/
│   ├── rss/
│   └── generic-api/
├── data/                     # persistent runtime data (volume)
├── docs/
└── legacy/                   # reference copies of the 1.x implementation
```

## Security model

- Add-ons are disabled until installed/activated in PenguHub.
- Integration secrets are encrypted with `secret.key` using Sodium Secretbox.
- Integration requests run server-side.
- HTTP clients follow neither arbitrary browser redirects nor `file://` URLs.
- Only HTTP/HTTPS integration endpoints are accepted.
- TLS certificate verification is enabled by default and can be disabled per integration for explicitly trusted self-signed internal services.
- OPNsense support is intentionally **read-only** in this first version.
- Non-GET API requests require a session CSRF token.

PenguHub currently trusts packages bundled with the PenguLab image. Remote third-party code installation should not be enabled until package signing, permissions and update verification are implemented.

## Development

See:

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — core/add-on boundaries and data model
- [`docs/ADDONS.md`](docs/ADDONS.md) — PenguHub manifest and connector model
- [`ROADMAP.md`](ROADMAP.md) — planned 2.0 milestones

## License

See [LICENSE](LICENSE).
