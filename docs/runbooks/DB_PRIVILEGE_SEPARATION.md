# Runbook — Separate the runtime DB user from the provisioning DB user

**Status:** PREPARED, NOT APPLIED. Do **not** run any of this against production
without (1) a fresh full backup, (2) a staging dry-run, and (3) a maintenance
window. Nothing here changes production grants or `.env` automatically — it is a
checklist for an operator to execute deliberately.

**Owner:** infra/ops · **App:** SolaStock (`/var/www/html/solavel-inventory`)
**Scope:** MySQL on `127.0.0.1:3306`. Does **not** touch Finance or Projects DBs
beyond granting the same least-privilege app user access to the shared central
DB they all read.

---

## 1. Current risk (why this matters)

Today a single high-privilege MySQL account does **everything**:

| Path | When it runs | Connection(s) | DB user today |
|---|---|---|---|
| Runtime request | every page / API call | `mysql` (central registry) + `tenant` (tenant DML) | `mysql` |
| Provisioning | only when a client activates inventory | `mysql_admin` (`CREATE DATABASE`) + `tenant` (`migrate` DDL) | `mysql` |

Confirmed in config:
- `config/database.php` → `mysql`, `tenant`, `tenant_dynamic` all use `env('DB_USERNAME')`.
- `mysql_admin` connection uses `env('TENANT_DB_ADMIN_USER')`.
- `.env` → `DB_USERNAME=mysql` **and** `TENANT_DB_ADMIN_USER=mysql` — the **same**
  account for runtime and provisioning.
- `app/Services/Tenancy/SecureTenantProvisioner.php` → `ensureDatabase()` issues
  `CREATE DATABASE IF NOT EXISTS` on the `mysql_admin` connection; `provisionInventory()`
  then runs `migrate --database=tenant` (CREATE/ALTER TABLE) on the tenant connection.

**The problem:** the runtime path only ever needs `SELECT/INSERT/UPDATE/DELETE`.
But the account it uses can also `CREATE`/`DROP DATABASE`, `ALTER`, and (if it has
`GRANT OPTION` or is `root`-like) hand out privileges — across **every** tenant
schema. So any runtime-reachable flaw (SQL injection, a deserialization/RCE bug,
a leaked `.env`) escalates straight to:

- read/modify **all** tenants' data (cross-tenant breach), not just one;
- `DROP DATABASE tenant_*` (destructive, irreversible without backups);
- create rogue schemas/users.

Least-privilege fixes this: a runtime compromise is then bounded to DML on data
the app already exposes, and cannot create/drop schemas or reach the admin path.

---

## 2. Target end state

Two distinct MySQL accounts, each used by exactly one path:

| Account | Used by | Privileges |
|---|---|---|
| `solavel_app` | runtime (`mysql` + `tenant` connections) | `SELECT, INSERT, UPDATE, DELETE` on `solavel.*` and `tenant\_%.*` only |
| `solavel_provisioner` | provisioning (`mysql_admin` + migrations) | `CREATE` on `tenant\_%.*` (lets it create those schemas) + full DDL/DML on `tenant\_%.*` to run migrations/seeders. **No** `GRANT OPTION`, **no** global `DROP`. |

Host scope: bind both to `127.0.0.1` (app and DB are on the same host — see
`DB_HOST=127.0.0.1`). Do **not** grant from `%` unless a remote app tier exists.

> Note the `\_` escaping: in a GRANT pattern `_` is a wildcard, so `tenant\_%`
> means "schemas literally starting with `tenant_`". Unescaped `tenant_%` also
> matches e.g. `tenantXfoo`.

---

## 3. Exact SQL — create the two users and grant

Run as a MySQL administrator (e.g. `root`). Replace the two passwords with fresh,
strong, **distinct** secrets from your secret manager — do **not** reuse the
current shared password, and never commit them.

