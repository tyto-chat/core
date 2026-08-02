#!/usr/bin/env bash
#
# Image-level HTTP-cache security + coherency smoke test.
#
# Exercises the compiled Souin/Caddy/Redis cache layer (Task 6) end to end
# against a *running* compose stack — PHPUnit never crosses Caddy, so this is
# the only place the security properties of the cache (bucket isolation,
# purge-on-write, dark-mode kill switch) are actually verified against the
# built image.
#
# Requires:
#   - The compose stack up (docker/frankenphp/Caddyfile wired, Souin reachable
#     at $BASE, admin API loopback-only per Task 6).
#   - An already-bootstrapped admin account (see DEPLOY.md — e.g.
#     `docker compose exec app php bin/console tyto:user:create --admin
#     --generate-password`, the ddev-init-equivalent entrypoint). This script
#     does not create the admin account itself: it only ever talks to the
#     public HTTP API.
#   - curl and jq on PATH.
#
# Usage:
#   BASE=http://localhost:8080 ADMIN_EMAIL=admin@example.com \
#     ADMIN_PASSWORD=secret bash docker/test/cache-smoke.sh
#
# Every T<N> assertion prints "PASS: T<N> ..." or "FAIL: T<N> ...". Exits
# non-zero if any assertion failed.

set -euo pipefail

BASE="${BASE:-http://localhost:8080}"
ADMIN_EMAIL="${ADMIN_EMAIL:?set ADMIN_EMAIL to an already-bootstrapped admin account}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:?set ADMIN_PASSWORD for that admin account}"

for bin in curl jq; do
  command -v "$bin" >/dev/null 2>&1 || {
    echo "cache-smoke.sh: '$bin' is required on PATH" >&2
    exit 1
  }
done

BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

# Every request uses the SAME Accept header — API Platform's content
# negotiation adds "Vary: Accept", so an inconsistent Accept header across
# requests would land in a different cache variant and read as a false miss
# (this bit the first draft of this script: see task-8-report.md).
ACCEPT_HEADER='Accept: application/ld+json'

FAILED=0
RUN_ID="$$-$RANDOM"

pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; FAILED=1; }

# assert_cmd DESC CMD [ARGS...] — runs CMD; PASS/FAIL on its exit status.
# Never itself fails under `set -e` (the command runs inside an `if`).
assert_cmd() {
  local desc="$1"
  shift
  if "$@"; then pass "$desc"; else fail "$desc"; fi
}

# assert_eq DESC EXPECTED ACTUAL
assert_eq() {
  if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected [$2], got [$3])"; fi
}

# --- generic HTTP helpers ------------------------------------------------

# login EMAIL PASSWORD -> prints JWT (empty on failure)
login() {
  curl -s -X POST "$BASE/auth" -H 'Content-Type: application/json' \
    -d "{\"email\":\"$1\",\"password\":\"$2\"}" | jq -r '.token // empty'
}

# api_json TOKEN METHOD PATH [JSON_BODY] -> prints response body (ld+json request/response)
api_json() {
  local token="$1" method="$2" path="$3" data="${4:-}"
  local auth=()
  [ -n "$token" ] && auth=(-H "Authorization: Bearer $token")
  if [ -n "$data" ]; then
    curl -s -X "$method" "${auth[@]}" -H 'Content-Type: application/ld+json' -H "$ACCEPT_HEADER" -d "$data" "$BASE$path"
  else
    curl -s -X "$method" "${auth[@]}" -H "$ACCEPT_HEADER" "$BASE$path"
  fi
}

# admin_patch_config TOKEN JSON_BODY -> prints HTTP status code
admin_patch_config() {
  curl -s -o /dev/null -w '%{http_code}' -X PATCH "$BASE/api/admin/server-config" \
    -H "Authorization: Bearer $1" -H 'Content-Type: application/merge-patch+json' -d "$2"
}

# get_page TOKEN_OR_EMPTY COMMUNITY CHANNEL PAGE_NUMBER -> prints raw response
# headers followed by a trailing "HTTP_STATUS:<code>" line; body lands in
# $BODY_FILE.
get_page() {
  local token="$1" comm="$2" chan="$3" pg="$4"
  local auth=()
  [ -n "$token" ] && auth=(-H "Authorization: Bearer $token")
  curl -s -D- -o "$BODY_FILE" -w 'HTTP_STATUS:%{http_code}\n' \
    "${auth[@]}" -H "$ACCEPT_HEADER" \
    "$BASE/api/communities/$comm/channels/$chan/pages/$pg"
}

# get_resource TOKEN_OR_EMPTY PATH -> like get_page but for an arbitrary API
# path (used by T12-T17 for /messages/current, /pinned-messages, /emojis).
get_resource() {
  local token="$1" path="$2"
  local auth=()
  [ -n "$token" ] && auth=(-H "Authorization: Bearer $token")
  curl -s -D- -o "$BODY_FILE" -w 'HTTP_STATUS:%{http_code}\n' \
    "${auth[@]}" -H "$ACCEPT_HEADER" \
    "$BASE$path"
}

http_status_of() { grep -o 'HTTP_STATUS:[0-9]*' <<<"$1" | cut -d: -f2; }
cache_status_of() { grep -i '^cache-status:' <<<"$1" | tr -d '\r'; }
cache_control_of() { grep -i '^cache-control:' <<<"$1" | tr -d '\r'; }

# Predicates below are plain functions returning the natural grep exit
# status — always call them through assert_cmd (or another `if`/`&&`/`||`),
# never bare, so a false result can't trip `set -e`.
is_miss() { grep -qi 'fwd=uri-miss' <<<"$1"; }
is_stored() { grep -qi 'stored' <<<"$1"; }
is_hit() { grep -qi '; *hit' <<<"$1"; }
is_not_hit() { ! is_hit "$1"; }
has_no_smaxage() { ! grep -qi 's-maxage' <<<"$1"; }
body_contains() { grep -qF "$1" "$BODY_FILE"; }

