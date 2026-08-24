# PenguLab 2.0

**PenguLab is a self-hosted Homelab Control Center.**

Instead of being only a start page, PenguLab 2.0 combines fast app shortcuts, a flexible dashboard, service integrations and installable PenguHub packages in one lightweight self-hosted interface.

> **Status:** `2.0.0-alpha.2` — this branch is an architectural preview and migration build. Back up your existing `apps.json` before testing an upgrade.

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

## Release channels

PenguLab uses separate Docker channels so test builds cannot replace the production image:

| Release type | Docker tag | Intended use |
| --- | --- | --- |
| Stable GitHub release | `latest` | Production |
| GitHub pre-release | `prerelease` | Alpha / beta / RC testing |
| Every release | exact release tag, e.g. `2.0.0-alpha.2` | Pinning / reproducible tests |

A GitHub **pre-release never updates `latest`**. Publishing `2.0.0-alpha.2` as a pre-release therefore publishes both `:2.0.0-alpha.2` and `:prerelease`, while the last stable build remains on `:latest`.

> **Note for `2.0.0-alpha.1`:** the first alpha workflow still tagged every published release as `latest`. If that workflow already ran, re-publish the last stable source (for example `1.0.3`) with the workflow's manual **stable** channel once. The corrected workflow in alpha.2 prevents this for future pre-releases.

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
docker build -t pengulab:2.0-alpha.2 .
docker run --rm -p 19961:8080 -v ./data:/app/data pengulab:2.0-alpha.2
```

## Proxmox VE LXC

PenguLab includes an interactive Proxmox VE installer for an **unprivileged Debian LXC with Docker**. It detects Proxmox VE 8/9, downloads a Debian 13 template (Debian 12 fallback), creates the container and installs PenguLab.

Run this on the **Proxmox host as root**:

```bash
curl -fsSL https://raw.githubusercontent.com/Borderlane-HA/PenguLab/main/scripts/proxmox-lxc-install.sh -o /tmp/pengulab-lxc-install.sh
bash /tmp/pengulab-lxc-install.sh
```

The installer asks for:

- Stable / Pre-release / exact release tag
- CTID and hostname
- LXC and template storage
- bridge and DHCP/static IPv4
- optional CPU/RAM/disk overrides

Defaults are deliberately small: 2 CPU cores, 1024 MiB RAM, 512 MiB swap and 8 GiB disk.

The LXC is created **unprivileged** and enables Proxmox `nesting=1,keyctl=1` because Docker inside an unprivileged container needs the additional container features. This is intentionally limited to the dedicated PenguLab LXC.

### Safely testing a pre-release on Proxmox

For alpha/beta testing, create a **second LXC** instead of changing the production container. In the installer choose:

```text
2) Pre-release - alpha/beta testing (Docker tag: prerelease)
```

This leaves the stable LXC on `ghcr.io/borderlane-ha/pengulab:latest` and creates the test LXC from `:prerelease`. Use a different CTID/hostname and preferably temporary or copied data.

To pin the test LXC to this exact pre-release instead:

```bash
pct enter <TEST-CTID>
pengulabctl version 2.0.0-alpha.2
pengulabctl update
```

### Updating a Proxmox installation

Enter the LXC and update the configured channel:

```bash
pct enter <CTID>
pengulabctl update
```

Before replacing an existing image, `pengulabctl update` automatically backs up `/opt/pengulab/data`, `.env` and `compose.yml` when data already exists. The ten newest automatic backups are retained under `/opt/pengulab/backups`.

Useful commands:

```bash
pengulabctl status
pengulabctl update
pengulabctl logs
pengulabctl backup
pengulabctl restart
```

The channel can also be changed explicitly:

```bash
pengulabctl channel stable
pengulabctl channel prerelease
```

Changing the channel only changes the selected image tag. Run `pengulabctl update` afterwards to pull and start it. For production-to-2.0 testing, a separate test LXC is still recommended because database migrations may not be backwards compatible with an older stable version.

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
    image: ghcr.io/borderlane-ha/pengulab:prerelease
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
├── scripts/
│   └── proxmox-lxc-install.sh  # interactive Proxmox VE LXC installer
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
