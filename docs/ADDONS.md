# PenguHub Add-on Model

Each bundled package lives under `addons/<id>/` and has a `manifest.json`.

## Minimal manifest

```json
{
  "id": "example",
  "name": "Example",
  "version": "1.0.0",
  "category": "Tools",
  "description": "Example PenguHub package",
  "icon": "box",
  "permissions": []
}
```

## Optional package hooks

A package can declare:

```json
{
  "entrypoint": "page.php",
  "api": "api.php",
  "connector": "connector.php"
}
```

- `entrypoint` provides a full page rendered inside the PenguLab shell.
- `api` provides add-on-specific API actions under `api.php?route=addon/<id>/<action>`.
- `connector` implements server-side integration calls.
- `install.php`, when present, performs an idempotent package migration when the package is enabled.

## Integration declaration

```json
{
  "integration": {
    "type": "example",
    "name": "Example Service",
    "fields": [
      {"key": "base_url", "label": "URL", "type": "url", "required": true},
      {"key": "token", "label": "API token", "type": "password", "secret": true, "required": true},
      {"key": "verify_tls", "label": "Verify TLS certificate", "type": "boolean", "default": true}
    ]
  }
}
```

A connector returns an associative array. The initial generic summary widget displays simple returned values; package-specific widgets can be added later without changing the integration credentials.

## Widget declaration

```json
{
  "widgets": [
    {
      "type": "integration-summary",
      "integrationType": "example",
      "name": "Example Summary",
      "description": "Service status",
      "icon": "box",
      "defaultSize": [4, 2]
    }
  ]
}
```

## Permissions

Permissions are currently descriptive metadata exposed by PenguHub. Bundled packages use values such as:

- `network.http`
- `secrets.read`
- `service.control` — package exposes a small explicit set of write actions (for example DNS protection pause/resume)
- `storage.addon`

The bundled Home Assistant package is an example of a connector with a package-specific widget. It uses a server-side Long-Lived Access Token and exposes only explicit controls for selected `switch`, `light` and `cover` entities.

The future remote PenguHub should make permissions enforceable before third-party packages are supported.


## PenguLab 2.2 bundled integrations

PenguHub also ships read-only **Proxmox VE** monitoring and **Zabbix** monitoring connectors. Both keep API credentials server-side and reuse the normal integration/widget permission model.

## Uploadable PenguHub packages (2.4+)

Administrators can upload a ZIP package directly in **PenguHub → Integration hochladen**. The package is extracted below `/data/addons/<id>/` and therefore survives normal Docker image updates.

Accepted packages contain either `manifest.json` at the ZIP root, or one top-level folder containing `manifest.json`. Uploaded packages cannot replace a package bundled with PenguLab.

For safety, PenguLab rejects absolute paths, `..` traversal, hidden files, unsupported file types, oversized files and oversized archives. Uploading remains an administrative trust boundary: a connector can contain PHP code and therefore must only be installed from a trusted source.

An uploaded connector can use the normal `integration-summary` widget without frontend code. For a clean generic widget, return a `metrics` object (or array) and optionally `rows`:

```json
{
  "status": "Online",
  "metrics": {
    "Hosts": "12",
    "Problems": "0"
  },
  "rows": [
    {"label": "Last backup", "value": "OK", "meta": "2 min ago"}
  ]
}
```

Package-specific UI can still be added to PenguLab itself when a more specialized presentation is useful.

## Proxmox Backup Server package

The bundled `pbs` package reads the PBS management REST API and summarizes recent task states. By default it queries the last 30 days through `/api2/json/nodes/localhost/tasks` and groups backup, prune, garbage collection, sync, verify and tape tasks into error, warning and OK counters.
