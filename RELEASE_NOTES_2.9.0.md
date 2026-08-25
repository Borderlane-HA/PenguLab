# PenguLab 2.9.0

PenguLab 2.9.0 adds **NPMplus → Apps** import support.

## NPMplus integration

Install **NPMplus** in PenguHub and create a connection with the NPMplus URL, login/e-mail and password. TLS certificate verification remains disabled by default for new internal integrations and can be enabled when the NPMplus endpoint uses a trusted certificate.

After the connection is saved, the Integrations page shows **Apps importieren**. PenguLab logs in server-side, reads the Proxy Host list and lets you select the hosts that should become apps.

Import destinations:

- **Only App Library** — recommended when you want to organise the apps yourself later.
- **One App Group** — creates or extends a compact PenguLab app folder on the dashboard.
- **Individual tiles** — places each imported app directly on the dashboard.

PenguLab avoids duplicates by remembering imported NPMplus host IDs and by matching existing app URLs. Re-importing can update the target URL of already imported apps while preserving custom app names, icons and categories. Wildcard-only Proxy Hosts are shown but are not directly importable as an app URL.

## Authentication compatibility

Current NPMplus releases use secure HttpOnly cookies for sessions, while older/upstream-compatible API variants can return a bearer token. The PenguLab connector accepts either form and does not depend on a fixed cookie name.

## Small integration UI fix

The Integrations page now only offers a **Widget** button when the installed integration package actually exposes a dashboard widget.
