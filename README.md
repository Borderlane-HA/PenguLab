<p align="center">
  <img src="docs/images/pengulab-header.png" alt="PenguLab – self-hosted Homelab Control Center" width="100%">
</p>

# PenguLab

**All your Homelab. One beautiful dashboard.**

PenguLab is a self-hosted control center for your homelab. It brings your apps, services, integrations and important stats together in one clean dashboard.

It is built for people who want a fast overview, useful widgets and a setup that stays easy to manage.

## Why people like PenguLab

- **Unified overview** – keep your most important apps, widgets and services in one place.
- **Real-time monitoring** – view live values, status information and small charts at a glance.
- **Smart integrations** – connect tools like Pi-hole, AdGuard Home, OPNsense, Home Assistant, Proxmox VE, Proxmox Backup Server and Zabbix through PenguHub.
- **Useful controls** – pause DNS protection or control selected Home Assistant entities right from the dashboard.
- **Flexible layout** – move, resize and arrange widgets the way you like, from tiny Home Assistant controls to larger monitoring panels.
- **Private by design** – your data stays on your own server.

## Overview

<p align="center">
  <img src="docs/images/dashboard-overview.png" alt="PenguLab dashboard overview" width="100%">
</p>

## What you can add

PenguLab stays lightweight, but it can grow with your homelab through **PenguHub**.

Available integrations and add-ons include:

- Pi-hole
- AdGuard Home
- OPNsense
- Home Assistant
- Proxmox VE
- Proxmox Backup Server
- Zabbix
- RSS / Atom feeds
- IP Manager
- Generic JSON API widgets

Admins can also upload additional PenguHub integration packages as **ZIP files** directly in the web interface. Uploaded packages are stored in `/data/addons`, so they survive normal container updates. Only install packages from sources you trust, because an integration package may contain server-side code.

## Proxmox Backup Server

The PBS integration uses a read-only API token and can show the familiar **Task Summary** for a selectable time window (7, 30 or 90 days): backups, prunes, garbage collection, syncs, verify jobs and tape backup/restore tasks with OK, warning and error counts.

For API-token authentication, use the PBS token ID (`user@realm!tokenname`) and token secret. A monitoring token needs appropriate read/audit permissions for the tasks it should see.

## Install with Docker

Create a new folder, add a compose file and start PenguLab.

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

Then run:

```bash
mkdir -p pengulab/data
cd pengulab
docker compose up -d
```

Open PenguLab in your browser:

```text
http://YOUR-SERVER:19961
```

## Install on Proxmox (LXC)

If you prefer Proxmox, use the included installer on your **Proxmox host**:

```bash
curl -fsSL https://raw.githubusercontent.com/Borderlane-HA/PenguLab/main/scripts/proxmox-lxc-install.sh -o /tmp/pengulab-lxc-install.sh
bash /tmp/pengulab-lxc-install.sh
```

The installer creates an unprivileged LXC, installs Docker and deploys PenguLab for you.

## Update PenguLab

### Docker

Open the folder that contains your `compose.yml` and run:

```bash
docker compose pull
docker compose up -d --remove-orphans
```

Your data stays in the mounted `data/` folder.

### Proxmox LXC

Open the LXC in the Proxmox console or enter it from the Proxmox host:

```bash
pct enter <CTID>
pengulabctl update
```

`pengulabctl update` creates a backup of the persistent PenguLab data before pulling and starting the new image.

## First login

A fresh installation starts with:

```text
Username: admin
Password: admin
```

Please change the password after your first login.

## License

See [LICENSE](LICENSE).
