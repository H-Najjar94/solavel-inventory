# SolaStock — Tenancy & Test Environment

Database-per-tenant, MySQL only (never SQLite), mirroring solavel-finance.

## Disposable test databases (created and removed by the canonical runner)

| DB | Role | Connection | Migrations |
|----|------|-----------|-----------|
| `solastock_test_a` | SolaStock tenant A (default active tenant) | `tenant` | `database/migrations/tenant` |
| `solastock_test_b` | SolaStock tenant B (isolation tests) | `tenant` | `database/migrations/tenant` |
| `solastock_test_central` | SolaStock central / landlord | `mysql` | `database/migrations/landlord` |

Central and tenant are always distinct. The runner rebuilds these three exact
schemas, migrates them, runs the suite, and removes them on success, failure or
interruption. It never accepts a production-style `tenant_NNNNNN` name.

## Safety guard (App\Tenancy\TenancySafetyGuard)

Before any DB action the suite refuses unless: `APP_ENV=testing`; the active
tenant DB is one of the three `solastock_test_*` names; the central DB (if set)
is one of those; central != tenant. It explicitly rejects every real tenant
(`tenant_000002`, `inventory_tenant_*`), and production fragments
(`solavel_finance`, `solavel_inventory`, ...). Reads `config(...)`, not just
`env(...)`, so a stale cached config is caught.

## Running tests

```bash
# Wire test env (no new user; no secrets committed — .env.testing is gitignored)
cp .env.testing.example .env.testing
php artisan key:generate --env=testing

# Canonical command: creates, migrates, tests, and always cleans up.
composer test
```

## Per-tenant derived DB users MUST be OFF in tests

Production `.env` sets `INVENTORY_USE_DERIVED_TENANT_DB_USER=true`, so at runtime
`TenantManager::switchToDatabase()` overrides the tenant connection's username with
a per-tenant derived user. Those derived users exist only for real provisioned
tenants — **not** for disposable test DBs.

If `.env.testing` is missing, the suite falls back to production `.env`, derivation
turns on, and database tests fail authentication.

`.env.testing` therefore MUST set `INVENTORY_USE_DERIVED_TENANT_DB_USER=false` and
use the test account credentials. The canonical runner also exports the safe
database names and disables derived users, so a stale local `.env.testing` cannot
redirect the suite to QA or production.
`.env.testing` is gitignored (it holds the real `mysql` password); recreate it from
`.env.testing.example` and set the derived-user flag to `false`.
