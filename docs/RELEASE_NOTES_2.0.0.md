# PenguLab 2.0.0

PenguLab 2.0 is the first stable release of the new **Homelab Control Center** architecture.

## Highlights

- Flexible drag/resize dashboard with persistent widget state and live refresh
- Dense, scalable app shortcuts and searchable app library
- SQLite persistence with automatic PenguLab 1.x migration
- PenguHub package architecture
- Local users, 90-day remember-login, per-integration/IP-Manager permissions
- Pi-hole v6 and AdGuard Home statistics plus protection controls
- OPNsense gateway, RAM, WireGuard and WAN traffic monitoring
- IP Manager as a separate PenguHub add-on with network discovery and OPNsense ARP enrichment
- RSS and Generic JSON API widgets
- Proxmox VE LXC installer with stable/prerelease channels and `pengulabctl`
- Authentication-aware Docker healthcheck that stays healthy while the API itself is protected

## New in the stable release: Home Assistant

A new **Home Assistant** package is available in PenguHub. It is intentionally not a replacement for the Home Assistant dashboard. It is designed for a few important entities that belong on the same overview as the rest of the Homelab.

Supported entity domains:

- `sensor` — value/unit and optional percentage bar
- `switch` — state and toggle
- `light` — state and toggle
- `cover` — state/position and open, stop, close controls

Each widget can contain up to eight selected entities, use a tile or compact layout, and show/hide icons or controls. The last successful state is cached in SQLite so widgets render immediately after returning to the dashboard.

Home Assistant authentication uses a **Long-Lived Access Token**, stored encrypted server-side in PenguLab.

## First login

New installations start with:

```text
Username: admin
Password: admin
```

Change the default password immediately under **Settings → User account**.

## Docker

Stable `2.0.0` publishes:

```text
ghcr.io/borderlane-ha/pengulab:2.0.0
ghcr.io/borderlane-ha/pengulab:latest
```

Pre-releases continue to use `:prerelease` and do not replace `:latest`.

## Proxmox update

For a Proxmox LXC already following the stable channel:

```bash
pct enter <CTID>
pengulabctl update
```

To move an existing test LXC from prerelease to stable:

```bash
pct enter <CTID>
pengulabctl channel stable
pengulabctl update
```

A backup is created automatically before an existing installation is replaced.
