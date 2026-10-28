#!/bin/sh
# Starts the editor with the PHP save API at http://127.0.0.1:8766/editor/ in Docker (WSL).
# The port is published on 127.0.0.1 only: the API has no accounts yet (router.php).
# Usage: sh server/run-dev.sh [image] [port]   (default: php:8.3-apache 8766)
# Data (SQLite and the published site) goes to server/data/, which Git ignores.
set -eu
cd "$(dirname "$0")/.."
image=${1:-php:8.3-apache}
port=${2:-8766}
mkdir -p server/data
# A container left over from a previous run would hold the port.
docker rm -f wge-dev >/dev/null 2>&1 || true
exec docker run --rm --pull never --name wge-dev -p "127.0.0.1:$port:8080" -e WGE_PORT="$port" \
  -v "$PWD":/app/packages -w /app "$image" \
  php -S 0.0.0.0:8080 -t packages packages/server/router.php
