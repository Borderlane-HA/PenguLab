#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Run this script as root on the Proxmox VE host." >&2
  exit 1
fi
if ! command -v pct >/dev/null 2>&1; then
  echo "pct not found. Run this on a Proxmox VE host." >&2
  exit 1
fi

CTID="${1:-}"
if [[ -z "$CTID" ]]; then
  read -r -p "PenguLab CTID: " CTID
fi
if [[ ! "$CTID" =~ ^[0-9]+$ ]] || ! pct status "$CTID" >/dev/null 2>&1; then
  echo "Invalid or unknown CTID: $CTID" >&2
  exit 1
fi
if ! pct status "$CTID" | grep -q 'status: running'; then
  pct start "$CTID"
  sleep 2
fi

pct exec "$CTID" -- bash -lc '
set -e
mkdir -p /etc/systemd/system/console-getty.service.d
cat > /etc/systemd/system/console-getty.service.d/autologin.conf <<"UNIT"
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin root --noclear --keep-baud 115200,57600,38400,9600 - $TERM
UNIT
mkdir -p /etc/systemd/system/container-getty@1.service.d
cat > /etc/systemd/system/container-getty@1.service.d/autologin.conf <<"UNIT"
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin root --noclear --keep-baud 115200,57600,38400,9600 %I $TERM
UNIT
cat > /etc/profile.d/pengulab-console.sh <<"PROFILE"
if [ -t 1 ] && [ "$(id -u)" = "0" ]; then
  printf "\nPenguLab LXC\n  pengulabctl status   Show status\n  pengulabctl update   Update current channel\n  pengulabctl logs     Follow logs\n\n"
fi
PROFILE
chmod 0644 /etc/profile.d/pengulab-console.sh
systemctl disable --now ssh.service sshd.service 2>/dev/null || true
systemctl daemon-reload
systemctl restart console-getty.service 2>/dev/null || true
systemctl restart container-getty@1.service 2>/dev/null || true
'

echo "PenguLab console auto-login enabled for CT $CTID. SSH service disabled if present."
