# syntax=docker/dockerfile:1

###########################################
# Stage 1 — composer dependencies (no-dev)
###########################################
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

###########################################
# Stage 1b — FrankenPHP + Souin (xcaddy)
###########################################
FROM dunglas/frankenphp:1-builder-php8.5 AS caddy-builder

COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy

# Souin + its Redis storage module are pinned: SouinCachePurger purges via the
# Surrogate-Key header, the only path proven to work against these pinned
# versions — every path-purge form (exact literal, glob "*", and PCRE regexp)
# was tried against the admin API's DeleteMany and matched nothing (see
# src/Service/HttpCache/SouinCachePurger.php's docblock). That's empirically
# observed behavior, not documented public contract. An unpinned rebuild
# picking up a newer release could silently change or break purge behavior.
# Bump deliberately, then re-run docker/test/cache-smoke.sh before shipping.
ENV CGO_ENABLED=1 XCADDY_SETCAP=1
RUN CGO_CFLAGS=$(php-config --includes) \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build \
    --output /usr/local/bin/frankenphp \
    --with github.com/dunglas/frankenphp/caddy \
    --with github.com/dunglas/caddy-cbrotli \
    --with github.com/dunglas/mercure/caddy \
    --with github.com/dunglas/vulcain/caddy \
    --with github.com/darkweak/souin/plugins/caddy@v1.7.8 \
    --with github.com/darkweak/storages/redis/caddy@v0.0.19

###########################################
# Stage 2 — runtime (FrankenPHP / PHP 8.5)
###########################################
FROM dunglas/frankenphp:1-php8.5 AS runtime

# PHP extensions via the bundled mlocati/install-php-extensions helper —
# it installs the required system libs and builds reliably (no -j race).
# imagick is REQUIRED: Liip Imagine is configured with driver: imagick
# (avatars/logos/emojis, incl. animated-GIF passthrough). gd kept for any
# direct GD usage.
RUN install-php-extensions pdo_mysql intl gd imagick zip opcache

COPY --from=caddy-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

ENV APP_ENV=prod
ENV SECRETS_FILE=/secrets/app.env
WORKDIR /app

COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/gen-secrets.sh /usr/local/bin/gen-secrets.sh
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/gen-secrets.sh /usr/local/bin/entrypoint.sh

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Writable runtime dirs (media + exports are mounted as volumes at runtime).
RUN mkdir -p var/cache var/log var/media var/exports config/jwt \
    && chown -R www-data:www-data var config/jwt

ENTRYPOINT ["entrypoint.sh"]
CMD ["app"]