```sql
-- === 3a. Runtime app user — DML only ======================================
CREATE USER IF NOT EXISTS 'solavel_app'@'127.0.0.1'
  IDENTIFIED BY '<APP_PASSWORD>';

-- Central registry / shared central DB (tenant lookup, org membership, etc.)
GRANT SELECT, INSERT, UPDATE, DELETE ON `solavel`.* TO 'solavel_app'@'127.0.0.1';

-- Every per-client tenant schema (DML only — no CREATE/ALTER/DROP)
GRANT SELECT, INSERT, UPDATE, DELETE ON `tenant\_%`.* TO 'solavel_app'@'127.0.0.1';

-- === 3b. Provisioning user — schema creation + migrations =================
CREATE USER IF NOT EXISTS 'solavel_provisioner'@'127.0.0.1'
  IDENTIFIED BY '<PROVISIONER_PASSWORD>';

-- CREATE on the tenant pattern lets it run CREATE DATABASE `tenant_<id>`.
-- The remaining privileges let `php artisan migrate` build/alter tables and
-- run any seeders inside those schemas. TRIGGER is REQUIRED: the stock-engine
-- migration creates the stock_ledger append-only triggers
-- (stock_ledger_no_update / stock_ledger_no_delete) on first provision; without
-- it, new-tenant provisioning fails at that migration.
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER,
      SELECT, INSERT, UPDATE, DELETE
  ON `tenant\_%`.* TO 'solavel_provisioner'@'127.0.0.1';

-- It must read information_schema to check "does this DB already exist?"
-- (information_schema is world-readable for owned objects; no extra grant
--  is normally required — verify in step 7).

FLUSH PRIVILEGES;
```

What is deliberately **NOT** granted:
- No `GRANT OPTION` to either account (cannot escalate privileges).
- No global (`*.*`) privileges to either account.
- No `DROP` to the runtime user (cannot drop tables/schemas).
- No access to the `mysql`/system schema for either account.

---

## 4. Required code/`.env` wiring

The runtime path and the provisioning path must use **different** credentials.

### 4a. `.env` (production) — change three values, add three

```dotenv
# Runtime (used by `mysql` and `tenant` connections)
DB_USERNAME=solavel_app
DB_PASSWORD=<APP_PASSWORD>

# Provisioning admin connection (mysql_admin)
TENANT_DB_ADMIN_USER=solavel_provisioner
TENANT_DB_ADMIN_PASS=<PROVISIONER_PASSWORD>
```

### 4b. Code prerequisite — IMPLEMENTED ✅ (step 1, no `.env` change)

> **Status: DONE.** This was the blocking prerequisite. It is now implemented and
> tested, so flipping the credentials in §4a is safe from a code standpoint.
> Nothing in `.env` or any DB grant was changed by this step.

The problem: tenant migrations pin their connection via
`getConnection() => config('tenancy.tenant_connection')`, so they always ran on
the runtime `tenant` connection. After separation that connection is the
**low-privilege** `solavel_app` user, which cannot `CREATE TABLE` → migrations
would fail.

What shipped:
- **New `tenant_admin` connection** (`config/database.php`) — runtime-selected
  database, credentials default to `TENANT_DB_ADMIN_USER/PASS` (the elevated
  admin account already in use). Today it resolves to the **same** creds as the
  runtime user, so there is **no behaviour or privilege change** yet — it is only
  a clean seam.
- **New config key** `tenancy.provision_connection` (env
  `TENANT_PROVISION_CONNECTION`, default `tenant_admin`).
- **`SecureTenantProvisioner::provisionInventory()`** now runs the migrate step
  through the provisioning connection. Because the migration files pin
  `getConnection()` to `tenancy.tenant_connection`, the provisioner **transiently
  retargets** that config value to the elevated connection for the duration of
  the migrate and restores it in a `finally` block — so DDL runs as the
  provisioner while the **runtime `tenant` connection is never reconfigured**.
  The method now also returns the `connection` it migrated through.

Proof (tests + standalone run):
- `tests/Feature/Tenancy/ProvisioningElevatedConnectionTest.php` — 3 tests:
  the connection is configured and distinct from runtime; provisioning creates +
  migrates a scratch DB **via `tenant_admin`** without disturbing the runtime
  connection; provisioning is idempotent through the elevated connection.
- Standalone: `provisionInventory(.., 'tenant_prov_proof')` →
  `{created:true, migrated:true, connection:"tenant_admin"}`, 52 tables incl.
  `stock_ledger` + `cost_layer_consumptions`; runtime `tenant` connection
  unchanged; `tenancy.tenant_connection` restored to `tenant`; scratch DB dropped.
- Full suite green (120/120) twice consecutively; no scratch-DB residue; `.env`
  untouched.

