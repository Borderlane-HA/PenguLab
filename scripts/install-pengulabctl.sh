#!/usr/bin/env bash
set -Eeuo pipefail
URL="https://raw.githubusercontent.com/Borderlane-HA/PenguLab/main/scripts/pengulabctl"
if [[ "$(id -u)" -ne 0 ]]; then echo "Run this inside the PenguLab LXC as root." >&2; exit 1; fi
if command -v curl >/dev/null 2>&1; then curl -fsSL "$URL" -o /usr/local/bin/pengulabctl
elif command -v wget >/dev/null 2>&1; then wget -qO /usr/local/bin/pengulabctl "$URL"
else echo "curl or wget is required." >&2; exit 1; fi
chmod 0755 /usr/local/bin/pengulabctl
ln -sfn /usr/local/bin/pengulabctl /usr/local/sbin/pengulabctl
ln -sfn /usr/local/bin/pengulabctl /usr/bin/pengulabctl
hash -r 2>/dev/null || true
echo "Installed: $(command -v pengulabctl || echo /usr/bin/pengulabctl)"
pengulabctl status
