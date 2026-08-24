# PenguLab 2.2.0

PenguLab 2.2.0 expands PenguHub with two infrastructure integrations and polishes the dashboard experience.

## Highlights

- **Proxmox VE integration** with read-only API-token monitoring for nodes, VMs, LXCs, CPU, RAM and storage.
- **Zabbix integration** using the current JSON-RPC API and API tokens to show monitored hosts and open problems.
- Home Assistant entity widgets no longer repeat the integration name as a card header when no custom widget title is set.
- Proxmox LXC installer now exposes `pengulabctl` through `/usr/bin` as well as `/usr/local/bin`, so it works in the minimal PATH used by `pct enter`.
- README redesigned around a simple, inviting project overview, real dashboard screenshot, Docker setup and Proxmox setup.

## Proxmox API

Create a dedicated API token and keep it read-only. A token limited to audit/monitoring permissions is sufficient for the PenguLab widget.

## Zabbix API

Create an API token in Zabbix and enter the Zabbix base URL plus token in PenguLab. PenguLab uses the JSON-RPC API with the Bearer token kept server-side.

## Docker images

A stable GitHub release publishes:

```text
ghcr.io/borderlane-ha/pengulab:2.2.0
ghcr.io/borderlane-ha/pengulab:latest
```
