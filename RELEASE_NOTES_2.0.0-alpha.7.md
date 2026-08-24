# PenguLab 2.0.0-alpha.7

Alpha 7 focuses on multi-user access, navigation and reliable DNS controls.

## Highlights

- **Collapsible sidebar** — hide it completely and reopen it from the top bar. The preference is stored per user.
- **Local user management** — initial login is `admin` / `admin`; change it immediately after first login.
- **Long-lived login** — optional 90-day HttpOnly remember cookie with server-side hashed tokens.
- **Permissions** — administrators can grant each normal user access to selected integrations and/or the IP Manager.
- **Admin separation** — PenguHub, connection credentials, app editing, dashboard layout, imports/exports and user management remain admin-only.
- **Pi-hole / AdGuard control fix** — after Resume / 5 min Pause / Stop, PenguLab reads the real protection state back immediately and updates the persistent cache.
- **Pi-hole state parsing fix** — both boolean and `enabled` / `disabled` API responses are handled correctly.
- **AdGuard state verification** — protection state is cross-checked via the current DNS settings endpoint.

## First login

```text
Username: admin
Password: admin
```

Change the password under **Settings → User account** before exposing PenguLab beyond a trusted test network.

## Update a Proxmox pre-release LXC

After the GitHub pre-release workflow is green:

```bash
pct enter <CTID>
pengulabctl update
docker exec pengulab cat /app/VERSION
```

Expected version: `2.0.0-alpha.7`.
