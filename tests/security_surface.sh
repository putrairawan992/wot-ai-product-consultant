#!/usr/bin/env bash
# Verifies the parts of the security surface that do not need a model call:
# Turnstile gating, response headers, and docroot isolation.
#
#   bash tests/security_surface.sh [port]
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8089}"
DATA="$(mktemp -d)"
FAILED=0

pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAILED=$((FAILED + 1)); }

cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null; rm -rf "$DATA"; }
trap cleanup EXIT

cd "$ROOT" || exit 1
CF_TURNSTILE_SECRET=test-secret CF_TURNSTILE_SITEKEY=1x00000000000000000000AA \
  WOT_DATA_DIR="$DATA" php -S "127.0.0.1:$PORT" -t public >/dev/null 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
  curl -s -o /dev/null "http://127.0.0.1:$PORT/api.php?config=1" && break
  sleep 0.25
done

# 1) The widget learns the sitekey, never the secret.
CONFIG=$(curl -s "http://127.0.0.1:$PORT/api.php?config=1")
if printf '%s' "$CONFIG" | grep -q '1x00000000000000000000AA' && ! printf '%s' "$CONFIG" | grep -q 'test-secret'; then
  pass "config exposes sitekey only"
else
  fail "config response: $CONFIG"
fi

# 2) With the secret set, a request without a token is rejected before any model call.
CODE=$(curl -s -o /tmp/wot-sec-1 -w '%{http_code}' -X POST "http://127.0.0.1:$PORT/api.php" \
  -H 'Content-Type: application/json' -d '{"message":"ISO 6506","history":[]}')
if [ "$CODE" = "403" ] && grep -q 'captcha_failed' /tmp/wot-sec-1; then
  pass "Turnstile enforced without token (403 captcha_failed)"
else
  fail "missing token: $CODE $(cat /tmp/wot-sec-1)"
fi

# 3) A forged token is rejected too.
CODE=$(curl -s -o /tmp/wot-sec-2 -w '%{http_code}' -X POST "http://127.0.0.1:$PORT/api.php" \
  -H 'Content-Type: application/json' -d '{"message":"ISO 6506","history":[],"cf_token":"forged"}')
if [ "$CODE" = "403" ] && grep -q 'captcha_failed' /tmp/wot-sec-2; then
  pass "Turnstile rejects forged token (403)"
else
  fail "forged token: $CODE $(cat /tmp/wot-sec-2)"
fi

# 4) No PHP version banner.
if curl -s -D- -o /dev/null "http://127.0.0.1:$PORT/api.php?config=1" | grep -qi 'x-powered-by'; then
  fail "X-Powered-By header still present"
else
  pass "X-Powered-By removed"
fi

# 5) Nothing above the docroot is reachable.
for PATH_INFO in /lib/catalog.php /lib/security.php /products.json /products_enriched.json /data/queries.db; do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' --path-as-is "http://127.0.0.1:$PORT$PATH_INFO")
  if [ "$CODE" = "404" ] || [ "$CODE" = "403" ]; then
    pass "not served: $PATH_INFO"
  else
    fail "$PATH_INFO answered $CODE"
  fi
done

echo
if [ "$FAILED" -eq 0 ]; then
  echo "security surface: all checks passed"
else
  echo "security surface: $FAILED failure(s)"
  exit 1
fi
