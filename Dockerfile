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

# Who the application runs as, in every environment. Mapped rather than fixed:
# locally the working tree is bind-mounted, so whatever the container writes
# has to belong to the developer rather than to root, and a deploy target may
# want its own service account. Compose passes both from .env.
#
# Declared here so `production`, `development`, and the `build` stage all
# inherit one identity instead of each inventing its own.
ARG APP_UID=1000
ARG APP_GID=1000

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

# Repoint the image's existing `www-data` rather than adding a second account,
# so there is one application user everywhere. `-o` permits a non-unique ID: a
# macOS host is commonly 501:20, and GID 20 is already `dialout` here.
#
# `www-data` ships with /var/www as its home, which is root-owned — and HOME
# stays /root from the base image unless it is set explicitly. Both npm and
# Composer write into HOME, so a non-root user without a writable one fails on
# the first install. Hence the dedicated home and the HOME below.
#
# /data and /config are Caddy's state, including the certificates it renews.
RUN groupmod -o -g "${APP_GID}" www-data \
    && usermod -o -u "${APP_UID}" -g "${APP_GID}" -d /home/www-data www-data \
    && mkdir -p /home/www-data /data /config \
    && chown -R www-data:www-data /home/www-data /app /data /config

# What an upload may weigh, and what a request may spend reading one.
#
# The FrankenPHP image installs no `php.ini` at all, so PHP's own compiled-in
# defaults applied: **2M**. `App\Support\Documents\DocumentStorage::MAX_BYTES`
# is 15MB, its validator says 15MB and the help article says 15MB — and every
# upload over 2M was discarded by PHP before any of them ran, arriving at the
# controller as an empty `$_FILES` and a validation error naming the wrong
# reason. A limit the application states four times and the runtime enforces at
# an eighth of it is worse than a lower limit honestly declared.
#
# `post_max_size` has to clear `upload_max_filesize` because multipart framing
# and the rest of the form ride in the same body; PHP discards the whole
# request when the body exceeds it, fields included.
#
# `memory_limit` is raised off its 128M default because a 15MB upload is held
# as a string while it is scanned. `ReadableText` bounds what it inflates and
# walks the file rather than splitting it, so a 15MB PDF was measured peaking
# at 24.3MB rather than the 52.3MB the split form cost — but a request also
# carries a framework, and a margin of zero against a default is not a margin.
#
# Set in `base` so development and production enforce the same ceiling: a limit
# that only exists in production is one nobody finds out about until deploy.
RUN { \
    echo 'upload_max_filesize=16M'; \
    echo 'post_max_size=24M'; \
    echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:80 \
    HOME=/home/www-data

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
# nothing from the context can shadow them. Copied as the application user, so
# `storage` and `bootstrap/cache` — which the entrypoint and the deploy's
# `config:cache` step write to — are writable without a second chown layer.
COPY --chown=www-data:www-data . .
COPY --from=build --chown=www-data:www-data /app/vendor ./vendor
COPY --from=build --chown=www-data:www-data /app/public/build ./public/build

# Opcache settings for a long-lived process. JIT is deliberately off: this is
# an I/O-bound web app, and JIT buys nothing while complicating crash reports.
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=0'; \
    echo 'opcache.memory_consumption=192'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Everything above needed root. Nothing below does: FrankenPHP carries
# CAP_NET_BIND_SERVICE on its binary, so it still binds :80 and :443.
USER www-data

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

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# The mapped user, its home, and the ownership of /app, /data and /config all
# come from `base`. Only the privilege drop belongs here: installing xdebug
# above needed root.
USER www-data

# Dependencies are installed into the image, including the dev ones. The local
# compose file mounts the working tree over /app but keeps /app/vendor and
# /app/node_modules as volumes, which are seeded from here — so a Linux laptop
# and a Mac run the same binaries, and neither needs PHP or Node installed.
#
# Installed as www-data, not root-then-chowned: a volume is seeded with the
# ownership the image directory carries, and chowning node_modules afterwards
# would duplicate it into a second, very large layer.
COPY --chown=www-data:www-data . .
RUN composer install --no-interaction --prefer-dist --no-progress \
    && npm ci --no-audit --no-fund

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
