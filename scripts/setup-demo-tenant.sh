#!/usr/bin/env bash
#
# setup-demo-tenant.sh — one-command setup for the SolaStock SAFE demo tenant.
#
# Creates (if missing) the demo tenant database, runs the inventory tenant
# migrations against it, seeds realistic demo data through the domain services,
# clears caches, and prints the URL to open.
#
# SAFETY: refuses any database that is not the explicitly allowed demo DB
# (tenant_990010). Never touches Finance (tenant_990001), Projects
# (tenant_990002), or any production database.
#
# Requires a MySQL account that can CREATE DATABASE + migrate. If this shell's
# user cannot reach MySQL, the script prints the exact commands an admin/mysql
# OS user must run instead of failing silently.
#
# Usage:
#   bash scripts/setup-demo-tenant.sh
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

# ── Config (matches config/inventory.php demo tenant) ────────────────────────
DEMO_DB="${INVENTORY_DEMO_DB:-tenant_990010}"
DEMO_ORG="${INVENTORY_DEMO_ORG:-990010}"
ALLOWED_DBS=("tenant_990010" "tenant_990011")
FORBIDDEN_DBS=("tenant_990001" "tenant_990002" "solavel" "solavel_finance" "solavel_inventory")

DB_HOST="${TENANT_DB_HOST:-127.0.0.1}"
DB_PORT="${TENANT_DB_PORT:-3306}"
DB_USER="${TENANT_DB_ADMIN_USER:-${DB_USERNAME:-root}}"
DB_PASS="${TENANT_DB_ADMIN_PASS:-${DB_PASSWORD:-}}"

echo "==> SolaStock demo tenant setup"
echo "    Database : ${DEMO_DB}"
echo "    Org id   : ${DEMO_ORG}"

# ── Guard: refuse unsafe DB names ────────────────────────────────────────────
for bad in "${FORBIDDEN_DBS[@]}"; do
  if [[ "$DEMO_DB" == "$bad" ]]; then
    echo "FATAL: '${DEMO_DB}' is a FORBIDDEN database (Finance/Projects/production). Aborting." >&2
    exit 1
  fi
done
allowed=0
for ok in "${ALLOWED_DBS[@]}"; do
  [[ "$DEMO_DB" == "$ok" ]] && allowed=1
done
if [[ "$allowed" -ne 1 ]]; then
  echo "FATAL: '${DEMO_DB}' is not in the demo allow-list (${ALLOWED_DBS[*]}). Aborting." >&2
  exit 1
fi

# ── Confirm target ──────────────────────────────────────────────────────────
if [[ "${ASSUME_YES:-0}" != "1" ]]; then
  read -r -p "Create + migrate + seed demo data into '${DEMO_DB}'? [y/N] " ans
  [[ "$ans" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
fi

# ── Helper to run a mysql statement, capturing failure ───────────────────────
mysql_exec() {
  local sql="$1"
  if [[ -n "$DB_PASS" ]]; then
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -e "$sql"
  else
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -e "$sql"
  fi
}

print_manual_instructions() {
  cat <<EOF

────────────────────────────────────────────────────────────────────────────
This shell cannot reach MySQL with the configured credentials.
Ask an admin / the 'mysql' OS user to run these commands:

  # 1. Create the safe demo database
  sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${DEMO_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

  # 2. Run the inventory tenant migrations against it
  cd ${APP_DIR}
  TENANT_DB_DATABASE=${DEMO_DB} php artisan migrate \\
      --database=tenant --path=database/migrations/tenant --force

  # 3. Seed realistic demo data (through the domain services)
  INVENTORY_DEMO_ORG=${DEMO_ORG} INVENTORY_DEMO_DB=${DEMO_DB} \\
      php artisan db:seed --class="Database\\\\Seeders\\\\InventoryDemoSeeder" --force

  # 4. Clear caches
  php artisan config:clear && php artisan route:clear

Then open:  /inventory/dashboard  and click "Use SolaStock Demo Tenant".
────────────────────────────────────────────────────────────────────────────
EOF
}

# ── 1. Create the demo database ──────────────────────────────────────────────
echo "==> Creating database '${DEMO_DB}' (if missing)…"
if ! mysql_exec "CREATE DATABASE IF NOT EXISTS \`${DEMO_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/tmp/solastock_demo_mysql.err; then
  echo "WARN: could not create the database from this shell:" >&2
  cat /tmp/solastock_demo_mysql.err >&2
  print_manual_instructions
  exit 2
fi

# ── 2. Migrate the tenant DB ─────────────────────────────────────────────────
echo "==> Migrating tenant schema into '${DEMO_DB}'…"
TENANT_DB_DATABASE="${DEMO_DB}" php artisan migrate \
  --database=tenant --path=database/migrations/tenant --force

# ── 3. Seed demo data ────────────────────────────────────────────────────────
echo "==> Seeding SolaStock demo data…"
INVENTORY_DEMO_ORG="${DEMO_ORG}" INVENTORY_DEMO_DB="${DEMO_DB}" \
  php artisan db:seed --class="Database\\Seeders\\InventoryDemoSeeder" --force

# ── 4. Clear caches ──────────────────────────────────────────────────────────
echo "==> Clearing caches…"
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true

# ── 5. Done ──────────────────────────────────────────────────────────────────
cat <<EOF

✓ SolaStock demo tenant is ready.

  Open:   /inventory/dashboard
  Then:   click "Use SolaStock Demo Tenant" in the top bar.

  Demo DB : ${DEMO_DB}   (org ${DEMO_ORG})   — safe, isolated, NOT Finance/Projects.
EOF
