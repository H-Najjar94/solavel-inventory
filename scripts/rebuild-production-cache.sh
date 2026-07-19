#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# run-tests.sh exports testing overrides in its parent shell.  Never carry
# those values into the cache served by the live web worker.
export APP_ENV=production
unset DB_DATABASE TENANT_DB_DATABASE SOLASTOCK_TEST_TENANT_A SOLASTOCK_TEST_TENANT_B SOLASTOCK_TEST_CENTRAL INVENTORY_USE_DERIVED_TENANT_DB_USER TENANT_DERIVE_PREVIOUS_SECRET_FALLBACK

# Laravel's optimization commands create a few cache files with the caller's
# umask.  The web worker is www-data; keep the files private to the deployment
# group while making them readable by the worker.
umask 0002
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

for cache_file in bootstrap/cache/packages.php bootstrap/cache/services.php; do
    chmod 0664 "$cache_file"
done

"$PWD/scripts/validate-runtime-cache.sh"
