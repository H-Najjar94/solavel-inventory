# Runbook — Enable SolaStock Derived Tenant DB User

> **GATED — DO NOT EXECUTE WITHOUT APPROVAL.**
> This runbook is documentation only. Do not run any step until the owner
> approves a specific enable window. It does not change `.env`, MySQL users,
> grants, passwords, tenant data, or production config by itself.

## Purpose

Flip SolaStock's runtime DB identity from the shared `mysql` superuser to the
deterministic per-tenant user `t_XXXXXX` (already provisioned by
Books/Projects/HR on the shared `tenant_XXXXXX` databases). This requires **no
MySQL user or grant change** — only adding the shared derive secret and flipping
a default-OFF flag.

Code is already merged (default OFF): config flag
`tenancy.use_derived_db_user` (`INVENTORY_USE_DERIVED_TENANT_DB_USER`), deriver
`App\Services\Tenancy\TenantCredentialDeriver`, and the gated branch in
`App\Services\Tenancy\TenantManager::switchToDatabase()`.

## Preconditions

- A specific low-traffic enable window is approved by the owner.
- You hold the **exact** `TENANT_DERIVE_SECRET` value used by
  SolaBooks/Projects/HR, retrieved from the **secret manager** — never from a
  repo, never pasted into chat, logs, or this file.
- A second operator watches logs; rollback is staged and understood.
- The target tenants you will smoke-test are active and provisioned.

## Steps

### 1. Add the derive secret to SolaStock (must byte-match the other apps)
Add to `/var/www/html/solavel-inventory/.env`:

```
TENANT_DERIVE_SECRET=<value from secret manager — IDENTICAL to Books/Projects/HR>
```

It **must** byte-match the value the other apps use, or the derived passwords
will not match the existing `t_XXXXXX` MySQL users and every tenant request will
fail closed. Confirm a match without printing the value:

```
diff <(grep '^TENANT_DERIVE_SECRET=' /var/www/html/solavel-finance/.env | sha256sum) \
     <(grep '^TENANT_DERIVE_SECRET=' /var/www/html/solavel-inventory/.env | sha256sum) \
  && echo "secrets MATCH" || echo "MISMATCH — STOP"
```

Must print `secrets MATCH`. If not, **STOP** and fix before continuing.

### 2. Flip the flag
In the same `.env`:

```
INVENTORY_USE_DERIVED_TENANT_DB_USER=true
```

### 3. Clear config cache (only if cached)
```
cd /var/www/html/solavel-inventory && php artisan config:clear
```
If your deploy caches config, run `php artisan config:cache` afterward. If config
is not cached, `.env` is read live and no cache step is needed.

## Verification

### V1 — Runtime identity is the per-tenant user (NOT `mysql`)
For a real, active Stock tenant, confirm the connection user is `t_XXXXXX`:

```
php artisan tinker --execute="config(['database.connections.tenant.database'=>'tenant_000008']); app(App\Services\Tenancy\TenantManager::class)->switchToDatabase('tenant_000008'); echo DB::connection('tenant')->select('SELECT CURRENT_USER() u')[0]->u;"
```

**Expected:** `t_000008@...` — **not** `mysql@...`.

### V2 — Normal Stock pages still work
Smoke a few authenticated Stock pages for a live tenant: dashboard, stock ledger,
a report, and one write (e.g. a stock adjustment). All should load/post normally
(the `t_XXXXXX` user holds `ALL` on its own tenant DB).

### V3 — No credential-resolution failures in normal use
Watch for ~10–15 min of normal traffic:

```
grep -c tenant_credential_resolution_failure /var/www/html/solavel-inventory/storage/logs/laravel*.log
```

**Expected: 0.** Any occurrence means a tenant could not derive/connect (usually a
tenant whose `t_XXXXXX` user was never provisioned). Those requests are failing
closed (503), not leaking — investigate before proceeding.

## Rollback (fast, no DB change)

Set the flag back to false and clear config cache:

```
# in /var/www/html/solavel-inventory/.env
INVENTORY_USE_DERIVED_TENANT_DB_USER=false
php artisan config:clear   # (+ config:cache if your deploy caches config)
```

This instantly returns to today's behavior (shared runtime user, database-only
switch). Optionally also remove the `TENANT_DERIVE_SECRET` line. There is **no**
MySQL user/grant/data change to undo.

## Explicit non-actions (this runbook NEVER does these)

- No `CREATE USER` / `ALTER USER` / `DROP USER`.
- No `GRANT` / `REVOKE`.
- No `CREATE DATABASE` / schema or tenant-data change.
- No Phase B (DML-only reduction of `t_XXXXXX` — separate, platform-wide, gated).
- No printing/logging/committing of any secret or password value.
- No execution at all until the owner approves the specific window.
