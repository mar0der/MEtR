#!/usr/bin/env bash
set -euo pipefail

ROOT="${METR_SYNC_ROOT:-/opt/metr-sync/site}"
BASE_URL="${METR_SYNC_BASE_URL:-https://metr.example.com}"

pass() { echo "[PASS] $*"; }
fail() { echo "[FAIL] $*" >&2; exit 1; }

cd "$ROOT" || fail "missing root $ROOT"

test -f .env || fail "missing root .env"
test -f backend/.env || fail "missing backend/.env"

docker compose config >/dev/null || fail "docker compose config failed"
docker compose ps || fail "docker compose ps failed"

docker volume inspect metr_sync_db_data >/dev/null || fail "missing db volume metr_sync_db_data"

for c in metr-sync-db metr-sync-redis metr-sync-php metr-sync-scheduler metr-sync-nginx; do
  docker inspect "$c" >/dev/null 2>&1 || fail "missing container $c"
  state="$(docker inspect -f '{{.State.Running}}' "$c")"
  [ "$state" = "true" ] || fail "$c is not running"
  pass "$c running"
done

docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan about --only=environment' >/dev/null \
  || fail "Laravel does not boot"
pass "Laravel boots"

docker exec metr-sync-php sh -lc 'cd /var/www/html && php artisan migrate:status' >/dev/null \
  || fail "migration status failed"
pass "migrations visible"

curl -fsS http://127.0.0.1:8090/health >/dev/null || fail "local health failed"
pass "local health ok"

if [ "$BASE_URL" != "https://metr.example.com" ]; then
  curl -fsS "$BASE_URL/health" >/dev/null || fail "public health failed"
  pass "public health ok"
fi
