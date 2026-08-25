# PenguLab 2.5.1

A focused dashboard-editor patch for touch devices and more consistent widget settings.

## Dashboard editor

- iPhone-sized screens can now reorder widgets vertically in edit mode instead of disabling drag completely.
- Smartphone widgets get a dedicated **Small / Medium / Large** size selector.
- iPad, tablets and desktop keep the free 12-column drag-and-resize layout.
- Other widgets temporarily make room while dragging, and the final draft is still only persisted when **Save** is pressed.
- Edit controls are slightly less dominant, while remaining reachable on small tiles.

## Widget settings

- All integration-summary widgets now show the settings button in edit mode.
- The button opens the connected integration directly (Pi-hole, AdGuard Home, OPNsense, PBS, Zabbix, Proxmox, Docker, Portainer and uploaded integrations).
- App and Home Assistant widgets keep their widget-specific settings dialogs.

## Docker image

```text
ghcr.io/borderlane-ha/pengulab:2.5.1
```