# =========================================================================
# Seed: community, channel, two members, one message (public API only,
# beyond the already-bootstrapped admin account).
# =========================================================================

echo "== seeding =="

ADMIN_TOKEN="$(login "$ADMIN_EMAIL" "$ADMIN_PASSWORD")"
[ -n "$ADMIN_TOKEN" ] || {
  echo "cache-smoke.sh: could not log in as admin ($ADMIN_EMAIL) at $BASE/auth — is the stack up and the admin bootstrapped?" >&2
  exit 1
}

# Generous rate limits for the duration of this run — the default login
# (5/60s) / register (3/600s) / api_write (60/60s) budgets are sized for real
# traffic, not a script that logs in and writes repeatedly in a few seconds.
status="$(admin_patch_config "$ADMIN_TOKEN" '{"rateLoginLimit":1000,"rateLoginIntervalSeconds":60,"rateRegisterLimit":1000,"rateRegisterIntervalSeconds":60,"rateApiWriteLimit":5000,"rateApiWriteIntervalSeconds":60}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to raise rate limits (HTTP $status)" >&2; exit 1; }

# Public registration normally requires an emailed challenge code; skip it so
# this script only depends on the public API + an already-created admin.
status="$(admin_patch_config "$ADMIN_TOKEN" '{"validateEmails":false}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to disable validateEmails (HTTP $status)" >&2; exit 1; }

status="$(admin_patch_config "$ADMIN_TOKEN" '{"httpCachePageTtlSeconds":60}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to enable httpCachePageTtlSeconds (HTTP $status)" >&2; exit 1; }

COMM1="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-test-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM1" ] || { echo "cache-smoke.sh: failed to create public community" >&2; exit 1; }
CHAN1="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM1\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN1" ] || { echo "cache-smoke.sh: failed to create channel in $COMM1" >&2; exit 1; }
echo "seeded community=$COMM1 channel=$CHAN1"

M1_EMAIL="member1-$RUN_ID@example.com"
M2_EMAIL="member2-$RUN_ID@example.com"
M1_ID="$(api_json "" POST /api/users "{\"email\":\"$M1_EMAIL\",\"password\":\"member1pass\",\"displayName\":\"Member One\"}" | jq -r '.id // empty')"
M2_ID="$(api_json "" POST /api/users "{\"email\":\"$M2_EMAIL\",\"password\":\"member2pass\",\"displayName\":\"Member Two\"}" | jq -r '.id // empty')"
[ -n "$M1_ID" ] && [ -n "$M2_ID" ] || { echo "cache-smoke.sh: member registration failed" >&2; exit 1; }
M1_TOKEN="$(login "$M1_EMAIL" member1pass)"
M2_TOKEN="$(login "$M2_EMAIL" member2pass)"
[ -n "$M1_TOKEN" ] && [ -n "$M2_TOKEN" ] || { echo "cache-smoke.sh: member login failed" >&2; exit 1; }

api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM1/members/add" "{\"userId\":$M1_ID}" >/dev/null
M2_MEMBER_JSON="$(api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM1/members/add" "{\"userId\":$M2_ID}")"
M2_MEMBER_ID="$(jq -r '.id // empty' <<<"$M2_MEMBER_JSON")"
[ -n "$M2_MEMBER_ID" ] || { echo "cache-smoke.sh: failed to add member2 to $COMM1" >&2; exit 1; }

api_json "$M1_TOKEN" POST "/api/communities/$COMM1/channels/$CHAN1/messages" '{"text":"seed message"}' >/dev/null

# =========================================================================
# T1-T5: bucket isolation / sharing on a fresh page.
# =========================================================================

echo "== T1-T5: bucket isolation =="

r="$(get_page "$M1_TOKEN" "$COMM1" "$CHAN1" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T1 member1 first GET is a miss" is_miss "$cs"
assert_cmd "T1 response is stored" is_stored "$cs"

r="$(get_page "$M1_TOKEN" "$COMM1" "$CHAN1" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T2 member1 second GET is a hit" is_hit "$cs"

r="$(get_page "$M2_TOKEN" "$COMM1" "$CHAN1" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T3 member2 GET hits member1's shared standard-bucket entry" is_hit "$cs"

r="$(get_page "$ADMIN_TOKEN" "$COMM1" "$CHAN1" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T4 admin GET is a miss (elevated fingerprint bucket, never shared)" is_miss "$cs"

r="$(get_page "" "$COMM1" "$CHAN1" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T5 anonymous GET is a miss (anon bucket separate from standard)" is_miss "$cs"

# =========================================================================
# T6: purge-on-write clears every bucket variant, not just the one being
# read. Uses its OWN community/channel (rather than reusing COMM1/CHAN1's
# already-primed buckets from T1-T5) so priming is explicit and
# self-contained: all three variants are freshly stored here, then a single
# mutation must purge all three via the shared Surrogate-Key.
# =========================================================================

echo "== T6: purge on write =="

