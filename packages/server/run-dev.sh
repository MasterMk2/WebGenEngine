#!/bin/sh
# Starts the editor with the PHP save API at http://127.0.0.1:8766/editor/ in Docker (WSL).
# The port is published on 127.0.0.1 only: the API has no accounts yet (router.php).
# Usage: sh server/run-dev.sh [image] [port] [blog title] [site name]
#   (default: php:8.3-apache 8766, no blog, no site)
# Data (SQLite and the published site) goes to server/data/, which Git ignores. With a blog
# title the site is a blog (blog.md) and its data goes to server/data/blog/ instead; with a
# site name (and no blog title) it is a site of pages (site.md), in server/data/pages/.
set -eu
cd "$(dirname "$0")/.."
image=${1:-php:8.3-apache}
port=${2:-8766}
blog=${3:-}
site=${4:-}
data=server/data
[ -z "$site" ] || data=server/data/pages
[ -z "$blog" ] || data=server/data/blog
mkdir -p "$data"
# A container left over from a previous run would hold the port.
docker rm -f "wge-dev-$port" >/dev/null 2>&1 || true
exec docker run --rm --pull never --name "wge-dev-$port" -p "127.0.0.1:$port:8080" -e WGE_PORT="$port" \
  -e WGE_DATA="/app/packages/$data" -e WGE_BLOG_TITLE="$blog" -e WGE_SITE_NAME="$site" \
  -v "$PWD":/app/packages -w /app "$image" \
  php -S 0.0.0.0:8080 -t packages packages/server/router.php
