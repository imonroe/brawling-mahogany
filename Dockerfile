# syntax=docker/dockerfile:1
#
# One image, three targets. `production` is what deploys; `development` is the
# same base with dev dependencies and no compiled caches, so local runs against
# the same PHP, the same extensions, and the same server as production
# (CLAUDE.md: "as reasonably close to production as possible").

ARG PHP_VERSION=8.4
ARG FRANKENPHP_VERSION=1
ARG NODE_VERSION=22

# `--from` cannot expand a variable, so the Node image is named as a stage
# here and referenced by that name wherever its binaries are copied in.
FROM node:${NODE_VERSION}-bookworm-slim AS node

# ---------------------------------------------------------------------------
# base — PHP, the extensions the app needs, and Composer
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

WORKDIR /app

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    intl \
    zip \
    gd \
    bcmath \
    pcntl \
    opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:80

# ---------------------------------------------------------------------------
# build — dependencies, Wayfinder output, and the compiled front end
#
# Node lives here rather than in a separate stage because the Vite build shells
# out to `php artisan wayfinder:generate`: the two toolchains have to meet.
# ---------------------------------------------------------------------------
FROM base AS build

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

# The placeholder environment exists only so artisan can boot during the build.
# It carries no secrets and is removed in the same layer.
RUN cp .env.example .env \
    && composer dump-autoload --optimize --no-dev \
    && php artisan wayfinder:generate --with-form \
    && npm run build \
    && rm -f .env

# ---------------------------------------------------------------------------
# production — the image that ships
# ---------------------------------------------------------------------------
FROM base AS production

ENV APP_ENV=production

# Source first, then the build's output on top of it: the artefacts win, and
# nothing from the context can shadow them.
COPY . .
COPY --from=build /app/vendor ./vendor
COPY --from=build /app/public/build ./public/build

# Opcache settings for a long-lived process. JIT is deliberately off: this is
# an I/O-bound web app, and JIT buys nothing while complicating crash reports.
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=0'; \
    echo 'opcache.memory_consumption=192'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

# ---------------------------------------------------------------------------
# development — the same base, plus the dev dependencies and no baked caches
# ---------------------------------------------------------------------------
FROM base AS development

ENV APP_ENV=local

RUN install-php-extensions xdebug

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

# Dependencies are installed into the image, including the dev ones. The local
# compose file mounts the working tree over /app but keeps /app/vendor and
# /app/node_modules as volumes, which are seeded from here — so a Linux laptop
# and a Mac run the same binaries, and neither needs PHP or Node installed.
COPY . .
RUN composer install --no-interaction --prefer-dist --no-progress \
    && npm ci --no-audit --no-fund

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
