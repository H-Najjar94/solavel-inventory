# SolaStock — Tenancy & Test Environment

Database-per-tenant, MySQL only (never SQLite), mirroring solavel-finance.

## Fixed reserved test databases (pre-provisioned; suite NEVER creates/drops them)

| DB | Role | Connection | Migrations |
|----|------|-----------|-----------|
| `tenant_990001` | **Finance tests — never touched by SolaStock** | — | — |
| `tenant_990010` | SolaStock tenant A (default active tenant) | `tenant` | `database/migrations/tenant` |
| `tenant_990011` | SolaStock tenant B (isolation tests) | `tenant` | `database/migrations/tenant` |
| `tenant_990012` | SolaStock central / landlord | `mysql` | `database/migrations/landlord` |

Central (`mysql` → `tenant_990012`) and tenant (`tenant` → `tenant_990010`/`3`) are
ALWAYS distinct databases. Credentials: the existing Finance-compatible `mysql`
MySQL account (socket auth) — run the test command as the OS user it maps to. No
new MySQL user is required.

## Safety guard (App\Tenancy\TenancySafetyGuard)

Before any DB action the suite refuses unless: `APP_ENV=testing`; the active
tenant DB is one of `tenant_990010/3/4`; the central DB (if set) is one of those;
central != tenant. It explicitly rejects `tenant_990001`, real tenants
(`tenant_000002`, `inventory_tenant_*`), and production fragments
(`solavel_finance`, `solavel_inventory`, ...). Reads `config(...)`, not just
`env(...)`, so a stale cached config is caught.

## One-time setup (run as the `mysql` OS user)

```bash
# Confirm the reserved DBs (create the empty ones if missing — admin step, not the suite)
mysql -e "SHOW DATABASES LIKE 'tenant_99%';"
mysql -e "CREATE DATABASE IF NOT EXISTS tenant_990010 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE DATABASE IF NOT EXISTS tenant_990011 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE DATABASE IF NOT EXISTS tenant_990012 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Wire test env (no new user; no secrets committed — .env.testing is gitignored)
cp .env.testing.example .env.testing
php artisan key:generate --env=testing

# Migrate the reserved DBs (migrate-only; never creates/drops)
bash scripts/rebuild-test-db.sh          # or: bash scripts/rebuild-test-db.sh --fresh
```

## Running tests

```bash
php artisan config:clear
php artisan cache:clear
php artisan test
```

## Per-tenant derived DB users MUST be OFF in tests

Production `.env` sets `INVENTORY_USE_DERIVED_TENANT_DB_USER=true`, so at runtime
`TenantManager::switchToDatabase()` overrides the tenant connection's username with
a per-tenant derived user (e.g. `tenant_990010` → `t_990010`). Those derived users
exist only for real provisioned tenants — **not** for the reserved test DBs.

If `.env.testing` is missing, the suite falls back to production `.env`, derivation
turns on, and every DB-touching test fails with:

```
SQLSTATE[HY000] [1045] Access denied for user 't_990010'@'localhost'
```

`.env.testing` therefore MUST set `INVENTORY_USE_DERIVED_TENANT_DB_USER=false` and
use the shared `mysql` account credentials. This is the single cause of the
historical "90 access-denied errors" — a test-env config gap, not a product bug.
`.env.testing` is gitignored (it holds the real `mysql` password); recreate it from
`.env.testing.example` and set the derived-user flag to `false`.
