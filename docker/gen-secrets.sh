#!/bin/sh
set -eu

SECRETS_FILE="${SECRETS_FILE:-/secrets/app.env}"
SECRETS_DIR="$(dirname "$SECRETS_FILE")"
SHARED_ENV="$SECRETS_DIR/shared.env"
SHARED_VALUES="$SECRETS_DIR/shared.values"

mkdir -p "$SECRETS_DIR"
touch "$SECRETS_FILE"

# put_secret KEY VALUE — append "export KEY=VALUE" only if KEY absent.
put_secret() {
  key="$1"; val="$2"
  if ! grep -q "^export ${key}=" "$SECRETS_FILE" 2>/dev/null; then
    printf 'export %s=%s\n' "$key" "$val" >> "$SECRETS_FILE"
    echo "gen-secrets: generated $key"
  fi
}

rand_hex() { openssl rand -hex "${1:-32}"; }

put_secret APP_SECRET "$(rand_hex 32)"
put_secret JWT_PASSPHRASE "$(rand_hex 32)"
put_secret MERCURE_JWT_SECRET "$(rand_hex 32)"
put_secret MERCURE_SUBSCRIBER_JWT_KEY "$(rand_hex 32)"
put_secret APP_MEDIA_SIGNING_KEY "$(rand_hex 32)"

# VAPID keypair (only if neither present) via the bundled minishlink generator.
if ! grep -q '^export VAPID_PUBLIC_KEY=' "$SECRETS_FILE" 2>/dev/null; then
  VAPID_JSON="$(php -r 'require "/app/vendor/autoload.php"; echo json_encode(\Minishlink\WebPush\VAPID::createVapidKeys());')"
  pub="$(echo "$VAPID_JSON" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["publicKey"];')"
  priv="$(echo "$VAPID_JSON" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["privateKey"];')"
  printf 'export VAPID_PUBLIC_KEY=%s\n' "$pub" >> "$SECRETS_FILE"
  printf 'export VAPID_PRIVATE_KEY=%s\n' "$priv" >> "$SECRETS_FILE"
  echo "gen-secrets: generated VAPID keypair"
fi

# ── Cross-container secrets (database, meilisearch, livekit) ─────────────────
# Unlike app.env above, these are needed by sidecar containers at THEIR OWN
# startup, so they can't stay app-only. Effective value per key:
#   non-empty env var (.env override) > previously generated > freshly generated.
# shared.values holds the effective plain values; the derived artifacts below
# are regenerated on every run so a .env override always propagates.

touch "$SHARED_VALUES"

shared_value() {
  key="$1"; gen="$2"
  # Operator override arrives under KEY_OVERRIDE (compose no longer sets the
  # bare KEY, so an empty value can't shadow .env.local for exec CLI sessions).
  envval="$(eval "printf '%s' \"\${${key}_OVERRIDE:-}\"")"
  stored="$(sed -n "s/^${key}=//p" "$SHARED_VALUES" | head -n1)"
  if [ -n "$envval" ]; then
    val="$envval"
  elif [ -n "$stored" ]; then
    val="$stored"
  else
    val="$gen"
    echo "gen-secrets: generated $key" >&2
  fi
  if [ -n "$stored" ]; then
    [ "$stored" = "$val" ] || sed -i "s|^${key}=.*|${key}=${val}|" "$SHARED_VALUES"
  else
    printf '%s=%s\n' "$key" "$val" >> "$SHARED_VALUES"
  fi
  printf '%s' "$val"
}

MARIADB_PASSWORD_V="$(shared_value MARIADB_PASSWORD "$(rand_hex 16)")"
MARIADB_ROOT_PASSWORD_V="$(shared_value MARIADB_ROOT_PASSWORD "$(rand_hex 16)")"
MEILI_MASTER_KEY_V="$(shared_value MEILI_MASTER_KEY "$(rand_hex 16)")"
LIVEKIT_API_KEY_V="$(shared_value LIVEKIT_API_KEY tyto)"
LIVEKIT_API_SECRET_V="$(shared_value LIVEKIT_API_SECRET "$(rand_hex 32)")"

# Defer-form env file: sourcing it never clobbers a non-empty env var, so
# .env overrides keep winning inside every container that sources this.
{
  for k in MARIADB_PASSWORD MARIADB_ROOT_PASSWORD MEILI_MASTER_KEY LIVEKIT_API_KEY LIVEKIT_API_SECRET; do
    v="$(sed -n "s/^${k}=//p" "$SHARED_VALUES" | head -n1)"
    printf 'export %s="${%s:-%s}"\n' "$k" "$k" "$v"
  done
} > "$SHARED_ENV"

printf '%s' "$MARIADB_PASSWORD_V" > "$SECRETS_DIR/mariadb_password"
printf '%s' "$MARIADB_ROOT_PASSWORD_V" > "$SECRETS_DIR/mariadb_root_password"

LIVEKIT_TEMPLATE="${LIVEKIT_TEMPLATE:-/app/docker/livekit/livekit.yaml}"
{
  cat "$LIVEKIT_TEMPLATE"
  printf 'keys:\n  %s: "%s"\n' "$LIVEKIT_API_KEY_V" "$LIVEKIT_API_SECRET_V"
} > "$SECRETS_DIR/livekit.yaml"

chmod 600 "$SECRETS_FILE" "$SHARED_VALUES" "$SHARED_ENV" \
  "$SECRETS_DIR/mariadb_password" "$SECRETS_DIR/mariadb_root_password" "$SECRETS_DIR/livekit.yaml"
