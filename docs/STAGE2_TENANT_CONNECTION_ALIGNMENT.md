# SolaStock — Tenant Connection Model & Alignment (Option A)

**Status:** Option A implemented behind a **DEFAULT-OFF** feature flag. No
production behaviour change; no `.env`, MySQL users, grants, or tenant data
changed. Enabling in production is a separate, approved step.

## Database model (audit-verified)

- SolaStock **shares the SAME per-client tenant databases** as Books/Projects/HR:
  `tenant_{clientId}` (e.g. `tenant_000008`). Verified: `stock_ledger`/`items`
  live in the same `tenant_XXXXXX` databases that also hold `journal_entries`,
  `accounts`, `invoices`, `tenant_system`. There are **no** separate
  `inventory_tenant_*` databases — earlier wording to that effect was **stale and
  has been removed** from `TenantManager`.
- Stock owns only its own `stock_*`/inventory tables there (migration marker
  `migrated_at_inv`); it never reads or writes Finance/Projects tables.
- Access control is fail-closed at the access layer: `LiveTenantResolver` +
  `ResolveInventoryTenant` validate org→client ownership (vs Central) +
  inventory permission + app-enablement, returning **409** before connecting.

## Runtime DB identity

- **Today / production (flag OFF):** `TenantManager::switchToDatabase()` sets only
  the *database* on the `tenant` connection; username/password stay the shared
  `DB_USERNAME` — i.e. the request runs as the shared **`mysql`** superuser
  (verified: `CURRENT_USER() = mysql@localhost` on a real tenant request).
- **Target (flag ON):** the runtime connection uses the deterministic per-tenant
  user **`t_XXXXXX`** derived from the selected tenant **database name**
  (`tenant_000008` → `t_000008`) — the **same users Books/Projects/HR already
  provisioned**, which already hold `GRANT ALL ON tenant_XXXXXX.*` (covering the
  `stock_*` tables). On a derive/secret failure the request **fails closed (503)**
  with a `[SECURITY] tenant_credential_resolution_failure` alert and **never**
  falls back to the shared user.

## The flag

```
INVENTORY_USE_DERIVED_TENANT_DB_USER=false   # default; today's behaviour
```
- `false` → database-only switch; shared runtime user; no behaviour change.
- `true`  → derive + set `t_XXXXXX` username/password; fail closed on miss.

Backed by `config('tenancy.use_derived_db_user')`. Deriver:
`App\Services\Tenancy\TenantCredentialDeriver` (ported verbatim from finance, so
the derived credentials match the existing `t_XXXXXX` users). The derive secret
is read only from `config('tenancy.derive_secret')` (`TENANT_DERIVE_SECRET`);
never hardcoded, logged, printed, or committed.

## No DB user/grant change required for this alignment

The `t_XXXXXX` users **already exist** and already have access to the shared
`tenant_XXXXXX` database (created when Books/Projects/HR provisioned the tenant).
Option A therefore needs **no `CREATE USER` and no `GRANT`** — only code (done,
behind the flag) plus, to enable, the shared derive secret in `.env`.

## Enabling in production (gated — NOT done here)

1. Add `TENANT_DERIVE_SECRET` to SolaStock `.env`, **equal to** the value
   Books/Projects/HR use (a secret addition — not a user/grant change).
2. Set `INVENTORY_USE_DERIVED_TENANT_DB_USER=true` in a low-traffic window;
   `php artisan config:clear`.
3. Verify `CURRENT_USER()` on a Stock tenant request is `t_XXXXXX` (not `mysql`).
4. Rollback: set the flag back to `false` (or revert the commit) → instantly back
   to today's behaviour.

Provisioning/DDL (`CREATE DATABASE`, migrations) already routes through the
elevated `mysql_admin` / `tenant_admin` seam, so flipping the runtime user does
not affect new-tenant activation.

## Residual (tracked under `pic_issues #1`)

Until the flag is enabled, Stock runs as the shared `mysql` superuser (a SQLi/RCE
could reach any tenant DB). Reducing the per-tenant users to **DML-only** remains
a later, platform-wide **Phase B** step (gated).
