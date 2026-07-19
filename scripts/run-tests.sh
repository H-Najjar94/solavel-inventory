#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

export APP_ENV=testing

cleanup() {
  bash scripts/rebuild-test-db.sh cleanup >/dev/null || true
  APP_ENV=production bash scripts/rebuild-production-cache.sh >/dev/null || true
}
trap cleanup EXIT INT TERM

bash scripts/rebuild-test-db.sh rebuild
export DB_CONNECTION=tenant
export CENTRAL_CONNECTION=mysql
export TENANT_CONNECTION=tenant
export DB_DATABASE=solastock_test_central
export TENANT_DB_DATABASE=solastock_test_a
export SOLASTOCK_TEST_TENANT_A=solastock_test_a
export SOLASTOCK_TEST_TENANT_B=solastock_test_b
export SOLASTOCK_TEST_CENTRAL=solastock_test_central
export INVENTORY_USE_DERIVED_TENANT_DB_USER=false

APP_ENV=testing php artisan config:clear --ansi
php artisan test --compact "$@"
