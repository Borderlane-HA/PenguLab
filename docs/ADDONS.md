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

Permissions are currently descriptive metadata exposed by PenguHub. Alpha packages use values such as:

- `network.http`
- `secrets.read`
- `storage.addon`

The future remote PenguHub should make permissions enforceable before third-party packages are supported.
