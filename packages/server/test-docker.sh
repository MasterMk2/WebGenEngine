#!/bin/sh
# Runs test.php in Docker for each supported PHP version (D7).
# Usage: sh server/test-docker.sh [image ...]   (default: php:8.3-apache php:8.5-cli-alpine)
set -eu
cd "$(dirname "$0")/.."
status=0
[ $# -gt 0 ] || set -- php:8.3-apache php:8.5-cli-alpine
# --pull never: a missing image is an error here, never a download.
for image in "$@"; do
  docker run --rm --pull never -v "$PWD":/w/packages:ro -w /w "$image" \
    php -d error_reporting=-1 -d display_errors=1 packages/server/test.php || status=1
done
exit $status
