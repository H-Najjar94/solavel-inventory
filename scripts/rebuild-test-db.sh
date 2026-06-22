#!/usr/bin/env bash
#
# rebuild-test-db.sh — migrate SolaStock's FIXED reserved test databases.
#
# Finance/Projects-style model: the reserved DBs are pre-provisioned (empty
# schemas created once by an admin). This script ONLY runs migrations into them.
# It NEVER creates or drops the databases themselves, and it refuses any name
# outside SolaStock's three reserved databases.
#
#   tenant_990010 = SolaStock tenant A   (tenant/inventory migrations)
#   tenant_990011 = SolaStock tenant B   (tenant/inventory migrations)
#   tenant_990012 = SolaStock central     (landlord migrations)
#
# Explicitly refuses tenant_990001 (Finance), tenant_990002 (Projects), and any
# other database.
#
# Auth: uses the Finance-compatible 'mysql' MySQL account (auth_socket). Run this
# script as the 'mysql' OS user (same as Finance/Projects test runs).
#
# Usage:
#   sudo -u mysql bash scripts/rebuild-test-db.sh           # migrate
#   sudo -u mysql bash scripts/rebuild-test-db.sh --fresh   # migrate:fresh (reserved DBs only)
set -euo pipefail
cd "$(dirname "$0")/.."

TENANT_A="tenant_990010"
TENANT_B="tenant_990011"
CENTRAL="tenant_990012"
FRESH="${1:-}"

ALLOWED=("$TENANT_A" "$TENANT_B" "$CENTRAL")
FORBIDDEN=("tenant_990001" "tenant_990002")

assert_allowed() {
  local db="$1"
  for f in "${FORBIDDEN[@]}"; do
    if [ "$db" = "$f" ]; then
      echo "REFUSING: '$db' is reserved by another Solavel app (Finance/Projects)." >&2; exit 1
    fi
  done
  for a in "${ALLOWED[@]}"; do
    [ "$db" = "$a" ] && return 0
  done
  echo "REFUSING: '$db' is not a SolaStock reserved test DB (allowed: ${ALLOWED[*]})." >&2; exit 1
}

for db in "${ALLOWED[@]}"; do assert_allowed "$db"; done

FRESH_FLAG=""
[ "$FRESH" = "--fresh" ] && FRESH_FLAG="--fresh"

run_migrate() {
  local conn="$1" db="$2" path="$3"
  assert_allowed "$db"
  echo "==> Migrating ${db} via '${conn}' (${path}) ${FRESH_FLAG}"
  if [ "$conn" = "tenant" ]; then
    TENANT_DB_DATABASE="$db" php artisan migrate ${FRESH_FLAG} \
      --database=tenant --path="$path" --force
  else
    DB_DATABASE="$db" php artisan migrate ${FRESH_FLAG} \
      --database=mysql --path="$path" --force
  fi
}

# Central / landlord schema → tenant_990012
run_migrate mysql "$CENTRAL" database/migrations/landlord

# Inventory tenant schema → tenant_990010 and tenant_990011
run_migrate tenant "$TENANT_A" database/migrations/tenant
run_migrate tenant "$TENANT_B" database/migrations/tenant

echo "==> Done. SolaStock reserved test DBs migrated."
echo "    tenant_990001 (Finance) and tenant_990002 (Projects) untouched."
