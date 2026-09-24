# syntax=docker/dockerfile:1.7
#
# Production image of AuBi-One's Gäld fork (branch `aubi`).
# Lives in the fork at docker/production/Dockerfile; built by
# .github/workflows/build-image.yml and pushed to GHCR. The server only pulls.
#
# Two targets from one build:
#   app  php-fpm 8.4 + the application (vendor/, built frontend, plugins baked in)
#   web  nginx serving public/ and passing PHP requests to app:9000
#
# Nothing is compiled or installed on the server; no source is bind-mounted.
# Upstream's docker/php/Dockerfile is the Sail *development* image and is not used.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=24
ARG PNPM_VERSION=11.20.0
ARG NGINX_VERSION=1.28

# ---------------------------------------------------------------------------
# base: PHP runtime with the extensions Gäld needs (same list as upstream's dev image)
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

# Runtime tools: fcgi for the php-fpm health check, tesseract for receipt OCR
# (optional feature, kept so the image matches upstream's behaviour).
RUN apk add --no-cache \
        fcgi \
        tesseract-ocr tesseract-ocr-data-deu tesseract-ocr-data-fra tesseract-ocr-data-eng

# Extensions via mlocati/php-extension-installer (handles -dev packages and cleanup).
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip intl opcache redis

# php.ini: production defaults, then Gäld-specific overrides.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/production/php-gaeld.ini /usr/local/etc/php/conf.d/zz-gaeld.ini
COPY docker/production/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-gaeld-pool.conf

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor: Composer dependencies, production only
# ---------------------------------------------------------------------------
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
# Dependencies first so this layer is cached until composer.lock changes.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---------------------------------------------------------------------------
# assets: Vite build of the frontend, plugin pages included
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS assets
ARG PNPM_VERSION
RUN npm install -g pnpm@${PNPM_VERSION}
WORKDIR /app
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    pnpm install --frozen-lockfile
COPY . .
# vite.config.js discovers plugins/*/plugin.json "frontend.pages" only when this is "true".
ENV VITE_PLUGINS_ENABLED=true PLUGINS_ENABLED=true NODE_ENV=production
RUN pnpm run build

# ---------------------------------------------------------------------------
# app: the runtime image
# ---------------------------------------------------------------------------
FROM base AS app
ARG GAELD_VERSION=dev
ARG GAELD_COMMIT=unknown
LABEL org.opencontainers.image.title="Gäld (AuBi-One fork)" \
      org.opencontainers.image.source="https://github.com/AuBi-One/Gaeld" \
      org.opencontainers.image.version="${GAELD_VERSION}" \
      org.opencontainers.image.revision="${GAELD_COMMIT}" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/production/entrypoint.sh /usr/local/bin/gaeld-entrypoint

# Package manifest for Laravel (composer ran with --no-scripts), storage skeleton,
# ownership for the pool user (www-data = uid 82 on Alpine). storage/ is replaced by
# a bind mount at run time; bootstrap/cache stays in the container.
RUN php artisan package:discover --ansi \
    && mkdir -p storage/app/private storage/app/public storage/fonts storage/logs \
                storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing \
    && rm -rf tests node_modules .git .github .specify specs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/gaeld-entrypoint \
    && printf '%s\n' "${GAELD_VERSION}" > /var/www/html/VERSION

ENTRYPOINT ["/usr/local/bin/gaeld-entrypoint"]
CMD ["php-fpm"]
EXPOSE 9000
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong

# ---------------------------------------------------------------------------
# web: nginx with the public/ tree (static assets served directly, PHP to app:9000)
# ---------------------------------------------------------------------------
FROM nginx:${NGINX_VERSION}-alpine AS web
ARG GAELD_VERSION=dev
LABEL org.opencontainers.image.title="Gäld web (nginx)" \
      org.opencontainers.image.source="https://github.com/AuBi-One/Gaeld" \
      org.opencontainers.image.version="${GAELD_VERSION}"
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf
# "nginx serves": a static file, not /up — /up answers 503 in maintenance mode, and
# `docker compose up` must not treat a deploy's maintenance window as a failed container.
# The application health (/up: database + cache) is checked by deploy.sh smoke and monitor.sh.
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/build/manifest.json || exit 1
