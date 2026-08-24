# PenguLab 2.4.0

PenguLab 2.4.0 makes PenguHub more independent from the main container and adds Proxmox Backup Server monitoring.

## Highlights

- **Upload PenguHub packages:** admins can install trusted integration/add-on ZIP files directly in PenguHub. Packages are stored persistently under `/data/addons`.
- **Proxmox Backup Server:** new PBS integration using API-token authentication.
- **PBS Task Summary:** selectable 7, 30 or 90 day overview of backups, prunes, garbage collection, syncs, verify and tape jobs with OK/warning/error counts.
- **Safer uploads:** path traversal, hidden files, unsupported file types and oversized archives are rejected; bundled package IDs cannot be overridden.
- **Generic custom widgets:** uploaded connectors can return `metrics` and `rows` for a clean default dashboard presentation without changing PenguLab frontend code.

## Docker image

```text
ghcr.io/borderlane-ha/pengulab:2.4.0
```

> Uploaded packages can execute server-side integration code. Only install packages from sources you trust.