COMM_T6="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t6-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T6" ] || { echo "cache-smoke.sh: failed to create community for T6" >&2; exit 1; }
CHAN_T6="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM_T6\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN_T6" ] || { echo "cache-smoke.sh: failed to create channel for T6" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T6/members/add" "{\"userId\":$M1_ID}" >/dev/null
api_json "$M1_TOKEN" POST "/api/communities/$COMM_T6/channels/$CHAN_T6/messages" '{"text":"t6 seed"}' >/dev/null

# Prime the standard, admin (elevated fingerprint), and anon buckets — one
# fresh store each.
r="$(get_page "$M1_TOKEN" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 priming: standard-bucket GET is stored" is_stored "$cs"

r="$(get_page "$ADMIN_TOKEN" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 priming: admin-bucket GET is stored" is_stored "$cs"

r="$(get_page "" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 priming: anon-bucket GET is stored" is_stored "$cs"

# Confirm all three are actually cached (hits) before mutating, so the
# post-mutation misses below can only be explained by the purge.
r="$(get_page "$M1_TOKEN" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 priming: standard-bucket GET is a hit before mutation" is_hit "$cs"

r="$(get_page "$ADMIN_TOKEN" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 priming: admin-bucket GET is a hit before mutation" is_hit "$cs"

r="$(get_page "" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 priming: anon-bucket GET is a hit before mutation" is_hit "$cs"

api_json "$M1_TOKEN" POST "/api/communities/$COMM_T6/channels/$CHAN_T6/messages" '{"text":"second message triggers purge"}' >/dev/null

r="$(get_page "$M1_TOKEN" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 standard-bucket GET after write is a miss (purge fired)" is_miss "$cs"
assert_cmd "T6 refreshed page contains the new message" body_contains "second message triggers purge"

r="$(get_page "$ADMIN_TOKEN" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 admin-bucket GET after write is also a miss (purge cleared every variant)" is_miss "$cs"

r="$(get_page "" "$COMM_T6" "$CHAN_T6" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T6 anon-bucket GET after write is also a miss (purge cleared every variant)" is_miss "$cs"

# =========================================================================
# T7: dark-mode kill switch. Uses its OWN community/channel — reusing COMM1
# here would let the s-maxage=60 entry stored back in T1/T6 (still valid;
# ttl=0 only affects NEW stores) serve a false "hit" that has nothing to do
# with the kill switch actually working.
# =========================================================================

echo "== T7: kill switch =="

status="$(admin_patch_config "$ADMIN_TOKEN" '{"httpCachePageTtlSeconds":0}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to disable httpCachePageTtlSeconds" >&2; exit 1; }

COMM_T7="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t7-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T7" ] || { echo "cache-smoke.sh: failed to create community for T7" >&2; exit 1; }
CHAN_T7="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM_T7\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN_T7" ] || { echo "cache-smoke.sh: failed to create channel for T7" >&2; exit 1; }
api_json "$M1_TOKEN" POST "/api/communities/$COMM_T7/channels/$CHAN_T7/messages" '{"text":"t7 seed"}' >/dev/null

r="$(get_page "$M1_TOKEN" "$COMM_T7" "$CHAN_T7" 1)"
cc="$(cache_control_of "$r")"
cs="$(cache_status_of "$r")"
assert_cmd "T7 response carries no s-maxage once ttl=0" has_no_smaxage "$cc"
assert_cmd "T7 first GET under ttl=0 is not a hit" is_not_hit "$cs"

r="$(get_page "$M1_TOKEN" "$COMM_T7" "$CHAN_T7" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T7 repeat GET under ttl=0 stays non-hit" is_not_hit "$cs"

status="$(admin_patch_config "$ADMIN_TOKEN" '{"httpCachePageTtlSeconds":60}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to re-enable httpCachePageTtlSeconds" >&2; exit 1; }

# =========================================================================
# T9: role-change flips the bucket (moderator promotion).
# =========================================================================

echo "== T9: grant-change bucket flip =="

# Prime a standard-bucket entry as member2 (pre-promotion); not itself
# asserted on — what matters is that member2's FIRST request under their new,
# never-before-seen fingerprint bucket is guaranteed to be a miss regardless
# of this call's outcome or of purge/TTL history on the standard bucket.
get_page "$M2_TOKEN" "$COMM1" "$CHAN1" 1 >/dev/null

status="$(curl -s -o /dev/null -w '%{http_code}' -X PATCH "$BASE/api/communities/$COMM1/members/$M2_MEMBER_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/merge-patch+json' -d '{"role":"moderator"}')"
assert_eq "T9 promoting member2 to moderator succeeds" "200" "$status"

r="$(get_page "$M2_TOKEN" "$COMM1" "$CHAN1" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T9 member2's next GET is a miss (bucket flipped standard -> fingerprint)" is_miss "$cs"

# =========================================================================
# T8: private community — anonymous never gets in, never a hit.
# =========================================================================

echo "== T8: private community anonymous denial =="

COMM2="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-priv-$RUN_ID\",\"isPrivate\":true}" | jq -r '.identifier // empty')"
[ -n "$COMM2" ] || { echo "cache-smoke.sh: failed to create private community" >&2; exit 1; }
CHAN2="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM2\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN2" ] || { echo "cache-smoke.sh: failed to create channel in $COMM2" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM2/members/add" "{\"userId\":$M1_ID}" >/dev/null
api_json "$M1_TOKEN" POST "/api/communities/$COMM2/channels/$CHAN2/messages" '{"text":"private seed"}' >/dev/null

# An anonymous (unauthenticated) caller denied by a voter gets 401, not 403,
# throughout this app (Symfony's convention: AccessDeniedException against an
# unauthenticated token maps to 401; 403 is reserved for an authenticated but
# insufficiently-privileged caller — see e.g. MessageTest::
# testGetCurrentPageReturns401ForAnonymous). What matters for T8 is that it's
# NEVER a 200, and NEVER a cache hit.
for attempt in 1 2; do
  r="$(get_page "" "$COMM2" "$CHAN2" 1)"
  st="$(http_status_of "$r")"
  cs="$(cache_status_of "$r")"
  assert_eq "T8 anonymous GET #$attempt on private community is denied (401)" "401" "$st"
  assert_cmd "T8 anonymous GET #$attempt is never a cache hit" is_not_hit "$cs"
done

# =========================================================================
# T10: Souin's admin/purge surface is not reachable through the public site.
# =========================================================================

echo "== T10: admin surface not public =="

st="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/souin-api/souin")"
if [ "$st" = "404" ] || [ "$st" = "403" ]; then
  pass "T10 /souin-api/souin is not reachable through the public site"
else
  fail "T10 /souin-api/souin is not reachable through the public site (got HTTP $st)"
fi

# =========================================================================
# T11 (carry-over from Task 6 review): dark mode never serves a stale hit.
# httpCachePageTtlSeconds=0 must force revalidation on every request, even
# immediately after a new message — no "; hit" ever, and the body is always
# current.
# =========================================================================

echo "== T11: dark-mode coherency =="

status="$(admin_patch_config "$ADMIN_TOKEN" '{"httpCachePageTtlSeconds":0}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to disable httpCachePageTtlSeconds for T11" >&2; exit 1; }

