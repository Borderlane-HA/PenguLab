# PenguLab 2.1.0

PenguLab 2.1 focuses on dashboard reliability and richer network/DNS widgets while keeping the 2.0 architecture and existing data model intact.

## OPNsense traffic that works across installations

PenguLab now discovers the interfaces configured in each connected OPNsense firewall. The traffic widget defaults to **Automatic (detect WAN)** and administrators can select any discovered interface from a dropdown, including custom WAN names, VLANs, PPPoE, WireGuard or multi-WAN interfaces.

The connector now understands current OPNsense interface-statistics responses (`statistics`, `received-bytes`, `sent-bytes`) and no longer turns missing counters into a false `0 bit/s` graph.

## Recent DNS blocks

Pi-hole and AdGuard Home integrations gain an optional **Latest blocked domains** section. Administrators can enable it and choose 3, 5 or 10 entries. The list is cached together with the widget summary, so the most recent known entries appear immediately after a page change or reload.

> DNS resolvers see domain names, not full browser URLs or URL paths.

## Deterministic dashboard layout

Dashboard editing now uses a draft workflow:

1. choose **Layout bearbeiten**
2. move and resize widgets locally
3. choose **Speichern** to persist one complete layout snapshot, or **Abbrechen** to restore the previous positions

A widget dropped on another widget is returned to its previous position instead of being silently relocated. The server also rejects overlapping layouts atomically.

## Upgrade

Stable Docker installations:

```bash
pengulabctl channel stable
pengulabctl update
```

Verify:

```bash
docker exec pengulab cat /app/VERSION
```

Expected output:

```text
2.1.0
```

Existing SQLite data, users, apps, integrations, IP Manager data and dashboard widgets are retained.
