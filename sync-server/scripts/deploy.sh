#!/usr/bin/env bash
set -euo pipefail

# MEtR sync-server deploy.
# Pushes the Laravel backend to the18th, clears caches, and updates the
# main_proxy vhost.
#
# Usage:
#   ./scripts/deploy.sh                # backend code + proxy
#   ./scripts/deploy.sh --proxy-only   # only the main_proxy vhost
#
# The Tauri desktop client is released separately — see ../../DEPLOYMENT.md.

cd "$(dirname "$0")/.."

SERVER="root@the18th"
SSH_KEY="${METR_SSH_KEY:-$HOME/.ssh/id_the18th_root}"
SSH_OPTS=()
[ -f "$SSH_KEY" ] && SSH_OPTS=(-i "$SSH_KEY")

REMOTE_ROOT="/opt/metr-sync/site"
PROXY_ONLY=false

for arg in "$@"; do
  case "$arg" in
    --proxy-only) PROXY_ONLY=true ;;
    *) echo "unknown flag: $arg"; exit 1 ;;
  esac
done

echo "═══ MEtR sync-server deploy ═══"

if ! $PROXY_ONLY; then
  echo "[1/3] Syncing backend (Laravel)..."
  rsync -avz \
    --exclude='.env' \
    --exclude='vendor/' \
    --exclude='node_modules/' \
    --exclude='storage/logs/' \
    --exclude='storage/framework/cache/' \
    --exclude='storage/framework/sessions/' \
    --exclude='storage/framework/views/' \
    -e "ssh ${SSH_OPTS[*]}" \
    ./backend/ "$SERVER:$REMOTE_ROOT/backend/"

  echo "[1/3] Refreshing route + config cache..."
  ssh "${SSH_OPTS[@]}" "$SERVER" "docker exec metr-sync-php sh -lc 'cd /var/www/html && \
    php artisan migrate --force && \
    php artisan config:cache && \
    php artisan route:cache'"

  echo "[1/3] Running preflight on server..."
  ssh "${SSH_OPTS[@]}" "$SERVER" "cd $REMOTE_ROOT && bash scripts/metr_sync_preflight.sh" || {
    echo "  ⚠ preflight failed — investigate before next deploy"
  }
fi

echo "[2/3] Pushing main_proxy vhost..."
rsync -avz -e "ssh ${SSH_OPTS[*]}" \
  ./proxy/76-metr-sync.conf "$SERVER:/opt/proxy/conf.d/76-metr-sync.conf"

echo "[3/3] Validating + reloading main_proxy..."
if ssh "${SSH_OPTS[@]}" "$SERVER" "docker exec main_proxy nginx -t" 2>&1; then
  ssh "${SSH_OPTS[@]}" "$SERVER" "docker exec main_proxy nginx -s reload"
  echo "  ✓ main_proxy reloaded"
else
  echo "  ✗ nginx -t FAILED — main_proxy NOT reloaded."
  exit 1
fi

echo
echo "✅ Deploy complete — https://metr.petarpetkov.com/health"
