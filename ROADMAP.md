# PenguLab Roadmap

## PenguLab 2.0 — completed foundation

- [x] SQLite core storage
- [x] automatic 1.x app/settings migration
- [x] IP Manager legacy migration
- [x] App / Integration / Widget separation
- [x] PenguHub local curated package catalog
- [x] dashboard drag + resize
- [x] global Ctrl+K search
- [x] IP Manager as an add-on
- [x] Pi-hole v6 connector
- [x] AdGuard Home connector
- [x] OPNsense read-only connector
- [x] RSS widget
- [x] Generic JSON API connector
- [x] encrypted server-side secrets
- [x] Home Assistant entity integration (sensor, switch, light, cover)

## Alpha 2 — dashboard polish

- [ ] dashboard layout presets and multiple dashboards
- [x] richer service-specific widgets and charts (first DNS/OPNsense pass)
- [x] widget/integration display configuration (first pass)
- [x] persistent integration snapshots/history and configurable live refresh cadence
- [ ] mobile dashboard editing improvements
- [ ] service status aggregation and last-refresh controls
- [ ] localization pass for all new 2.0 UI strings
- [ ] import/export UX with conflict preview

## Alpha 3 — Network intelligence

- [x] OPNsense interface/ARP discovery foundation
- [ ] Kea subnet/reservation import
- [ ] IP Manager source/reconciliation view
- [ ] duplicate IP/MAC warnings and scan reconciliation
- [x] subnet utilization visualization
- [ ] optional read-only periodic sync

## Beta — PenguHub repository

- [ ] remote signed package catalog
- [ ] checksums/signature verification
- [ ] compatibility constraints
- [ ] update and rollback support
- [ ] permission review UI
- [ ] third-party package developer tooling

## Later candidates

- Proxmox deeper metrics/actions
- Uptime Kuma
- EVCC
- Nextcloud
- Docker/Portainer
- custom webhooks / status sources
