# PenguLab 2.1.1

PenguLab 2.1.1 is a focused maintenance release for the 2.1 dashboard and Proxmox installer.

## Fixes

- Dashboard edit mode once again shows the **move handle**, **widget controls/remove button**, and **resize grip**. The 2.1 draft → Save/Cancel behavior remains unchanged.
- Sidebar/footer version information is now read from the central `VERSION` file instead of showing the old `2.0 alpha` label.
- New Proxmox LXC installations place `pengulabctl` in `/usr/local/bin`, so it is available from `pct enter`, the Proxmox web console, and normal root shells. A compatibility symlink is also created in `/usr/local/sbin`.

## Upgrade

Stable Docker installations can update from `latest` as usual. Existing data in `/app/data` is preserved.

For an existing 2.1.0 LXC where `pengulabctl` is missing from PATH, either update manually once with Docker Compose or create the compatibility link if the command already exists in `/usr/local/sbin`.