COMM3="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-dark-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM3" ] || { echo "cache-smoke.sh: failed to create community for T11" >&2; exit 1; }
CHAN3="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM3\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN3" ] || { echo "cache-smoke.sh: failed to create channel for T11" >&2; exit 1; }

api_json "$M1_TOKEN" POST "/api/communities/$COMM3/channels/$CHAN3/messages" "{\"text\":\"dark-msg-A-$RUN_ID\"}" >/dev/null

r="$(get_page "$M1_TOKEN" "$COMM3" "$CHAN3" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T11 dark-mode GET after message A is never a hit" is_not_hit "$cs"
assert_cmd "T11 dark-mode GET returns message A" body_contains "dark-msg-A-$RUN_ID"

api_json "$M1_TOKEN" POST "/api/communities/$COMM3/channels/$CHAN3/messages" "{\"text\":\"dark-msg-B-$RUN_ID\"}" >/dev/null

r="$(get_page "$M1_TOKEN" "$COMM3" "$CHAN3" 1)"
cs="$(cache_status_of "$r")"
assert_cmd "T11 dark-mode GET after message B is never a hit" is_not_hit "$cs"
assert_cmd "T11 dark-mode GET immediately reflects message B (no stale body)" body_contains "dark-msg-B-$RUN_ID"

# Restore a sane default so a human poking the stack afterwards sees caching
# behavior rather than a kill-switched server.
admin_patch_config "$ADMIN_TOKEN" '{"httpCachePageTtlSeconds":60}' >/dev/null

# =========================================================================
# T12: /messages/current — same bucket-isolation + purge-on-write shape as
# T1-T6, but for the "current page" shortcut endpoint rather than a numbered
# page. Own community/channel, self-contained.
# =========================================================================

echo "== T12: current =="

