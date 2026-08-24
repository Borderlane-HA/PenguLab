#!/bin/sh
set -eu

DATA_DIR="${PENGULAB_DATA_DIR:-/app/data}"

# Bind mounts replace the ownership prepared at image build time.  When the
# container starts as root, make the persistent directory writable for the
# unprivileged PenguLab runtime user and then drop privileges.
if [ "$(id -u)" = "0" ]; then
    mkdir -p "$DATA_DIR"
    chown -R pengulab:pengulab "$DATA_DIR"
    exec su-exec pengulab "$@"
fi

exec "$@"
