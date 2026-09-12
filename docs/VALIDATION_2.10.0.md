# Validation of PenguLab 2.10.0

Validation date: 2026-09-12. Source baseline: attached PenguLab 2.9.1 archive.

| Check | Result |
|---|---|
| Geometry solver | 250 scenarios, 150 widgets, no overlaps |
| Chromium UI, API fixtures | 150 widgets; no JavaScript errors |
| 12 edit/save/cancel cycles | 4 refresh timers before and after; zero additional widget data requests from mode changes |
| Content geometry | Matching body width, height and padding in view/edit modes for tested cards |
| Drag and resize | Position and size survive save; Escape restores pre-gesture layout |
| Numeric geometry editor | Exact width applied in existing 8px units |
| Groups | Short hover does not group; deliberate hold creates group |
| New controls | Boolean and numeric action payloads verified |
| Mobile | 390px viewport render and edit/cancel checked; physical iPhone/iPad gestures not tested |
| PHP parser | PHP source parsed using native PHP 8.5.10 and a separate PHP 8.3 grammar parser |
| ioBroker fixture | REST-API 4.0.2 result envelope, metadata, boolean false, writes with ack=false, permissions/ranges/auth errors |
| Node-RED fixture | Protocol, discovery, missing points, bearer header and command validation |
| Bridge function execution | Token checks, command routing, read-only/range/type rejection, feedback semantics, valid node wiring |
| SQLite | Addon registration, geometry save, atomic collision rejection, integration-based widget visibility |

## Isolated solver comparison

100 widgets, 30 movements, same Node.js process and fixtures:

| Metric | 2.9.1 | 2.10.0 |
|---|---:|---:|
| Median solver time | 18.71 ms | 1.17 ms |
| 95th percentile | 50.89 ms | 2.71 ms |

This measures layout calculation only, excluding browser paint, network and
server latency. It is not an end-to-end speed guarantee for the user's hardware.

## Limits

- No connection to the user's live ioBroker, Node-RED, Home Assistant or other
  homelab services. Connector tests use contract fixtures; the bridge functions
  were executed directly, not inside a running Node-RED instance.
- Docker image build/start and the four-worker setting could not be exercised in
  this environment. The environment has no Docker runtime. The worker setting
  follows the documented Linux PHP CLI server mechanism.
- Native PHP tests ran in PHP-WASM. Sodium is absent there, so the extra
  encryption-at-rest assertion was explicitly skipped. The existing encryption
  implementation was not changed; sodium remains in the production Dockerfile.
- No new Docker image or GitHub release has been published by this task.
