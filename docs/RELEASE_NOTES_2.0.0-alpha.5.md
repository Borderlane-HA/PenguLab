# PenguLab 2.0.0-alpha.5

This pre-release focuses on **network intelligence, richer service widgets and appliance-style Proxmox operation**.

## Highlights

- **IP Manager network scan** — scan each documented IPv4 network and review active hosts before importing them.
- **OPNsense ARP enrichment** — when an OPNsense integration has the required diagnostics privilege, scan results can gain MAC address, vendor and DHCP hostname information from the firewall's ARP table.
- **One-click device import** — use `+` on a discovered host to open the normal device form prefilled with IP, hostname and MAC.
- **Richer device documentation** — gateway, DNS and a dedicated **Has DHCP reservation** flag are now available.
- **Richer OPNsense widget** — optional gateway, RAM, WireGuard and sampled traffic information plus a small live graph.
- **Integration display options** — edit an integration to choose which statistics, graph and controls its dashboard widget should show.
- **App widget layouts** — choose Automatic, icon above text, icon beside text, or icon-only for every app shortcut widget.
- **Proxmox console appliance mode** — new LXC installs auto-login as root on the local/Proxmox web console and disable the SSH service.

## Notes for existing Proxmox test LXCs

Updating the Docker image with `pengulabctl update` updates PenguLab itself. The console auto-login setting belongs to the LXC operating system and is therefore applied automatically only by a **new alpha.5 LXC installation**. Existing test LXCs can keep using `pct enter`, or the console configuration can be applied once manually.

The OPNsense traffic graph is sampled by PenguLab every 30 seconds, so it needs at least two successful refreshes before a line can be drawn.

Active network discovery only finds hosts that are reachable/respond during the scan. MAC addresses are normally only visible on the local L2 network; OPNsense ARP enrichment is intended to fill that gap for routed VLANs when OPNsense knows the neighbor.

## Upgrade

After the GitHub pre-release workflow has finished successfully:

```bash
pct enter <CTID>
pengulabctl update
docker exec pengulab cat /app/VERSION
```

Expected version:

```text
2.0.0-alpha.5
```
