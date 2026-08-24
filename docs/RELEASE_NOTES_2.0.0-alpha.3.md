# PenguLab 2.0.0-alpha.3

This alpha is a focused Docker/Proxmox startup hotfix.

## Fixed

- Fixed `SQLSTATE[HY000] [14] unable to open database file` when `/app/data` is provided as a Docker bind mount.
- PenguLab now starts through a small entrypoint which prepares the persistent data directory as root and then drops privileges to the unprivileged `pengulab` user.
- The fix applies both to the Proxmox LXC installer and normal Docker Compose installations.
- Includes the Proxmox template-download output fix from alpha.2 testing.

## Upgrade

Pre-release installations:

```bash
pengulabctl update
```

Pinned installations:

```bash
pengulabctl version 2.0.0-alpha.3
pengulabctl update
```

Existing data in `/opt/pengulab/data` is retained.
