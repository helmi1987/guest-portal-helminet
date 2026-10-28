# syntax=docker/dockerfile:1
# Single image: nginx + php-fpm (Laravel) + FreeRADIUS + queue worker + scheduler, supervised by s6-overlay.

ARG ALPINE_IMAGE=alpine:3.24
ARG NODE_IMAGE=node:22-alpine
ARG COMPOSER_IMAGE=composer:2

FROM --platform=$BUILDPLATFORM ${COMPOSER_IMAGE} AS composer

# --- Composer dependencies (platform independent, built natively) ---------------
FROM --platform=$BUILDPLATFORM ${ALPINE_IMAGE} AS vendor
RUN apk add --no-cache php84 php84-phar php84-openssl php84-mbstring php84-tokenizer php84-ctype \
        php84-dom php84-xml php84-xmlwriter php84-fileinfo php84-iconv php84-session php84-curl \
        php84-intl php84-pdo php84-pdo_sqlite php84-simplexml php84-zip unzip git \
    && ln -sf /usr/bin/php84 /usr/local/bin/php
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi

# --- Front-end assets ----------------------------------------------------------------
FROM --platform=$BUILDPLATFORM ${NODE_IMAGE} AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /app /app
RUN npm run build

# --- Runtime ---------------------------------------------------------------------------
FROM ${ALPINE_IMAGE}
ARG VERSION=dev
LABEL org.opencontainers.image.title="mikrotik-guest-portal" \
      org.opencontainers.image.description="Captive portal with RADIUS for MikroTik RouterOS 7 hotspots" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.version="${VERSION}"

RUN apk add --no-cache \
        s6-overlay nginx openssl curl ca-certificates tzdata \
        freeradius freeradius-rest freeradius-utils \
        php84 php84-fpm php84-opcache php84-openssl php84-mbstring php84-tokenizer php84-ctype \
        php84-dom php84-xml php84-xmlwriter php84-fileinfo php84-iconv php84-session php84-curl \
        php84-intl php84-pdo php84-pdo_sqlite php84-pdo_mysql php84-simplexml php84-bcmath \
        php84-pcntl php84-posix php84-sodium \
    && ln -sf /usr/bin/php84 /usr/local/bin/php \
    && addgroup -S portal && adduser -S -D -H -G portal -h /var/www/html portal \
    && rm -rf /etc/raddb/sites-enabled /etc/raddb/mods-enabled /etc/nginx/http.d

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/portal.conf.template docker/nginx/portal-app.conf /etc/nginx/
COPY docker/php/php-fpm.conf /etc/php84/php-fpm.conf
COPY docker/php/php.ini /etc/php84/conf.d/zz-portal.ini
COPY docker/freeradius/radiusd.conf /etc/raddb/radiusd.conf
COPY docker/freeradius/mods/ /etc/raddb/portal-mods/
COPY docker/freeradius/sites/ /etc/raddb/portal-sites/
COPY docker/s6-rc.d/ /etc/s6-overlay/s6-rc.d/
COPY docker/bin/ /usr/local/bin/

WORKDIR /var/www/html
COPY --from=vendor --chown=portal:portal /app /var/www/html
COPY --from=assets --chown=portal:portal /app/public/build /var/www/html/public/build
RUN rm -rf node_modules tests docker .github .env .env.* database/database.sqlite \
    && echo "${VERSION}" > VERSION \
    && chown -R portal:portal storage bootstrap/cache

ENV S6_KEEP_ENV=1 \
    S6_BEHAVIOUR_IF_STAGE2_FAILS=2 \
    S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 \
    S6_VERBOSITY=1 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/db/database.sqlite \
    SESSION_DRIVER=database \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    PORTAL_DATA_DIR=/data \
    GUEST_PORT=443 \
    ADMIN_PORT=8443 \
    INTERNAL_PORT=8080

VOLUME ["/data"]
EXPOSE 443/tcp 8443/tcp 1812/udp 1813/udp

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${INTERNAL_PORT}/up" >/dev/null || exit 1

ENTRYPOINT ["/init"]
