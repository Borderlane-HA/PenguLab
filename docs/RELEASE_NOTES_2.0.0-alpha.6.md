# PenguLab 2.0.0-alpha.6

Alpha 6 focuses on dashboard responsiveness and high-density app shortcuts.

## Instant integration widgets

Pi-hole, AdGuard Home and OPNsense widgets now persist their last successful snapshot and metric samples in SQLite. When the dashboard is opened again, PenguLab paints the cached values and graph immediately and refreshes the integration in the background.

This removes the repeated **“Sammelt Daten…”** phase after changing pages or reloading the browser. On a brand-new integration the first rate graph still needs two measurements, but after that the history survives page changes, reloads and container restarts.

Each integration can choose a refresh cadence of **5, 10, 15, 30 or 60 seconds**. Requests for the same widget do not overlap.

## Denser app shortcuts

App shortcuts can now be reduced to **1×1 while still showing icon + app name**. Four 1×1 shortcuts therefore fit into the same dashboard area as one 2×2 app tile.

The label typography stays consistent between tile sizes. PenguLab scales icon size, spacing and optional metadata instead of making every tile use a different font size. Existing layout choices remain available:

- Automatic
- Icon above text
- Icon beside text
- Icon only

New dashboard shortcuts default to a 1×1 icon-over-text tile.

## Self-signed favicon discovery

Automatic favicon lookup first uses normal TLS verification. If that fails for a **local/private Homelab target** (private IP, `.local`, `.lan`, `.internal`, `.home`, `.home.arpa`, localhost or a hostname resolving only to private IPv4 addresses), PenguLab retries favicon discovery without certificate verification.

The relaxed retry applies only to favicon discovery. Integration APIs keep their own explicit TLS setting and public HTTPS favicon targets do not silently bypass certificate validation.

## Upgrade

Publish `2.0.0-alpha.6` as a GitHub pre-release, wait for the Docker workflow to finish, then inside the Proxmox test LXC run:

```bash
pengulabctl update
```

Verify the running version with:

```bash
docker exec pengulab cat /app/VERSION
```

Expected output:

```text
2.0.0-alpha.6
```
