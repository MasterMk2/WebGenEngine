# The editor and the save API behind a trusted front (docs/design/server.md §6).
# Build:  docker build -t webgenengine .
# Run:    docker run -v <data>:/data -v <site>:/site -v <key file>:/run/wge-key:ro \
#           -e WGE_SVC_KEY_FILE=/run/wge-key -e WGE_SVC_CLIENT=<client ID> -e WGE_SITE_URL=<https://…> webgenengine
# Without the key and the client ID it serves nothing. Never publish its port: only the front calls it.
ARG PHP_IMAGE=php:8.3-cli-alpine
FROM ${PHP_IMAGE}

WORKDIR /app
COPY packages/source/schema.json packages/source/theme.default.json packages/source/
COPY packages/source/render packages/source/render
COPY packages/source/js packages/source/js
COPY packages/source/php packages/source/php
COPY packages/editor packages/editor
COPY packages/server/Api.php packages/server/Blog.php packages/server/Front.php packages/server/PageStore.php packages/server/Site.php packages/server/StaticFiles.php packages/server/SvcAuth.php packages/server/trusted-front.php packages/server/

# The built-in server, with a few workers so that one slow request does not hold the others.
ENV WGE_DATA=/data WGE_SITE_DIR=/site PHP_CLI_SERVER_WORKERS=4
RUN mkdir -p /data /site && chown www-data:www-data /data /site
USER www-data
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "packages/server/trusted-front.php"]
