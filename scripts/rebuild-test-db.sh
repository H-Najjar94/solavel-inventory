#!/usr/bin/env bash
# Recreate SolaStock's disposable MySQL test schemas using .env.testing only.
set -euo pipefail
cd "$(dirname "$0")/.."

TENANT_A="solastock_test_a"
TENANT_B="solastock_test_b"
CENTRAL="solastock_test_central"

case "${1:-rebuild}" in
  rebuild|cleanup) ;;
  *) echo "Usage: $0 [rebuild|cleanup]" >&2; exit 2 ;;
esac

manage_databases() {
  local mode="$1"
  APP_ENV=testing DB_DATABASE= TENANT_DB_DATABASE= \
    php artisan tinker --execute="
      config(['database.connections.mysql.database' => null]);
      DB::purge('mysql');
      foreach (['${TENANT_A}', '${TENANT_B}', '${CENTRAL}'] as \$db) {
          if (!in_array(\$db, ['solastock_test_a', 'solastock_test_b', 'solastock_test_central'], true)) throw new RuntimeException('unsafe test database');
          DB::connection('mysql')->statement('DROP DATABASE IF EXISTS '.\$db);
          if ('${mode}' === 'rebuild') {
              DB::connection('mysql')->statement('CREATE DATABASE '.\$db.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
          }
      }
    " >/dev/null
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
" >/dev/null

for db in "$TENANT_A" "$TENANT_B"; do
  APP_ENV=testing TENANT_DB_DATABASE="$db" INVENTORY_USE_DERIVED_TENANT_DB_USER=false \
    php artisan migrate:fresh --database=tenant --path=database/migrations/tenant \
      --force --no-interaction >/dev/null
done

echo "SolaStock disposable test schemas rebuilt: ${TENANT_A}, ${TENANT_B}, ${CENTRAL}."