When §4a flips `TENANT_DB_ADMIN_USER/PASS` to `solavel_provisioner`, migrations
automatically run as that user (via `tenant_admin`), and the runtime `tenant`
connection can safely become `solavel_app` (DML-only). **Still do not flip
`DB_USERNAME` until §3/§4a are executed in a window** — but the code no longer
blocks it.

---

## 5. Rollout order (staging first)

1. Take a full MySQL backup (`mysqldump --all-databases` or snapshot).
2. On **staging**: run §3, apply §4a + §4b, `php artisan config:clear`, then:
   - exercise a normal request (read + write a stock movement) → must work as `solavel_app`;
   - provision a throwaway client (activate inventory) → `CREATE DATABASE` + migrate must succeed as `solavel_provisioner`;
   - run the verification in §7.
3. Only after staging is green, schedule a production window and repeat 1–2 on prod.
4. After 24–48h stable, **rotate/disable** the old shared `mysql` app password so it
   can no longer be used by the app tier.

---

## 6. Rollback

Fully reversible — the old account is untouched until step 5.4.

1. Restore `.env`:
   ```dotenv
   DB_USERNAME=mysql
   DB_PASSWORD=<ORIGINAL_PASSWORD>
   TENANT_DB_ADMIN_USER=mysql
   TENANT_DB_ADMIN_PASS=<ORIGINAL_PASSWORD>
   ```
   then `php artisan config:clear`.
2. Revert the §4b code change (or just keep using `DB_USERNAME=mysql` which has DDL).
3. (Optional) Drop the new users once confirmed unused:
   ```sql
   DROP USER IF EXISTS 'solavel_app'@'127.0.0.1';
   DROP USER IF EXISTS 'solavel_provisioner'@'127.0.0.1';
   FLUSH PRIVILEGES;
   ```
No schema or data changes are made by this runbook, so rollback cannot lose data.

---

## 7. Verification commands

### 7a. Inspect the new grants
```sql
SHOW GRANTS FOR 'solavel_app'@'127.0.0.1';
SHOW GRANTS FOR 'solavel_provisioner'@'127.0.0.1';
```
Expect `solavel_app` to show only `SELECT, INSERT, UPDATE, DELETE` on `solavel.*`
and `` `tenant\_%`.* `` — and **no** `CREATE`, `DROP`, `GRANT OPTION`, or `*.*`.

### 7b. Prove the runtime user CANNOT escalate (these must FAIL)
```sql
-- as solavel_app:
CREATE DATABASE tenant_999999;             -- expect: ERROR 1044 access denied
DROP DATABASE tenant_990010;               -- expect: ERROR 1044 access denied
CREATE TABLE solavel.evil (id INT);        -- expect: ERROR 1142 CREATE denied
GRANT ALL ON *.* TO 'solavel_app'@'127.0.0.1'; -- expect: ERROR 1045/1044 denied
```

### 7c. Prove the runtime user CAN do its job (these must SUCCEED)
```sql
-- as solavel_app:
SELECT COUNT(*) FROM tenant_990010.items;  -- ok
INSERT INTO solavel.<some_log_table> ...;  -- ok (a real app write)
```

### 7d. Prove the provisioner CAN create + migrate (these must SUCCEED)
```sql
-- as solavel_provisioner:
CREATE DATABASE IF NOT EXISTS tenant_test_prov
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;   -- ok
CREATE TABLE tenant_test_prov.t (id INT);             -- ok
DROP DATABASE tenant_test_prov;                       -- ok (cleanup)
```

### 7e. End-to-end through the app (staging)
```bash
php artisan config:clear
# normal request path uses solavel_app:
php artisan tinker --execute='dd(\App\Models\Tenant\Item::on("tenant")->getConnection()->getConfig("username"));'
# provisioning path uses solavel_provisioner — activate a throwaway client and confirm
# CREATE DATABASE + migrate succeed and the marker row is written.
```

---

## 8. Summary

- **Before:** one `mysql` superuser for runtime + provisioning → a runtime
  compromise = full multi-tenant breach + ability to drop databases.
- **After:** `solavel_app` (DML-only, every request) and `solavel_provisioner`
  (schema create + migrate, activation only). Runtime compromise is bounded to
  DML on already-exposed data; it cannot create/drop schemas or grant privileges.
- **Blocking dependency:** ship the §4b migrate-under-provisioner code change
  before flipping `DB_USERNAME`, or provisioning breaks.
- **Reversible:** no data/schema changes; rollback is an `.env` swap.
