# syntax=docker/dockerfile:1

# Node is copied in (not apt-installed) so the app container can build the
# front end: the Wayfinder Vite plugin calls `php artisan`, so assets need
# PHP and Node in the same place.
FROM node:22.22-bookworm-slim AS node

# PHP-FPM + nginx in one container, with pdo_mysql, redis, pcntl and opcache.
FROM serversideup/php:8.4-fpm-nginx-v4.5.1

USER root

ARG USER_ID=1000
ARG GROUP_ID=1000

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    # Run as the host user so files written to the bind mount stay editable.
    && docker-php-serversideup-set-id www-data "${USER_ID}:${GROUP_ID}" \
    && docker-php-serversideup-set-file-permissions --owner "${USER_ID}:${GROUP_ID}" --service nginx

USER www-data
