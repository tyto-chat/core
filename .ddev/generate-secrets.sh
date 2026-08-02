#!/bin/bash
# Runs on the host as a pre-start hook.
# Ensures .ddev/.env has the Mercure, LiveKit, and Meilisearch secrets before
# Docker Compose reads it. `ddev init` then mirrors them into .env.local.

# ── Mercure keys ──────────────────────────────────────────────────────────────
if ! grep -qs '^MERCURE_PUBLISHER_JWT_KEY=.' .ddev/.env 2>/dev/null || \
   ! grep -qs '^MERCURE_SUBSCRIBER_JWT_KEY=.' .ddev/.env 2>/dev/null; then
    printf 'MERCURE_PUBLISHER_JWT_KEY=%s\nMERCURE_SUBSCRIBER_JWT_KEY=%s\n' \
        "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" >> .ddev/.env
    echo "Generated Mercure JWT keys → .ddev/.env"
fi

# ── LiveKit keys ──────────────────────────────────────────────────────────────
if ! grep -qs '^LIVEKIT_API_KEY=.' .ddev/.env 2>/dev/null || \
   ! grep -qs '^LIVEKIT_API_SECRET=.' .ddev/.env 2>/dev/null; then
    printf 'LIVEKIT_API_KEY=%s\nLIVEKIT_API_SECRET=%s\n' \
        "$(openssl rand -hex 8)" "$(openssl rand -hex 32)" >> .ddev/.env
    echo "Generated LiveKit API keys → .ddev/.env"
fi

# ── Meilisearch master key ────────────────────────────────────────────────────
# The compose file passes MEILI_MASTER_KEY into the Meilisearch container from
# .ddev/.env; `ddev init` mirrors it into .env.local for Symfony. Booting Meili
# with an empty key disables auth, so generate one if missing.
if ! grep -qs '^MEILI_MASTER_KEY=.' .ddev/.env 2>/dev/null; then
    printf 'MEILI_MASTER_KEY=%s\n' "$(openssl rand -hex 32)" >> .ddev/.env
    echo "Generated Meilisearch master key → .ddev/.env"
fi

# ── Write livekit.yaml with current keys ─────────────────────────────────────
LIVEKIT_API_KEY=$(grep '^LIVEKIT_API_KEY=' .ddev/.env | cut -d= -f2-)
LIVEKIT_API_SECRET=$(grep '^LIVEKIT_API_SECRET=' .ddev/.env | cut -d= -f2-)

if [ ! -f .ddev/livekit.yaml ] || \
   ! grep -qs "^  ${LIVEKIT_API_KEY}: ${LIVEKIT_API_SECRET}" .ddev/livekit.yaml 2>/dev/null; then
    cat > .ddev/livekit.yaml <<EOF
port: 7880
log_level: info

keys:
  ${LIVEKIT_API_KEY}: ${LIVEKIT_API_SECRET}

rtc:
  use_external_ip: false
  node_ip: "127.0.0.1"
  tcp_port: 7881
  port_range_start: 50100
  port_range_end: 50150

turn:
  enabled: false   # not needed for local dev; port 3478 is not exposed

webhook:
  api_key: ${LIVEKIT_API_KEY}
  urls:
    - http://ddev-${DDEV_SITENAME}-web/api/livekit/webhook
EOF
    echo "Wrote .ddev/livekit.yaml with generated keys"
fi
