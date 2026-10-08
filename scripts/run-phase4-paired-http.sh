#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

port="${PHASE4_HTTP_PORT:-18947}"
state="$(mktemp)"
log="$(mktemp)"
cleanup() {
  [[ -n "${server_pid:-}" ]] && kill -TERM "$server_pid" 2>/dev/null || true
  wait "${server_pid:-0}" 2>/dev/null || true
  rm -f "$state" "$log"
  bash scripts/rebuild-test-db.sh cleanup >/dev/null || true
  APP_ENV=production bash scripts/rebuild-production-cache.sh >/dev/null || true
}
trap cleanup EXIT INT TERM

PHASE4_RECEIVER_STATE="$state" php -S "127.0.0.1:${port}" tests/Support/phase4_http_receiver.php >"$log" 2>&1 &
server_pid=$!
for _ in $(seq 1 30); do
  if curl -sS -o /dev/null "http://127.0.0.1:${port}" 2>/dev/null; then break; fi
  sleep 0.1
done

APP_ENV=testing bash scripts/rebuild-test-db.sh rebuild
export APP_ENV=testing DB_CONNECTION=tenant CENTRAL_CONNECTION=mysql TENANT_CONNECTION=tenant
export DB_DATABASE=solastock_test_central TENANT_DB_DATABASE=solastock_test_a
export SOLASTOCK_TEST_TENANT_A=solastock_test_a SOLASTOCK_TEST_TENANT_B=solastock_test_b
export SOLASTOCK_TEST_CENTRAL=solastock_test_central INVENTORY_USE_DERIVED_TENANT_DB_USER=false
export PHASE4_REAL_HTTP_URL="http://127.0.0.1:${port}/api/v1/journal-entries"
php artisan config:clear --no-ansi
php artisan test --compact --filter=durable_transport_uses_real_http_and_recovers_remote_commit
