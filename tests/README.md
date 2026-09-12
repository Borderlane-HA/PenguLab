# Regression tests

Run from the repository root:

```sh
node tests/layout.test.cjs
node tests/bridge.test.cjs
php tests/lint.php
php tests/connectors.test.php
php tests/storage.test.php
```

The PHP connector tests substitute an HTTP transport fixture. The storage tests
use a fresh temporary SQLite database; they never use the application's data
folder. Sodium-dependent checks are explicitly skipped if that extension is
unavailable. The production Dockerfile continues to install sodium.

The browser suite uses Playwright with a local static server and API fixtures:

```sh
npm install --no-save playwright
npx playwright install chromium
node tests/frontend.test.cjs
```

Optionally set `CHROMIUM_EXECUTABLE` to an existing Chromium binary. Tests cover
150 widgets, body geometry across modes, 12 edit/save/cancel cycles with stable
timer counts and no reload burst, drag/save/cancel, resizing, exact dimensions,
short vs. deliberate group hover, ioBroker/Node-RED controls and a 390px viewport.
Screenshots go to the OS temporary directory. Tests are excluded from Docker
images; PHP test entrypoints also reject web requests.

No live home automation system is required or contacted by these tests.
