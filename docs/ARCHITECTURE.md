# PenguLab 2.0 Architecture

## Design goal

The PenguLab core should remain useful even when no optional package is installed. Optional domain features belong to PenguHub packages instead of being added to `index.php`.

## Core responsibilities

The core owns:

- dashboard layout
- app shortcuts
- widgets and widget placement
- integration records and encrypted credentials
- settings, import/export and search
- local authentication, remember tokens and per-user authorization
- package discovery/activation
- shared navigation and UI primitives

The core does **not** own IP address management, Pi-hole logic, AdGuard logic or OPNsense-specific API behavior.

## Domain separation

```text
App
  independent shortcut

Integration
  one server-side connection + secrets

Widget
  presentation on a dashboard

Add-on
  optional feature package supplied through PenguHub
```

A package may provide an integration type, one or more widget types, a full-page add-on, an API endpoint, or a combination of them.

## Persistent data

PenguLab uses one SQLite database plus one local encryption key.

Core tables:

- `settings`
- `apps`
- `dashboards`
- `widgets`
- `addons`
- `addon_kv`
- `integrations`
- `users`
- `remember_tokens`
- `integration_widget_cache` / `integration_metric_samples`

Add-ons own tables with a package-specific prefix. IP Manager, for example, owns `ipm_networks` and `ipm_devices`.


## Authentication and authorization

PenguLab uses local users stored in SQLite. Passwords use PHP `password_hash()`. Long-lived sign-in uses a selector/verifier remember-token design: the browser receives an HttpOnly cookie, while SQLite stores only the SHA-256 hash of the verifier.

Administrators bypass resource filters. Normal users have a compact permission document containing an optional IP Manager grant and a list of integration IDs. The API enforces these permissions again server-side; hiding a navigation item is never treated as an authorization boundary.

The sidebar collapsed state is stored in each user's preferences rather than in a global setting.

## Secrets

Secrets are stored separately from non-sensitive integration configuration. The encrypted payload is produced with Sodium Secretbox and a 32-byte key in `data/secret.key`.

The normal JSON export excludes the secret payload. A full restore therefore uses the complete `data/` directory.

## API connector boundary

Connectors are PHP callables loaded only from enabled, locally discovered packages. They receive a resolved integration object and the shared HTTP client.

```text
Widget request
 -> Core API
 -> IntegrationManager
 -> enabled package connector
 -> HttpClient
 -> target service
```

This prevents browser widgets from receiving API passwords or OPNsense API secrets.

## Package trust

Alpha 1 uses a curated bundled catalog. An "install" operation enables a package already present in the image and runs its idempotent database migration.

A future remote PenguHub must add, at minimum:

1. signed catalog metadata
2. signed package archives/checksums
3. compatibility constraints
4. explicit permissions
5. transactional install/update/rollback
6. no direct writable access outside the package data contract
7. a review/trust policy for third-party packages

Until these exist, downloading arbitrary PHP from a remote catalog is intentionally out of scope.
