#!/bin/sh
set -eu

ROLE="${1:-app}"
SECRETS_FILE="${SECRETS_FILE:-/secrets/app.env}"
SHARED_ENV="$(dirname "$SECRETS_FILE")/shared.env"
LOCK="/secrets/.bootstrap.lock"

wait_for_secrets() {
  i=0
  while [ ! -f "$SECRETS_FILE" ]; do
    i=$((i+1)); [ "$i" -gt 120 ] && { echo "entrypoint: timed out waiting for $SECRETS_FILE"; exit 1; }
    echo "entrypoint($ROLE): waiting for secrets…"; sleep 2
  done
  # shellcheck disable=SC1090
  . "$SECRETS_FILE"
}

# Shared cross-container secrets (defer-form: non-empty env vars keep winning),
# then derive DATABASE_URL unless the operator supplied one.
source_shared() {
  if [ -f "$SHARED_ENV" ]; then
    # shellcheck disable=SC1090
    . "$SHARED_ENV"
  fi
  export DATABASE_URL="${DATABASE_URL_OVERRIDE:-mysql://tyto:${MARIADB_PASSWORD}@database:3306/tyto?serverVersion=mariadb-11.8.0&charset=utf8mb4}"
}

# Mirror the resolved secrets into .env.local so Symfony's Dotenv exposes them
# to every process — the web app AND ad-hoc `bin/console` runs via `docker
# compose exec`, which start a fresh shell that does not inherit the env this
# script sources. /secrets stays the persisted, cross-container source of
# truth; this file is regenerated from it on each boot. Call after the secrets
# are sourced. Values are single-quoted (literal, no dotenv interpolation).
write_env_local() {
  env_local="${ENV_LOCAL:-/app/.env.local}"
  ( umask 077; : > "$env_local" )
  for k in APP_SECRET JWT_PASSPHRASE MERCURE_JWT_SECRET MERCURE_SUBSCRIBER_JWT_KEY \
           APP_MEDIA_SIGNING_KEY VAPID_PUBLIC_KEY VAPID_PRIVATE_KEY \
           MARIADB_PASSWORD MEILI_MASTER_KEY LIVEKIT_API_KEY LIVEKIT_API_SECRET \
           DATABASE_URL; do
    v="$(eval "printf '%s' \"\${$k:-}\"")"
    [ -n "$v" ] && printf "%s='%s'\n" "$k" "$v" >> "$env_local"
  done
}

case "$ROLE" in
  secrets-init)
    /usr/local/bin/gen-secrets.sh
    echo "entrypoint: secrets ready"
    exit 0
    ;;
  app)
    # Generate secrets once (lockfile guards concurrent app replicas). The
    # secrets-init service normally did this already; this is the fallback
    # for bare `docker run` setups.
    if mkdir "$LOCK" 2>/dev/null; then
      /usr/local/bin/gen-secrets.sh
    else
      wait_for_secrets
    fi
    # shellcheck disable=SC1090
    . "$SECRETS_FILE"
    source_shared
    write_env_local

    # JWT keypair (idempotent).
    php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction

    # DB migrations — app only, never workers.
    php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing

    # First-boot Meilisearch backfill (guarded by a marker on the secrets volume).
    # Only set the marker on SUCCESS, so a failed first run (e.g. meili not yet
    # reachable) retries on the next boot instead of being skipped forever.
    if [ ! -f /secrets/.meili-initialized ]; then
      if php bin/console tyto:search:reindex; then
        touch /secrets/.meili-initialized
      else
        echo "entrypoint: reindex failed (non-fatal); will retry on next boot"
      fi
    fi

    php bin/console cache:clear --no-warmup
    php bin/console cache:warmup
    # Image builds with --no-scripts, so bundle assets (Swagger UI at /api) land here.
    php bin/console assets:install public --no-interaction

    # Signal running workers to gracefully restart so they pick up new code /
    # the recompiled container (the restart flag is written to the shared cache
    # pool; each worker exits after its current message and is respawned).
    php bin/console messenger:stop-workers || echo "entrypoint: stop-workers failed (non-fatal); workers self-restart via --time-limit"

    if [ "${BEHIND_PROXY:-false}" = "true" ]; then
      export CADDY_SITE_ADDRESS="http://${SERVER_DOMAIN:-localhost}"
      export TRUSTED_PROXIES="${TRUSTED_PROXIES:-private_ranges}"
    else
      export CADDY_SITE_ADDRESS="${SERVER_DOMAIN:-localhost}"
    fi

    # Single-stack bundle: also serve the web client (files mounted at
    # /srv/app by compose.bundle.yaml) on its own domain.
    if [ -n "${APP_DOMAIN:-}" ]; then
      if [ "${BEHIND_PROXY:-false}" = "true" ]; then
        export APP_SITE_ADDRESS="http://${APP_DOMAIN}"
      else
        export APP_SITE_ADDRESS="${APP_DOMAIN}"
      fi
      mkdir -p /etc/frankenphp/sites.d
      cp /app/docker/frankenphp/app-site.caddyfile /etc/frankenphp/sites.d/app.caddyfile
      cat > /srv/app/config.js <<EOF
window.__TYTO_CONFIG__ = { serverInfoUrl: "https://${SERVER_DOMAIN}/api/server-info" };
EOF
      echo "entrypoint: bundled web client enabled on ${APP_SITE_ADDRESS}"
    fi

    exec frankenphp run --config /etc/frankenphp/Caddyfile
    ;;
  worker)
    wait_for_secrets
    source_shared
    exec php bin/console messenger:consume async webhook --time-limit=3600 --memory-limit=192M -v
    ;;
  scheduler)
    wait_for_secrets
    source_shared
    exec php bin/console messenger:consume scheduler_default --time-limit=3600 --memory-limit=192M -v
    ;;
  *)
    echo "entrypoint: unknown role '$ROLE'"; exit 1 ;;
esac
