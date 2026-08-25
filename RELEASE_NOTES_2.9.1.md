# PenguLab 2.9.1

PenguLab 2.9.1 is a compatibility fix for the NPMplus App Import introduced in 2.9.0.

## NPMplus fix

- Fixed Proxy Host loading with current NPMplus releases.
- The importer now calls `GET /api/nginx/proxy-hosts` without unnecessary `expand` parameters.
- This avoids an incompatibility with current NPMplus where the expansion is named `access_lists` rather than the older/singular `access_list` form.
- Improved API error reporting: when NPMplus returns a useful JSON error message, PenguLab now includes the short message in the integration error.
- NPMplus URL, authentication model and TLS handling are otherwise unchanged.

A normal NPMplus admin URL such as `https://10.10.1.29:81/` remains valid.
