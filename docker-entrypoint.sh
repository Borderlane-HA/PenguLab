#!/bin/sh
set -eu

DATA_DIR="${PENGULAB_DATA_DIR:-/app/data}"

# Bind mounts replace the ownership prepared at image build time.  When the
# container starts as root, make the persistent directory writable for the
# unprivileged PenguLab runtime user and then drop privileges.
if [ "$(id -u)" = "0" ]; then
    mkdir -p "$DATA_DIR"
    chown -R pengulab:pengulab "$DATA_DIR"
    exec su-exec pengulab "$0" "$@"
fi

if [ "${1:-}" = "php" ] && [ "${2:-}" = "-S" ]; then
    php -r '$ctx = require "/app/bootstrap.php"; if (session_status() === PHP_SESSION_ACTIVE) session_write_close();'
    php /app/bin/penguops-worker.php &
    COLLECTOR_PID=$!
    php /app/bin/penguops-worker.php --ai &
    AI_PID=$!
    "$@" &
    WEB_PID=$!
    trap 'kill "$COLLECTOR_PID" "$AI_PID" "$WEB_PID" 2>/dev/null || true' TERM INT EXIT
    wait "$WEB_PID"
else
    exec "$@"
fi
