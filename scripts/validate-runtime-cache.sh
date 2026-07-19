#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

for cache_file in bootstrap/cache/packages.php bootstrap/cache/services.php; do
    test -f "$cache_file"
    test ! -w "$cache_file" || test "$(stat -c '%A' "$cache_file" | cut -c10)" = "-"
    if [ "$(id -u)" -eq 0 ]; then
        runuser -u www-data -- test -r "$cache_file"
    else
        # Non-root deployment operators cannot switch identities.  Require a
        # read bit for the worker's supplementary-group/other access path;
        # root deployment runs the stronger identity-specific check above.
        test "$(stat -c '%A' "$cache_file" | cut -c8)" = "r"
    fi
done

php artisan tinker --execute='if (! app()->bound("view")) { throw new RuntimeException("view service is not bound"); }' >/dev/null
echo "Runtime cache permissions and view binding are valid."
