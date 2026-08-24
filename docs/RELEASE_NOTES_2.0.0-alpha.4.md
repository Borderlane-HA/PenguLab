# PenguLab 2.0.0-alpha.4

This pre-release focuses on the first real dashboard usability pass after the PenguLab 2.0 foundation.

## Highlights

- Pi-hole v6 and AdGuard Home widgets can now control DNS protection directly:
  - Resume protection
  - Pause for 5 minutes
  - Pause indefinitely
- App shortcuts are now genuinely compact and can be resized down to **1×1**.
- Fixed the layout persistence bug that made narrow app widgets grow again after saving.
- New app shortcuts default to **2×1**.
- The Apps page now has a high-density compact view designed for larger Homelabs, plus category filters, search, and an optional detail-card view.
- Automatic favicon discovery is back. PenguLab fetches favicons server-side when an app has no custom icon, and the app editor can explicitly reload a favicon.
- Pi-hole protection state is read from the Pi-hole v6 `/api/dns/blocking` endpoint.
- Proxmox installer explicitly installs `docker-cli` on Debian 13 and avoids the locale warnings seen during alpha.3 testing.

## DNS control API notes

Pi-hole uses the v6 `/api/dns/blocking` API. A 5-minute pause sends a 300-second timer. AdGuard Home uses `POST /control/protection`; the 5-minute pause uses a 300000 ms duration. Credentials remain server-side in PenguLab.

## Upgrade

For a Proxmox pre-release installation:

```bash
pengulabctl update
```

No database migration is required for alpha.4. Existing apps, integrations, widgets and IP Manager data are preserved.