COMM_T12="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t12-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T12" ] || { echo "cache-smoke.sh: failed to create community for T12" >&2; exit 1; }
CHAN_T12="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM_T12\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN_T12" ] || { echo "cache-smoke.sh: failed to create channel for T12" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T12/members/add" "{\"userId\":$M1_ID}" >/dev/null
api_json "$M1_TOKEN" POST "/api/communities/$COMM_T12/channels/$CHAN_T12/messages" '{"text":"t12 seed"}' >/dev/null

CURRENT_PATH_T12="/api/communities/$COMM_T12/channels/$CHAN_T12/messages/current"

r="$(get_resource "$M1_TOKEN" "$CURRENT_PATH_T12")"
cs="$(cache_status_of "$r")"
assert_cmd "T12 member first GET /messages/current is a miss" is_miss "$cs"
assert_cmd "T12 response is stored" is_stored "$cs"

r="$(get_resource "$M1_TOKEN" "$CURRENT_PATH_T12")"
cs="$(cache_status_of "$r")"
assert_cmd "T12 member second GET /messages/current is a hit" is_hit "$cs"

api_json "$M1_TOKEN" POST "/api/communities/$COMM_T12/channels/$CHAN_T12/messages" '{"text":"t12 second message"}' >/dev/null

r="$(get_resource "$M1_TOKEN" "$CURRENT_PATH_T12")"
cs="$(cache_status_of "$r")"
assert_cmd "T12 GET /messages/current after new message is a miss (purge-on-send)" is_miss "$cs"
assert_cmd "T12 refreshed /messages/current contains the new message" body_contains "t12 second message"

# =========================================================================
# T13: current-coherency — the subtle invariant. Reacting to a message on
# the channel's LATEST page must also purge /messages/current, not just the
# numbered page it lives on (purgeChannelExtras alongside purgeChannelPage,
# Task 3 decision 1). Own community/channel.
# =========================================================================

echo "== T13: current-coherency (reaction purge) =="

COMM_T13="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t13-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T13" ] || { echo "cache-smoke.sh: failed to create community for T13" >&2; exit 1; }
CHAN_T13="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM_T13\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN_T13" ] || { echo "cache-smoke.sh: failed to create channel for T13" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T13/members/add" "{\"userId\":$M1_ID}" >/dev/null

MSG_T13_JSON="$(api_json "$M1_TOKEN" POST "/api/communities/$COMM_T13/channels/$CHAN_T13/messages" '{"text":"t13 seed"}')"
MSG_T13_ID="$(jq -r '.["@id"] // empty' <<<"$MSG_T13_JSON" | sed 's#.*/##')"
[ -n "$MSG_T13_ID" ] || { echo "cache-smoke.sh: failed to capture message id for T13" >&2; exit 1; }

CURRENT_PATH_T13="/api/communities/$COMM_T13/channels/$CHAN_T13/messages/current"

r="$(get_resource "$M1_TOKEN" "$CURRENT_PATH_T13")"
cs="$(cache_status_of "$r")"
assert_cmd "T13 priming GET /messages/current is stored" is_stored "$cs"

r="$(get_resource "$M1_TOKEN" "$CURRENT_PATH_T13")"
cs="$(cache_status_of "$r")"
assert_cmd "T13 priming GET /messages/current is a hit before the reaction" is_hit "$cs"

status="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/messages/$MSG_T13_ID/reactions" \
  -H "Authorization: Bearer $M1_TOKEN" -H 'Content-Type: application/ld+json' -H "$ACCEPT_HEADER" -d '{"emoji":"👍"}')"
assert_eq "T13 reacting to the message succeeds" "201" "$status"

r="$(get_resource "$M1_TOKEN" "$CURRENT_PATH_T13")"
cs="$(cache_status_of "$r")"
assert_cmd "T13 GET /messages/current after reacting is a miss (extras-purge invariant)" is_miss "$cs"

# =========================================================================
# T14: pinned-messages. Store/hit like T12, purge-on-pin, and the
# always-401-never-cached anonymous floor (mirrors T8's private-community
# shape, but here it's every caller, since /pinned-messages requires
# ROLE_USER regardless of the community's visibility).
# =========================================================================

echo "== T14: pinned =="

COMM_T14="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t14-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T14" ] || { echo "cache-smoke.sh: failed to create community for T14" >&2; exit 1; }
CHAN_T14="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM_T14\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN_T14" ] || { echo "cache-smoke.sh: failed to create channel for T14" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T14/members/add" "{\"userId\":$M1_ID}" >/dev/null

MSG_T14_JSON="$(api_json "$M1_TOKEN" POST "/api/communities/$COMM_T14/channels/$CHAN_T14/messages" '{"text":"t14 seed"}')"
MSG_T14_ID="$(jq -r '.["@id"] // empty' <<<"$MSG_T14_JSON" | sed 's#.*/##')"
[ -n "$MSG_T14_ID" ] || { echo "cache-smoke.sh: failed to capture message id for T14" >&2; exit 1; }

PINNED_PATH_T14="/api/communities/$COMM_T14/channels/$CHAN_T14/pinned-messages"

r="$(get_resource "$M1_TOKEN" "$PINNED_PATH_T14")"
cs="$(cache_status_of "$r")"
assert_cmd "T14 member first GET /pinned-messages is a miss" is_miss "$cs"
assert_cmd "T14 response is stored" is_stored "$cs"

r="$(get_resource "$M1_TOKEN" "$PINNED_PATH_T14")"
cs="$(cache_status_of "$r")"
assert_cmd "T14 member second GET /pinned-messages is a hit" is_hit "$cs"

status="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/messages/$MSG_T14_ID/pin" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "$ACCEPT_HEADER")"
assert_cmd "T14 pinning the message succeeds (HTTP $status)" [ "${status:0:1}" = "2" ]

r="$(get_resource "$M1_TOKEN" "$PINNED_PATH_T14")"
cs="$(cache_status_of "$r")"
assert_cmd "T14 GET /pinned-messages after pinning is a miss (purge-on-pin)" is_miss "$cs"

for attempt in 1 2; do
  r="$(get_resource "" "$PINNED_PATH_T14")"
  st="$(http_status_of "$r")"
  cs="$(cache_status_of "$r")"
  assert_eq "T14 anonymous GET /pinned-messages #$attempt is denied (401)" "401" "$st"
  assert_cmd "T14 anonymous GET /pinned-messages #$attempt is never a cache hit" is_not_hit "$cs"
done

# =========================================================================
# T15: community emojis. Anon store/hit on a public community, purge on
# upload, and the separate-variant proof: a member's FIRST GET must be a
# miss, not a hit against the anon-bucket entry (v1:anon vs v1:C{id}:standard
# are different Souin cache entries even for the same underlying data).
# =========================================================================

echo "== T15: emojis =="

COMM_T15="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t15-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T15" ] || { echo "cache-smoke.sh: failed to create community for T15" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T15/members/add" "{\"userId\":$M1_ID}" >/dev/null

EMOJIS_PATH_T15="/api/communities/$COMM_T15/emojis"

r="$(get_resource "" "$EMOJIS_PATH_T15")"
cs="$(cache_status_of "$r")"
assert_cmd "T15 anonymous first GET /emojis is a miss" is_miss "$cs"
assert_cmd "T15 response is stored" is_stored "$cs"

r="$(get_resource "" "$EMOJIS_PATH_T15")"
cs="$(cache_status_of "$r")"
assert_cmd "T15 anonymous second GET /emojis is a hit" is_hit "$cs"

EMOJI_PNG="$(mktemp)"
# 1x1 transparent PNG, same fixture Task 4's serialization canary uses.
base64 -d >"$EMOJI_PNG" <<'PNGB64'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA
60e6kgAAAABJRU5ErkJggg==
PNGB64
status="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/communities/$COMM_T15/emojis/custom" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "$ACCEPT_HEADER" \
  -F "shortcode=:cachet15:" -F "file=@$EMOJI_PNG;type=image/png")"
assert_eq "T15 uploading a custom emoji succeeds" "201" "$status"
rm -f "$EMOJI_PNG"

r="$(get_resource "" "$EMOJIS_PATH_T15")"
cs="$(cache_status_of "$r")"
assert_cmd "T15 anonymous GET /emojis after upload is a miss (purge-on-upload)" is_miss "$cs"
assert_cmd "T15 refreshed /emojis contains the new shortcode" body_contains ":cachet15:"

r="$(get_resource "$M1_TOKEN" "$EMOJIS_PATH_T15")"
cs="$(cache_status_of "$r")"
assert_cmd "T15 member's first GET /emojis is a miss, NOT a hit on the anon bucket (separate variants)" is_miss "$cs"

# =========================================================================
# T16: kill switch still global for the three new endpoints — same ttl=0
# no-store/never-a-hit floor as T7/T11, applied to /messages/current,
# /pinned-messages, and /emojis. Own community/channel.
# =========================================================================

echo "== T16: kill switch still global (current/pinned/emojis) =="

status="$(admin_patch_config "$ADMIN_TOKEN" '{"httpCachePageTtlSeconds":0}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to disable httpCachePageTtlSeconds for T16" >&2; exit 1; }

