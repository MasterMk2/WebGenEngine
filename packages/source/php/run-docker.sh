#!/bin/sh
# Runs the PHP fixtures in Docker for each supported PHP version (D7).
# Usage: sh php/run-docker.sh [image ...]   (default: php:8.3-apache)
set -eu
cd "$(dirname "$0")/.."
status=0
for image in "${@:-php:8.3-apache}"; do
  docker run --rm -v "$PWD":/w:ro -w /w "$image" \
    php -d error_reporting=-1 -d display_errors=1 php/run-fixtures.php || status=1
done
exit $status
