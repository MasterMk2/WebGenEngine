#!/bin/sh
# Runs the JS/PHP differential test. Needs node on the host and Docker.
# Usage: sh differential/run.sh [count] [seed] [image]
set -eu
cd "$(dirname "$0")/.."
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
node differential/gen.mjs "$work/cases.json" "${1:-2000}" "${2:-1}"
docker run --rm -v "$PWD":/w:ro -v "$work":/cases:ro -w /w "${3:-php:8.3-apache}" \
  php -d error_reporting=-1 -d display_errors=1 differential/check.php /cases/cases.json