COMM_T16="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t16-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T16" ] || { echo "cache-smoke.sh: failed to create community for T16" >&2; exit 1; }
CHAN_T16="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM_T16\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN_T16" ] || { echo "cache-smoke.sh: failed to create channel for T16" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T16/members/add" "{\"userId\":$M1_ID}" >/dev/null
api_json "$M1_TOKEN" POST "/api/communities/$COMM_T16/channels/$CHAN_T16/messages" '{"text":"t16 seed"}' >/dev/null

for label in current pinned; do
  case "$label" in
    current) path="/api/communities/$COMM_T16/channels/$CHAN_T16/messages/current" ;;
    pinned) path="/api/communities/$COMM_T16/channels/$CHAN_T16/pinned-messages" ;;
  esac
  r="$(get_resource "$M1_TOKEN" "$path")"
  cc="$(cache_control_of "$r")"
  cs="$(cache_status_of "$r")"
  assert_cmd "T16 $label carries no s-maxage once ttl=0" has_no_smaxage "$cc"
  assert_cmd "T16 $label first GET under ttl=0 is not a hit" is_not_hit "$cs"
  r="$(get_resource "$M1_TOKEN" "$path")"
  cs="$(cache_status_of "$r")"
  assert_cmd "T16 $label repeat GET under ttl=0 stays non-hit" is_not_hit "$cs"
done

r="$(get_resource "" "/api/communities/$COMM_T16/emojis")"
cc="$(cache_control_of "$r")"
cs="$(cache_status_of "$r")"
assert_cmd "T16 emojis carries no s-maxage once ttl=0" has_no_smaxage "$cc"
assert_cmd "T16 emojis first GET under ttl=0 is not a hit" is_not_hit "$cs"
r="$(get_resource "" "/api/communities/$COMM_T16/emojis")"
cs="$(cache_status_of "$r")"
assert_cmd "T16 emojis repeat GET under ttl=0 stays non-hit" is_not_hit "$cs"

status="$(admin_patch_config "$ADMIN_TOKEN" '{"httpCachePageTtlSeconds":60}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to re-enable httpCachePageTtlSeconds after T16" >&2; exit 1; }

# =========================================================================
# T17 (security-review carry-over): dot-segment probe. Caddy's @cacheable
# path_regexp and the forward_auth hash sub-request must agree on what a
# request path IS — if Caddy cleans/normalizes a dot-segment path one way
# for routing/caching purposes while the hash endpoint (and PHP's own
# router) see a different (raw, un-normalized) path, a request that LOOKS
# like it targets one bucket could be cached under another. Unit tests never
# cross Caddy, so this is the only place this seam gets exercised. Sent with
# --path-as-is so curl itself doesn't collapse the dot-segment before it
# ever reaches Caddy.
#
# Observed (image-level smoke, Task 5): Caddy's path_regexp DOES match this
# path (its internal request-path cleaning collapses the %2e%2e segment
# before @cacheable evaluates), so the request IS routed through the
# forward_auth + cache handler — but PHP's own router receives the RAW,
# uncleaned path (Symfony has no route for the literal ".../emojis/../emojis"
# string) and 404s. That 404 carries the framework's default
# "Cache-Control: no-cache, private" (HttpCacheHeadersListener only ever
# adds Vary/s-maxage on a *successful* response), which forces revalidation
# on every subsequent request even though Souin "stores" a copy under
# bypass_request mode — so it is never served as a cache hit, on this
# request or a repeat of it. Asserted directly below rather than assumed.
# =========================================================================

echo "== T17: dot-segment probe =="

DOTSEG_PATH="/api/communities/$COMM1/emojis/%2e%2e/emojis"

for attempt in 1 2; do
  r="$(curl -s --path-as-is -D- -o "$BODY_FILE" -w 'HTTP_STATUS:%{http_code}\n' -H "$ACCEPT_HEADER" "$BASE$DOTSEG_PATH")"
  st="$(http_status_of "$r")"
  cs="$(cache_status_of "$r")"
  if [ "$st" = "404" ]; then
    pass "T17 dot-segment probe #$attempt is a plain 404"
  else
    pass "T17 dot-segment probe #$attempt returned HTTP $st (not a 404, but still checked below)"
  fi
  assert_cmd "T17 dot-segment probe #$attempt is never a cache hit" is_not_hit "$cs"
done

# =========================================================================
# T18 (v3): thread replies. Same store/hit shape as T12/T14, but purge comes
# from TWO distinct call sites: `publishMessageThreadMeta` (fired when a
# reply is posted — purges the thread + the root's page) and the ordinary
# per-message publishers via `purgeThreadFor` (fired when an EXISTING reply
# is mutated, e.g. reacted to — purges the parent's thread). Own
# community/channel.
# =========================================================================

echo "== T18: thread =="

