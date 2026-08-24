<p align="center">
  <img src="docs/images/pengulab-header.png" alt="PenguLab – self-hosted Homelab Control Center" width="100%">
</p>

# PenguLab

**Your Homelab, at a glance.** PenguLab brings apps, infrastructure, DNS, monitoring, smart-home values and network documentation into one clean self-hosted dashboard.

No giant management suite. No cloud account. Just a fast control center you can shape around the services you actually use.

## Why PenguLab?

- **Flexible dashboard** — move and resize widgets, build dense app grids and keep the things that matter visible.
- **PenguHub integrations** — Pi-hole, AdGuard Home, OPNsense, Home Assistant, Proxmox VE, Zabbix, RSS and generic JSON APIs.
- **Useful controls** — pause/resume DNS protection and control selected Home Assistant switches, lights and covers.
- **Live status** — cached values appear instantly and refresh in the background; graphs keep their history across page changes.
- **IP Manager** — document networks, VLANs and devices and discover hosts with network scans.
- **Apps without clutter** — compact shortcuts with automatic favicons, categories and multiple tile layouts.
- **Multi-user** — long-lived sign-in and per-user access to integrations and the IP Manager.
- **Private by design** — credentials stay server-side and sensitive integration values are encrypted in SQLite.

## Dashboard

<p align="center">
  <img src="docs/images/dashboard-overview.png" alt="PenguLab dashboard overview" width="100%">
</p>

## PenguHub

PenguHub keeps optional features separate from the core. Install only what you need:

| Integration | What it adds |
| --- | --- |
| **Pi-hole** | DNS stats, graphs, latest blocked domains and protection controls |
| **AdGuard Home** | DNS stats, latest blocked domains and protection controls |
| **OPNsense** | Gateway, WireGuard, RAM and automatically discovered interface traffic |
| **Home Assistant** | Selected sensors, switches, lights and covers — without replacing your HA dashboard |
| **Proxmox VE** | Nodes, VMs, LXCs, CPU, RAM and storage overview |
| **Zabbix** | Monitored hosts and current problems |
| **IP Manager** | Networks, VLANs, devices and network discovery |
| **RSS / Atom** | News feeds directly on the dashboard |
| **Generic API** | Simple values from your own JSON endpoints |

## Install with Docker

Create a folder and a `compose.yml`:

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

Start PenguLab:

```bash
mkdir -p pengulab/data
cd pengulab
docker compose up -d
```

Open:

```text
http://YOUR-SERVER:19961
```

## Install on Proxmox VE

PenguLab includes an interactive installer that creates an **unprivileged Debian LXC**, installs Docker and deploys PenguLab for you.

Run on the **Proxmox host as root**:

```bash
curl -fsSL https://raw.githubusercontent.com/Borderlane-HA/PenguLab/main/scripts/proxmox-lxc-install.sh -o /tmp/pengulab-lxc-install.sh
bash /tmp/pengulab-lxc-install.sh
```

The installer asks for the CTID, storage, bridge, DHCP/static IP and resource size. Afterwards the LXC can be managed locally with:

```bash
pengulabctl status
pengulabctl update
pengulabctl logs
pengulabctl backup
```

## First login

A new installation starts with:

```text
Username: admin
Password: admin
```

**Change the password after your first login.**

## Data & backup

All persistent data lives in the mounted `data/` directory. Back up that directory to keep your dashboard, users, integrations and encrypted secrets together.

## License

See [LICENSE](LICENSE).
