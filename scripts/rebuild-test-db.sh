#!/usr/bin/env bash
# Recreate SolaStock's disposable MySQL test schemas on an explicitly isolated
# server. Never source the application .env and never use a production-capable
# listener.
set -euo pipefail
cd "$(dirname "$0")/.."

TENANT_A="solastock_test_a"
TENANT_B="solastock_test_b"
CENTRAL="solastock_test_central"

case "${1:-rebuild}" in
  rebuild|cleanup) ;;
  *) echo "Usage: $0 [rebuild|cleanup]" >&2; exit 2 ;;
esac

[[ "${TEST_DATABASE_ENVIRONMENT:-}" == "isolated_staging" ]] || {
  echo "REFUSING: SolaStock rebuild requires TEST_DATABASE_ENVIRONMENT=isolated_staging." >&2
  exit 2
}
TEST_SOCKET="${TEST_DB_SOCKET:-}"
TEST_USER="${TEST_SCHEMA_DB_USER:-}"
[[ -S "$TEST_SOCKET" && -n "$TEST_USER" ]] || {
  echo "REFUSING: isolated socket and scoped schema user are required." >&2
  exit 2
}
[[ "$(mysql --protocol=socket --socket="$TEST_SOCKET" --user="$TEST_USER" --skip-password -N -e 'SELECT @@port')" == "0" ]] || {
  echo "REFUSING: disposable database server is network reachable." >&2
  exit 2
}

export APP_CONFIG_CACHE=/tmp/phase6a-stock-rebuild-config.php
export APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
export TENANT_DERIVE_SECRET=phase6a-test-only-tenant-derivation-secret-000000000000000000000000
export DB_HOST=localhost DB_PORT=0 DB_SOCKET="$TEST_SOCKET"
export DB_USERNAME="$TEST_USER" DB_PASSWORD=
export TENANT_DB_ADMIN_USER="$TEST_USER" TENANT_DB_ADMIN_PASS=
export INVENTORY_USE_DERIVED_TENANT_DB_USER=false

manage_databases() {
  local mode="$1"
  local db
  for db in "$TENANT_A" "$TENANT_B" "$CENTRAL"; do
    [[ "$db" =~ ^solastock_test_[a-z]+$ ]] || { echo "REFUSING: unsafe database identity." >&2; exit 2; }
    mysql --protocol=socket --socket="$TEST_SOCKET" --user="$TEST_USER" --skip-password \
      -e "DROP DATABASE IF EXISTS \`${db}\`;"
    if [[ "$mode" == rebuild ]]; then
      mysql --protocol=socket --socket="$TEST_SOCKET" --user="$TEST_USER" --skip-password \
        -e "CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    fi
  done
}

if [ "${1:-rebuild}" = "cleanup" ]; then
  manage_databases cleanup
  echo "SolaStock disposable test schemas removed."
  exit 0
fi

manage_databases rebuild

APP_ENV=testing DB_DATABASE="$CENTRAL" php artisan migrate:fresh \
  --database=mysql --path=database/migrations/landlord --force --no-interaction >/dev/null

# The real User model is pinned to Central. SolaStock owns no Central users
# migration, so the disposable harness supplies only the columns its auth tests
# consume; production Central remains the source of truth.
APP_ENV=testing DB_DATABASE="$CENTRAL" php artisan tinker --execute="
  if (!Schema::connection('mysql')->hasTable('users')) {
      Schema::connection('mysql')->create('users', function (\$table) {
          \$table->id();
          \$table->unsignedBigInteger('client_id')->nullable();
          \$table->string('name');
          \$table->string('email')->unique();
          \$table->string('phone')->nullable();
          \$table->string('identification_number')->nullable();
          \$table->text('address')->nullable();
          \$table->string('password');
          \$table->string('status')->nullable();
          \$table->rememberToken();
          \$table->timestamps();
      });
  }
  if (!Schema::connection('mysql')->hasTable('entitlement_state_snapshots')) {
      Schema::connection('mysql')->create('entitlement_state_snapshots', function (\$table) {
          \$table->id();
          \$table->unsignedBigInteger('organization_id')->unique();
          \$table->unsignedBigInteger('subscription_id')->nullable();
          \$table->string('underlying_subscription_state');
          \$table->string('effective_access_state');
          \$table->string('plan_code')->nullable();
          \$table->char('state_hash', 64);
          \$table->json('state_payload');
          \$table->timestamp('evaluated_at')->nullable();
          \$table->timestamp('last_changed_at')->nullable();
          \$table->timestamps();
      });
  }
" >/dev/null

for db in "$TENANT_A" "$TENANT_B"; do
  APP_ENV=testing TENANT_DB_DATABASE="$db" INVENTORY_USE_DERIVED_TENANT_DB_USER=false \
    php artisan migrate:fresh --database=tenant --path=database/migrations/tenant \
      --force --no-interaction >/dev/null

  # The connection wizard compares authoritative SolaStock records with a
  # read-only Finance projection. Production receives these tables from the
  # paired application schema; the isolated Stock suite supplies their minimal
  # shape explicitly so no test reaches a Finance or production database.
  mysql --protocol=socket --socket="$TEST_SOCKET" --user="$TEST_USER" --skip-password "$db" <<'SQL'
CREATE TABLE IF NOT EXISTS organizations (
  id BIGINT UNSIGNED PRIMARY KEY,
  central_org_id BIGINT UNSIGNED NOT NULL
);
CREATE TABLE IF NOT EXISTS accounts (
  id BIGINT UNSIGNED PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(32) NULL,
  name VARCHAR(191) NULL,
  type VARCHAR(32) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_postable TINYINT(1) NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(191) NULL,
  barcode VARCHAR(191) NULL,
  name VARCHAR(191) NULL,
  category_id BIGINT UNSIGNED NULL,
  unit_id BIGINT UNSIGNED NULL,
  qty_on_hand DECIMAL(18,4) NOT NULL DEFAULT 0,
  average_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
  valuation_method VARCHAR(30) NULL,
  tracking_type VARCHAR(30) NULL,
  inventory_asset_account_id BIGINT UNSIGNED NULL,
  cogs_account_id BIGINT UNSIGNED NULL,
  income_account_id BIGINT UNSIGNED NULL,
  deleted_at TIMESTAMP NULL,
  KEY inventory_items_org_sku_idx (organization_id, sku)
);
SQL
done

echo "SolaStock disposable test schemas rebuilt: ${TENANT_A}, ${TENANT_B}, ${CENTRAL}."