COMM_T18="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t18-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T18" ] || { echo "cache-smoke.sh: failed to create community for T18" >&2; exit 1; }
CHAN_T18="$(api_json "$ADMIN_TOKEN" POST /api/channels "{\"name\":\"general\",\"type\":\"text\",\"community\":\"/api/communities/$COMM_T18\"}" | jq -r '.identifier // empty')"
[ -n "$CHAN_T18" ] || { echo "cache-smoke.sh: failed to create channel for T18" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T18/members/add" "{\"userId\":$M1_ID}" >/dev/null

ROOT_T18_JSON="$(api_json "$M1_TOKEN" POST "/api/communities/$COMM_T18/channels/$CHAN_T18/messages" '{"text":"t18 root"}')"
ROOT_T18_ID="$(jq -r '.["@id"] // empty' <<<"$ROOT_T18_JSON" | sed 's#.*/##')"
[ -n "$ROOT_T18_ID" ] || { echo "cache-smoke.sh: failed to capture root id for T18" >&2; exit 1; }

REPLY1_T18_JSON="$(api_json "$M1_TOKEN" POST "/api/messages/$ROOT_T18_ID/replies" '{"text":"t18 reply one"}')"
REPLY1_T18_ID="$(jq -r '.["@id"] // empty' <<<"$REPLY1_T18_JSON" | sed 's#.*/##')"
[ -n "$REPLY1_T18_ID" ] || { echo "cache-smoke.sh: failed to capture reply id for T18" >&2; exit 1; }

THREAD_PATH_T18="/api/messages/$ROOT_T18_ID/thread"

r="$(get_resource "$M1_TOKEN" "$THREAD_PATH_T18")"
cs="$(cache_status_of "$r")"
assert_cmd "T18 member first GET thread is a miss" is_miss "$cs"
assert_cmd "T18 response is stored" is_stored "$cs"

r="$(get_resource "$M1_TOKEN" "$THREAD_PATH_T18")"
cs="$(cache_status_of "$r")"
assert_cmd "T18 member second GET thread is a hit" is_hit "$cs"

api_json "$M1_TOKEN" POST "/api/messages/$ROOT_T18_ID/replies" '{"text":"t18 reply two"}' >/dev/null

r="$(get_resource "$M1_TOKEN" "$THREAD_PATH_T18")"
cs="$(cache_status_of "$r")"
assert_cmd "T18 GET thread after second reply is a miss (purge via thread-meta)" is_miss "$cs"
assert_cmd "T18 refreshed thread contains the second reply" body_contains "t18 reply two"

r="$(get_resource "$M1_TOKEN" "$THREAD_PATH_T18")"
cs="$(cache_status_of "$r")"
assert_cmd "T18 GET thread is a hit again before the reaction" is_hit "$cs"

status="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/messages/$REPLY1_T18_ID/reactions" \
  -H "Authorization: Bearer $M1_TOKEN" -H 'Content-Type: application/ld+json' -H "$ACCEPT_HEADER" -d '{"emoji":"👍"}')"
assert_eq "T18 reacting to the reply succeeds" "201" "$status"

r="$(get_resource "$M1_TOKEN" "$THREAD_PATH_T18")"
cs="$(cache_status_of "$r")"
assert_cmd "T18 GET thread after reacting to reply is a miss (reply-mutation purge)" is_miss "$cs"

# =========================================================================
# T19 (v3): presence summary — own TTL knob (httpCachePresenceTtlSeconds),
# independent of httpCachePageTtlSeconds (left exactly as the earlier
# matrix set it — 60, restored after T16). Same store/hit/collapsing shape
# as T1-T4, but the count is a point-in-time snapshot, not per-message
# content — a "hit" only proves the shared-bucket collapsing works, it says
# nothing about the online count's freshness (documented constraint: no
# purge on this endpoint, TTL-only).
# =========================================================================

echo "== T19: presence =="

status="$(admin_patch_config "$ADMIN_TOKEN" '{"httpCachePresenceTtlSeconds":15}')"
[ "$status" = "200" ] || { echo "cache-smoke.sh: failed to enable httpCachePresenceTtlSeconds" >&2; exit 1; }

COMM_T19="$(api_json "$ADMIN_TOKEN" POST /api/communities "{\"name\":\"cache-t19-$RUN_ID\",\"isPrivate\":false}" | jq -r '.identifier // empty')"
[ -n "$COMM_T19" ] || { echo "cache-smoke.sh: failed to create community for T19" >&2; exit 1; }
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T19/members/add" "{\"userId\":$M1_ID}" >/dev/null
api_json "$ADMIN_TOKEN" POST "/api/communities/$COMM_T19/members/add" "{\"userId\":$M2_ID}" >/dev/null

PRESENCE_PATH_T19="/api/communities/$COMM_T19/presence/summary"

r="$(get_resource "$M1_TOKEN" "$PRESENCE_PATH_T19")"
cs="$(cache_status_of "$r")"
assert_cmd "T19 member1 first GET summary is a miss" is_miss "$cs"
assert_cmd "T19 response is stored" is_stored "$cs"

# Souin only emits a `ttl=<seconds-remaining>` parameter in Cache-Status on a
# HIT, never on the initial store — so this must read the second (hit)
# request, not the first.
r="$(get_resource "$M1_TOKEN" "$PRESENCE_PATH_T19")"
cs="$(cache_status_of "$r")"
assert_cmd "T19 member1 second GET summary is a hit" is_hit "$cs"

ttl="$(grep -o 'ttl=[0-9]*' <<<"$cs" | head -1 | cut -d= -f2 || true)"
if [ -n "$ttl" ] && [ "$ttl" -le 15 ]; then
  pass "T19 Cache-Status ttl is <= 15 (got $ttl)"
else
  fail "T19 Cache-Status ttl is <= 15 (got '$ttl' from: $cs)"
fi

r="$(get_resource "$M2_TOKEN" "$PRESENCE_PATH_T19")"
cs="$(cache_status_of "$r")"
assert_cmd "T19 member2 GET summary hits member1's shared standard-bucket entry (collapsing works)" is_hit "$cs"

r="$(get_resource "$ADMIN_TOKEN" "$PRESENCE_PATH_T19")"
cs="$(cache_status_of "$r")"
assert_cmd "T19 admin GET summary is a miss (elevated fingerprint bucket, never shared)" is_miss "$cs"

# =========================================================================
# T20 (v3): thread fail-closed. Same shape as T8's private-community
# anonymous-denial floor, applied to the thread endpoint: anonymous never
# gets in (401, never cached), and a non-member of a private community
# never gets in either (403/404 — MessageVoter delegates to ChannelVoter::
# VIEW, denied to an authenticated-but-unrelated caller), never cached.
# Reuses COMM2/CHAN2 (private community) + its seed message from T8.
# =========================================================================

echo "== T20: thread fail-closed =="

PRIVATE_SEED_JSON="$(api_json "$M1_TOKEN" GET "/api/communities/$COMM2/channels/$CHAN2/messages/current")"
PRIVATE_ROOT_ID="$(jq -r '.messages[0]["@id"] // empty' <<<"$PRIVATE_SEED_JSON" | sed 's#.*/##')"
[ -n "$PRIVATE_ROOT_ID" ] || { echo "cache-smoke.sh: failed to locate T8's private seed message for T20" >&2; exit 1; }

PRIVATE_THREAD_PATH="/api/messages/$PRIVATE_ROOT_ID/thread"

for attempt in 1 2; do
  r="$(get_resource "" "$PRIVATE_THREAD_PATH")"
  st="$(http_status_of "$r")"
  cs="$(cache_status_of "$r")"
  assert_eq "T20 anonymous GET private thread #$attempt is denied (401)" "401" "$st"
  assert_cmd "T20 anonymous GET private thread #$attempt is never a cache hit" is_not_hit "$cs"
done

for attempt in 1 2; do
  r="$(get_resource "$M2_TOKEN" "$PRIVATE_THREAD_PATH")"
  st="$(http_status_of "$r")"
  cs="$(cache_status_of "$r")"
  if [ "$st" = "403" ] || [ "$st" = "404" ]; then
    pass "T20 non-member GET private thread #$attempt is denied ($st)"
  else
    fail "T20 non-member GET private thread #$attempt is denied (403/404) (got HTTP $st)"
  fi
  assert_cmd "T20 non-member GET private thread #$attempt is never a cache hit" is_not_hit "$cs"
done

# =========================================================================
# T21 (v4): community detail. Store/hit shape like T1-T5, but the whole
# point of this batch is the standard-bucket COLLAPSE: a non-member of a
# PUBLIC community must hit the exact same cache entry a member already
# stored -- same Cache-Status hit, byte-identical body -- not merely land in
# a compatible bucket. Then: admin still gets its own elevated-fingerprint
# miss (T4-shape); a structure mutation (channel rename, NOT a message send)
# must purge the cached detail; and GET .../membership -- the endpoint that
# made this whole promotion possible by carrying the per-viewer state the
# detail payload no longer does -- must never itself carry cache headers or
# hit. Reuses COMM1/CHAN1 (already has member1 + member2 as members).
# =========================================================================

echo "== T21: community detail =="

DETAIL_PATH_T21="/api/communities/$COMM1"

r="$(get_resource "$M1_TOKEN" "$DETAIL_PATH_T21")"
cs="$(cache_status_of "$r")"
assert_cmd "T21 member first GET community detail is a miss" is_miss "$cs"
assert_cmd "T21 response is stored" is_stored "$cs"

r="$(get_resource "$M1_TOKEN" "$DETAIL_PATH_T21")"
cs="$(cache_status_of "$r")"
assert_cmd "T21 member second GET community detail is a hit" is_hit "$cs"
MEMBER_BODY_T21="$(cat "$BODY_FILE")"

# A fresh, never-before-seen user who has NEVER joined COMM1 -- a genuine
# non-member of a PUBLIC community, distinct from every user used elsewhere
# in this script (member1/member2 are both members of COMM1).
NONMEMBER_EMAIL_T21="cache-t21-nonmember-$RUN_ID@example.com"
NONMEMBER_ID_T21="$(api_json "" POST /api/users "{\"email\":\"$NONMEMBER_EMAIL_T21\",\"password\":\"nonmemberpass\",\"displayName\":\"T21 Nonmember\"}" | jq -r '.id // empty')"
[ -n "$NONMEMBER_ID_T21" ] || { echo "cache-smoke.sh: failed to register T21 non-member" >&2; exit 1; }
NONMEMBER_TOKEN_T21="$(login "$NONMEMBER_EMAIL_T21" nonmemberpass)"
[ -n "$NONMEMBER_TOKEN_T21" ] || { echo "cache-smoke.sh: failed to log in T21 non-member" >&2; exit 1; }

r="$(get_resource "$NONMEMBER_TOKEN_T21" "$DETAIL_PATH_T21")"
cs="$(cache_status_of "$r")"
NONMEMBER_BODY_T21="$(cat "$BODY_FILE")"
assert_cmd "T21 non-member GET hits the SAME standard-bucket entry the member already stored (the whole point of this batch)" is_hit "$cs"
assert_eq "T21 non-member and member community-detail bodies are byte-identical" "$MEMBER_BODY_T21" "$NONMEMBER_BODY_T21"

r="$(get_resource "$ADMIN_TOKEN" "$DETAIL_PATH_T21")"
cs="$(cache_status_of "$r")"
assert_cmd "T21 admin GET community detail is a miss (elevated fingerprint bucket, never shared)" is_miss "$cs"

# Structure mutation (channel rename), not a message send -- must purge the
# cached community detail via CachePurgingStructurePublisher, not the
# message-page purge path.
status="$(curl -s -o /dev/null -w '%{http_code}' -X PATCH "$BASE/api/communities/$COMM1/channels/$CHAN1" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/merge-patch+json' -d "{\"name\":\"renamed-$RUN_ID\"}")"
assert_eq "T21 renaming a channel succeeds" "200" "$status"

r="$(get_resource "$M1_TOKEN" "$DETAIL_PATH_T21")"
cs="$(cache_status_of "$r")"
assert_cmd "T21 GET community detail after channel rename is a miss (structure purge fired)" is_miss "$cs"

# GET .../membership carries the per-viewer state the cached detail payload
# no longer does -- it must never itself be cached, or that state goes
# stale/shared right back into the leak this whole batch removed.
MEMBERSHIP_PATH_T21="/api/communities/$COMM1/membership"
for attempt in 1 2; do
  r="$(get_resource "$M1_TOKEN" "$MEMBERSHIP_PATH_T21")"
  # membership is outside the Caddy @cacheable matcher entirely, so unlike
  # every guarded endpoint above it, Souin never touches the request and
  # Cache-Status is simply absent, not merely non-hit — cache_control_of/
  # cache_status_of's internal `grep | tr` pipeline propagates grep's no-match
  # exit status through `pipefail` (tr itself always exits 0), so the `||
  # true` is required here to avoid aborting the script under `set -e`.
  cc="$(cache_control_of "$r" || true)"
  cs="$(cache_status_of "$r" || true)"
  assert_cmd "T21 GET .../membership #$attempt carries no s-maxage" has_no_smaxage "$cc"
  assert_cmd "T21 GET .../membership #$attempt is never a cache hit" is_not_hit "$cs"
done

echo "=============================="
if [ "$FAILED" -eq 0 ]; then
  echo "cache-smoke.sh: ALL CHECKS PASSED"
  exit 0
else
  echo "cache-smoke.sh: ONE OR MORE CHECKS FAILED"
  exit 1
fi
