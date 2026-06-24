# Step 3 — privilege-separation execution checklist (FOR APPROVAL)

> ✅ **RESOLVED — Model A (3-user) standard, Phase A code landed (2026-06-24).**
> The earlier blocker (Books/HR/Projects create per-tenant DB users, which the
> DDL-only provisioner can't do) is fixed by the **3-user Model A**: runtime
> (DML), provisioner (DDL, no user mgmt), and a dedicated **bootstrap** user for
> `CREATE/DROP USER` + `GRANT/REVOKE`. Phase A code is shipped on all four apps
> (bootstrap connection + explicit grants + audit; behaviour-neutral). This
> checklist is now the **platform-wide** Phase-B production flip. See
> `solavel/docs/DB_PRIVILEGE_SEPARATION_MODEL_A_PLAN.md`. **Still do NOT run any
> window until the owner explicitly approves it.**

**Status: NOT EXECUTED. Prepared for review.** This is the first step that
changes production DB users and `.env`. Do not run any command here until the
maintenance window is scheduled and this checklist is approved.

Prereqs already done & accepted: Step 1 (elevated `tenant_admin` seam) and Step 2
(end-to-end activation through it, suite 120/120). The code no longer blocks the
credential flip.

Host facts (verified): app connects over **TCP `127.0.0.1`** (`DB_HOST=127.0.0.1`,
no socket) → users bound to `@'127.0.0.1'` will match. Central DB = `solavel`.
Tenant DBs = `tenant_<6-digit clientId>`. Today both runtime and provisioning use
the single `mysql` account.

---

## 1. Backup / snapshot (do first, no exceptions)

```bash
# Full logical backup (all schemas incl. every tenant_*) + the grant tables.
mysqldump --single-transaction --routines --triggers --events \
  --all-databases > /var/backups/solavel_preseparation_$(date +%F_%H%M).sql

# Capture current grants for the existing account (for reference/rollback):
mysql -e "SHOW GRANTS FOR CURRENT_USER();" > /var/backups/grants_before_$(date +%F_%H%M).txt
```
Expected: a multi-GB `.sql` file written, and a grants file. Verify the dump
ends with `-- Dump completed`.

> Also snapshot the VM/disk if that's available — faster restore than mysqldump.

---

## 2. Exact SQL — runtime user `solavel_app` (DML only)

Run as a MySQL admin (e.g. `root`). Use a fresh, strong password from the secret
manager — NOT the current shared password; never commit it.

```sql
CREATE USER IF NOT EXISTS 'solavel_app'@'127.0.0.1'
  IDENTIFIED BY '<APP_PASSWORD>';

-- Central registry / shared central DB.
GRANT SELECT, INSERT, UPDATE, DELETE ON `solavel`.* TO 'solavel_app'@'127.0.0.1';

-- Every per-client tenant schema (DML only — no CREATE/ALTER/DROP/TRIGGER).
GRANT SELECT, INSERT, UPDATE, DELETE ON `tenant\_%`.* TO 'solavel_app'@'127.0.0.1';

FLUSH PRIVILEGES;
```
`\_` is escaped on purpose: in a GRANT pattern `_` is a single-char wildcard, so
`tenant\_%` means schemas literally starting with `tenant_`.

---

## 3. Exact SQL — provisioning user `solavel_provisioner` (DDL + migrate)

```sql
CREATE USER IF NOT EXISTS 'solavel_provisioner'@'127.0.0.1'
  IDENTIFIED BY '<PROVISIONER_PASSWORD>';

-- CREATE on the pattern allows CREATE DATABASE `tenant_<id>`. TRIGGER is REQUIRED
-- (the stock-engine migration creates the stock_ledger append-only triggers on
-- first provision). REFERENCES is required (59 FKs across tenant migrations).
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER,
      SELECT, INSERT, UPDATE, DELETE
  ON `tenant\_%`.* TO 'solavel_provisioner'@'127.0.0.1';

FLUSH PRIVILEGES;
```

Deliberately NOT granted to `solavel_app`/`solavel_provisioner`: `CREATE USER`,
`DROP USER`, `GRANT OPTION`, any `*.*`, the `mysql` system schema. `solavel_app`
gets no `CREATE/DROP/ALTER/TRIGGER`; `solavel_provisioner` gets no user mgmt.

---

## 3.5 Exact SQL — bootstrap user `solavel_bootstrap` (user lifecycle ONLY)

Required by SolaBooks/SolaHR/SolaProjects, which create a **per-tenant DB user**
at client activation. Used ONLY for `CREATE/DROP USER` + `GRANT/REVOKE`; never by
runtime or migrations. SolaStock does not use it (no per-tenant user). Credentials
in the secret manager; every use is audited (`tenant.bootstrap` log channel).

```sql
CREATE USER IF NOT EXISTS 'solavel_bootstrap'@'127.0.0.1'
  IDENTIFIED BY '<BOOTSTRAP_PASSWORD>';

-- May create/drop the per-tenant users:
GRANT CREATE USER ON *.* TO 'solavel_bootstrap'@'127.0.0.1';

-- May grant/revoke the explicit tenant set to those users (GRANT OPTION confined
-- to this one account, scoped to tenant_% only — never *.*):
GRANT SELECT, INSERT, UPDATE, DELETE
  ON `tenant\_%`.* TO 'solavel_bootstrap'@'127.0.0.1' WITH GRANT OPTION;

FLUSH PRIVILEGES;
```

### Per-tenant derived user (Books/HR/Projects) — granted BY the bootstrap user

Phase B target = **DML only** (the provisioner runs DDL). The app emits this
automatically when `TENANT_USER_DML_ONLY=true`:
```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON `tenant_<id>`.* TO '<derived_user>'@'<host>';
```
**No `GRANT ALL` anywhere.** During the window, re-grant existing per-tenant users
down to this set (REVOKE the DDL subset) — additive/reversible, no schema change.

---

## 3.6 Per-app `.env` for Phase B (in addition to §4)

Same three users platform-wide; per-app `.env` keys:

| App | DB_USERNAME | TENANT_DB_ADMIN_USER | TENANT_DB_BOOTSTRAP_USER | extra |
|---|---|---|---|---|
| SolaStock | solavel_app | solavel_provisioner | solavel_bootstrap (unused) | — |
| SolaProjects | solavel_app | solavel_provisioner | solavel_bootstrap | `TENANT_PROVISION_CONNECTION=tenant_admin` already default |
| SolaBooks | solavel_app | solavel_provisioner | solavel_bootstrap | `TENANT_PROVISION_CONNECTION=tenant_admin`, `TENANT_USER_DML_ONLY=true` |
| SolaHR | solavel_app | solavel_provisioner | solavel_bootstrap | `TENANT_PROVISION_CONNECTION=tenant_admin`, `TENANT_USER_DML_ONLY=true` |

Books/HR default `provision_connection` to `tenant_dynamic` (Phase A), so their
flip MUST set `TENANT_PROVISION_CONNECTION=tenant_admin` (provisioner runs DDL)
and `TENANT_USER_DML_ONLY=true` (per-tenant user becomes DML-only).

---

## 4. Exact `.env` changes (production)

Change four values. Keep a copy of the originals (rollback §7). Currently both
are the shared `mysql` account.

```dotenv
# Runtime (used by the `mysql` and `tenant` connections)
DB_USERNAME=solavel_app
DB_PASSWORD=<APP_PASSWORD>

# Provisioning admin connection (mysql_admin + tenant_admin)
TENANT_DB_ADMIN_USER=solavel_provisioner
TENANT_DB_ADMIN_PASS=<PROVISIONER_PASSWORD>
```

No other `.env` keys change. `tenant_admin` and `mysql_admin` both read
`TENANT_DB_ADMIN_USER/PASS`, so provisioning automatically uses the provisioner.
The runtime `tenant`/`mysql` connections read `DB_USERNAME/PASSWORD`, so requests
use the DML-only app user.

---

## 5. Cache clear / reload

```bash
cd /var/www/html/solavel-inventory
php artisan config:clear        # no config cache is used here, but be explicit
php artisan optimize:clear      # drop compiled bootstrap files
# opcache auto-revalidates (validate_timestamps=On, freq 2s); no Apache restart
# needed. If you want it immediate, reload php-fpm/apache via your normal method.
```
Expected: `INFO Configuration cache cleared` / `cache … DONE`.

---

## 6. Verification (each must pass before declaring success)

### 6a. Runtime user CAN read/write inventory data — must SUCCEED
```bash
# Connects as solavel_app via the app's own runtime path against a live tenant.
php artisan tinker --execute='
app(\App\Services\Tenancy\TenantManager::class)->useTenant(2,"tenant_000002");
app(\App\Tenancy\OrganizationContext::class)->set(2);
echo "conn user=".DB::connection("tenant")->getConfig("username")."\n";
echo "read items: ".\App\Models\Tenant\Item::query()->count()." rows\n";
$n="PRIVTEST-".substr(md5(uniqid()),0,6);
$id=DB::connection("tenant")->table("items")->insertGetId(["organization_id"=>2,"sku"=>$n,"name"=>$n,"item_type"=>"inventory","tracking_type"=>"none","created_at"=>now(),"updated_at"=>now()]);
echo "write ok id=$id; cleanup=".DB::connection("tenant")->table("items")->where("id",$id)->delete()."\n";'
```
Expected: `conn user=solavel_app`, a row count, `write ok`, cleanup `1`.

### 6b. Runtime user CANNOT create/drop databases — must be DENIED
```bash
php artisan tinker --execute='
try{ DB::connection("tenant")->statement("CREATE DATABASE tenant_999998"); echo "BAD: create allowed\n"; }
catch(\Throwable $e){ echo "OK create denied: ".substr($e->getMessage(),0,40)."\n"; }
try{ DB::connection("tenant")->statement("DROP DATABASE tenant_000002"); echo "BAD: drop allowed\n"; }
catch(\Throwable $e){ echo "OK drop denied: ".substr($e->getMessage(),0,40)."\n"; }
try{ DB::connection("tenant")->statement("CREATE TABLE tenant_000002.evil (id int)"); echo "BAD: create table allowed\n"; }
catch(\Throwable $e){ echo "OK create-table denied\n"; }'
```
Expected: three `OK ... denied` lines (access-denied 1044/1142), no `BAD`.

### 6c. Provisioner CAN create + migrate a tenant DB — must SUCCEED
```bash
php artisan tinker --execute='
$db="tenant_970003";
DB::connection("mysql_admin")->statement("DROP DATABASE IF EXISTS `$db`");  # as provisioner
$r=app(\App\Services\Tenancy\SecureTenantProvisioner::class)->provisionInventory(970003,$db);
echo "result=".json_encode($r)."\n";
echo "admin user=".DB::connection("mysql_admin")->getConfig("username")."\n";
$t=collect(DB::connection("mysql_admin")->select("SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA=?",[$db]))->pluck("n");
echo "tables=".$t->count()." trigger_ok=".(in_array("stock_ledger",$t->all())?"yes":"no")."\n";
DB::connection("mysql_admin")->statement("DROP DATABASE IF EXISTS `$db`");
echo "scratch dropped\n";'
```
Expected: `result={...,"connection":"tenant_admin"}`, `admin user=solavel_provisioner`,
tables created incl. `stock_ledger` (proves TRIGGER priv worked), scratch dropped.

### 6d. New-client activation still works end-to-end — must SUCCEED
Re-run the Step-2 style proof under the split users (provision via provisioner +
runtime read/write via app user) on a scratch tenant, then drop it. (Use the same
flow proven in Step 2; it must report `STEP2 RESULT: PASS` with
`connection=tenant_admin` and a runtime write/read of on_hand.)

### 6e. Suite stays green — must SUCCEED
```bash
vendor/bin/phpunit
```
Expected: `120 passed`.

> **Test-environment policy (decided):** do NOT force the normal/full suite to run
> under the production DML-only `solavel_app` user — the provisioning tests
> create/drop scratch DBs and need create/drop rights. The test environment keeps
> its current credentials (or, optionally and separately, a dedicated split-user
> *test* mode: a DML-only test runtime user + a create/drop/migrate test
> provisioner user). The production privilege-separation window is **not** blocked
> on converting every test run to split users — provided §6f below explicitly
> proves the production-style split users work. So: run §6e with the existing
> test creds for regression coverage, and rely on §6f for the split-user proof.

### 6f. Targeted verification under the ACTUAL split production-style users — GATE

This is the authoritative proof that the split users themselves work end-to-end.
It does NOT use the test suite — it exercises the real services while the app is
configured with `solavel_app` (runtime) and `solavel_provisioner` (provisioning),
i.e. after §4/§5 have been applied. Run it as one script:

```bash
php artisan tinker --execute='
$fail=0; $ok=function($c,$m)use(&$fail){echo ($c?"  PASS ":"  FAIL ").$m."\n"; if(!$c)$fail++;};

// Confirm we are actually running as the split users.
$ok(DB::connection("tenant")->getConfig("username")==="solavel_app", "runtime conn user = solavel_app");
$ok(DB::connection("mysql_admin")->getConfig("username")==="solavel_provisioner", "admin conn user = solavel_provisioner");

// (i) runtime user CANNOT create / drop databases — must be DENIED.
try{ DB::connection("tenant")->statement("CREATE DATABASE tenant_999997"); $ok(false,"runtime CREATE DATABASE denied"); }
catch(\Throwable $e){ $ok(true,"runtime CREATE DATABASE denied"); }
try{ DB::connection("tenant")->statement("DROP DATABASE tenant_000002"); $ok(false,"runtime DROP DATABASE denied"); }
catch(\Throwable $e){ $ok(true,"runtime DROP DATABASE denied"); }

// (ii) provisioner CAN create + migrate a scratch tenant — must SUCCEED.
$db="tenant_970004";
DB::connection("mysql_admin")->statement("DROP DATABASE IF EXISTS `$db`");
$r=app(\App\Services\Tenancy\SecureTenantProvisioner::class)->provisionInventory(970004,$db);
$tbls=collect(DB::connection("mysql_admin")->select("SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA=?",[$db]))->pluck("n");
$ok($r["connection"]==="tenant_admin" && $r["created"]===true, "provisioner created+migrated $db via tenant_admin");
$ok($tbls->contains("stock_ledger")&&$tbls->contains("cost_layer_consumptions")&&$tbls->contains("stock_balances"), "required tables present (triggers ok)");

// (iii) app can read/write inventory through the RUNTIME user after provisioning.
app(\App\Services\Tenancy\TenantManager::class)->useTenant(970004,$db);
app(\App\Tenancy\OrganizationContext::class)->set(970004);
\App\Models\Tenant\InventorySetting::query()->updateOrCreate(["organization_id"=>970004],["default_costing_method"=>"fifo","allow_negative_stock"=>false]);
$it=\App\Models\Tenant\Item::create(["sku"=>"SPLIT-IT","name"=>"split","item_type"=>"inventory","tracking_type"=>"none","default_costing_method"=>"fifo"]);
$wh=\App\Models\Tenant\Warehouse::create(["organization_id"=>970004,"code"=>"SPLIT-WH","name"=>"split"]);
$os=app(\App\Services\Documents\OpeningStockService::class);
$os->post($os->createDraft(["entry_number"=>"SPLIT-OS","warehouse_id"=>$wh->id],[["item_id"=>$it->id,"quantity"=>"4.0000","unit_cost"=>"2.0000"]]));
$onhand=\App\Models\Tenant\StockBalance::query()->where("item_id",$it->id)->value("on_hand_qty");
$ok($onhand==="4.0000", "runtime user wrote+read inventory (on_hand=".var_export($onhand,true).")");

// cleanup scratch tenant (provisioner drops it).
app(\App\Tenancy\OrganizationContext::class)->forget();
DB::connection("mysql_admin")->statement("DROP DATABASE IF EXISTS `$db`");
echo $fail? "\n6f RESULT: FAIL ($fail)\n" : "\n6f RESULT: PASS\n";'
```
Expected: every line `PASS` and `6f RESULT: PASS`, proving under the real split
users: runtime cannot create/drop DBs, provisioner can create/migrate a scratch
tenant (incl. triggers), and the runtime user can read/write inventory after
provisioning. **If 6f fails → roll back (§7) immediately.**

### 6g. Model A 3-user gates (Books/HR/Projects — apps with per-tenant users) — GATE

The five owner-required proofs for the bootstrap model. Run per app under the
real split users:

1. **Runtime cannot `CREATE`/`ALTER`/`DROP`** — runtime connection attempts DDL on
   a tenant DB → access denied (same as 6b, asserted per app).
2. **Provisioner can migrate + triggers but cannot `CREATE USER`/`GRANT`** — as
   `solavel_provisioner`, migrate a scratch tenant (succeeds, incl. triggers); then
   `CREATE USER 't'@'127.0.0.1'` and `GRANT … WITH GRANT OPTION` → BOTH denied.
3. **Bootstrap can create/grant/revoke/drop a tenant user but is NOT used by
   runtime/migrate** — as `solavel_bootstrap`: `CREATE USER` + `GRANT <explicit set>`
   + `REVOKE` + `DROP USER` on a scratch tenant user (all succeed); grep app logs to
   confirm migrate/runtime never resolved to the bootstrap connection.
4. **No `GRANT ALL`** — after activating a scratch client, `SHOW GRANTS` for its
   per-tenant user shows exactly the explicit DML set (Phase B), never `ALL
   PRIVILEGES`.
5. **Books/HR/Projects new-client activation works under the final model** — full
   activation of a scratch client: bootstrap creates the DML-only per-tenant user,
   provisioner creates+migrates the DB (via `tenant_admin`), runtime reads/writes;
   then deactivation drops the user. End-to-end PASS, then clean up the scratch
   tenant.

**If any 6g check fails → roll back (§7) immediately** (revert `.env`, restore the
DDL grant to the per-tenant user).

---

## 7. Rollback (fast, no data loss)

The grants are additive and the app only switches users via `.env`, so rollback
is a config revert.

1. Restore `.env` to the originals:
   ```dotenv
   DB_USERNAME=mysql
   DB_PASSWORD=<ORIGINAL_PASSWORD>
   TENANT_DB_ADMIN_USER=mysql
   TENANT_DB_ADMIN_PASS=<ORIGINAL_PASSWORD>
   ```
2. `php artisan optimize:clear` (and reload php-fpm/apache if you forced it).
3. App is immediately back on the original account. No schema/data changed.
4. (Optional, later) once stable for 24–48h, drop the shared-account exposure by
   rotating the old password; only then, if desired, remove unused users:
   ```sql
   DROP USER IF EXISTS 'solavel_app'@'127.0.0.1';
   DROP USER IF EXISTS 'solavel_provisioner'@'127.0.0.1';
   FLUSH PRIVILEGES;
   ```

No step here drops or alters tenant data, so rollback cannot lose data.

---

## 8. Go/No-go summary

| Gate | Must be true to proceed |
|---|---|
| Backup | logical dump + grants file written, dump completed |
| 6a runtime read/write | SUCCEEDS as `solavel_app` |
| 6b runtime escalation | DENIED (create/drop/create-table) |
| 6c provisioner | create+migrate SUCCEEDS as `solavel_provisioner` (incl. triggers) |
| 6d activation e2e | `STEP2 RESULT: PASS` |
| 6e suite (existing test creds) | `120 passed` |
| **6f split-user proof** | **`6f RESULT: PASS`** (authoritative split-user gate) |

If any gate fails → execute §7 rollback, do not leave a half-applied state.

**Test-environment decision (resolved):** the normal/full suite is NOT forced
onto the production DML-only user — provisioning tests need create/drop rights.
The test env keeps its current creds (optionally a separate split-user *test*
mode later). The window is gated on §6f proving the real production split users
work, not on converting the suite.
