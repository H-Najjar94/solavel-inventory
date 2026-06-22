# Release checklist — FIFO `cost_layer_consumptions`

Covers the FIFO transfer/reversal layer-fidelity change and its new
`cost_layer_consumptions` table. Verified against the live MySQL on
`127.0.0.1:3306`. **Privilege separation stays a runbook only — no DB-user
changes in this release** (see §6).

---

## 0. Ground truth (verified now)

15 tenant DBs exist. The new table is needed only by **inventory-provisioned**
tenants. Current state:

| Tenant DB | inventory | `cost_layer_consumptions` | action |
|---|---|---|---|
| `tenant_000002` | yes | **NO** | **migrate (backfill)** |
| `tenant_000008` | yes | **NO** | **migrate (backfill)** |
| `tenant_990010` | yes | yes | reserved test DB — done |
| `tenant_990011` | yes | yes | reserved test DB — done |
| 11 others | no | no | nothing — see note |

> **Note:** the 11 non-inventory tenants need no action. When any of them
> activates inventory, `SecureTenantProvisioner::provisionInventory()` runs the
> full `database/migrations/tenant` path, which **includes** this migration — so
> new tenants get the table automatically. Only the **two already-provisioned**
> tenants (`tenant_000002`, `tenant_000008`) must be backfilled.

Re-confirm the live set before deploy (do not hardcode blindly):

```bash
php artisan tinker --execute='
$a="mysql_admin";
foreach(collect(DB::connection($a)->select("SELECT SCHEMA_NAME s FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE \"tenant\\_%\""))->pluck("s") as $d){
  $inv=DB::connection($a)->select("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=? AND table_name=\"cost_layers\"",[$d])[0]->c;
  $clc=DB::connection($a)->select("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=? AND table_name=\"cost_layer_consumptions\"",[$d])[0]->c;
  if($inv && !$clc) echo "BACKFILL: $d\n";
}'
```
Expected output today: `BACKFILL: tenant_000002` and `BACKFILL: tenant_000008`.

---

## 1. Migration safety (verified)

- **Idempotent — two layers:**
  1. Body guard: `Schema::hasTable('cost_layer_consumptions') or Schema::create(...)` — a second `up()` is a no-op even if the table exists but the migration ledger row doesn't (out-of-band create).
  2. Laravel ledger: a re-run reports `INFO Nothing to migrate.` (verified on `tenant_990010`).
- **Additive only:** creates ONE new table. It does **not** alter or touch any
  existing table, so it cannot corrupt current data and is safe on a live DB.
- **Rollback is surgical:** on already-migrated tenants this migration lands in
  its **own batch** (`[2] Ran`, verified). `migrate:rollback` removes **only**
  `cost_layer_consumptions`; the batch-`[1]` inventory tables are untouched.
- **up/down both proven** on a throwaway `scratch_clc_test` DB: up → table
  present; rollback → table gone; scratch DB dropped. No real tenant touched.

---

## 2. Exact migration commands (backfill the two live tenants)

Per-tenant one-shot (the `tenant` connection reads `TENANT_DB_DATABASE`):

```bash
cd /var/www/html/solavel-inventory

for DB in tenant_000002 tenant_000008; do
  echo "== $DB =="
  TENANT_DB_DATABASE=$DB php artisan migrate \
    --database=tenant \
    --path=database/migrations/tenant \
    --force
done
```

**Expected output per tenant** (only the new migration runs; everything else
already ran):

```
INFO  Running migrations.
2026_06_10_100015_create_cost_layer_consumptions_table .. DONE
```

A re-run of the same loop is safe and prints `INFO Nothing to migrate.`

---

## 3. Deploy order & downtime

- **Order:** migration **first**, then deploy the new application code.
  The new table is written by `StockLedgerService` (FIFO OUT) and
  `StockTransferService` (transfer post) — a FIFO OUT/transfer would error if the
  table is absent, so it must exist before the new code serves traffic.
- **Old code is safe with the table present.** Grep confirms only the two new
  services reference `cost_layer_consumptions`; pre-deploy code never queries it,
  so creating the table ahead of the code is a no-op for the running app.
- **Zero-downtime — no maintenance mode required.** The change is purely additive
  (new table; existing schema and the immutable `stock_ledger` untouched). Safe
  table-before-code window:
  1. Backfill `tenant_000002`, `tenant_000008` (§2) while old code runs — no effect.
  2. Deploy the new code; new FIFO writes begin populating the table.

---

## 4. Post-deploy verification

### 4a. Table exists on every inventory tenant
```bash
php artisan tinker --execute='
$a="mysql_admin"; $bad=0;
foreach(collect(DB::connection($a)->select("SELECT SCHEMA_NAME s FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE \"tenant\\_%\""))->pluck("s") as $d){
  $inv=DB::connection($a)->select("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=? AND table_name=\"cost_layers\"",[$d])[0]->c;
  if(!$inv) continue;
  $clc=DB::connection($a)->select("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=? AND table_name=\"cost_layer_consumptions\"",[$d])[0]->c;
  echo sprintf("%-18s clc=%s\n",$d,$clc?"yes":"MISSING"); if(!$clc)$bad++;
}
echo $bad? "FAIL: $bad missing\n":"OK: all inventory tenants have the table\n";'
```
Expected: every inventory tenant `clc=yes`, final line `OK: ...`.

### 4b. FIFO smoke test (safe — automated, rolls back)
The FIFO fidelity suite exercises a real multi-layer transfer + reversal against
the reserved test tenant and rolls back (no residue):
```bash
vendor/bin/phpunit tests/Feature/Stock/FifoLayerFidelityTest.php
```
Expected: `4 passed` — two destination layers preserved (10@$5, 5@$8, never $6),
valuation restored to $130 on reversal.

> To smoke a **live** safe tenant manually: as that tenant, post a small opening
> stock at two costs, transfer a qty spanning both layers, confirm the
> destination has two `cost_layers` rows (not one blended) and a
> `cost_layer_consumptions` row per consumed layer, then reverse and confirm the
> source layers/value return. Do this only on a disposable/test tenant.

### 4c. Cross-org dashboard/report isolation still holds
```bash
vendor/bin/phpunit --filter 'ReportOrgIsolation|TenantIsolation'
```
Expected: all green — two orgs in one tenant DB cannot see each other's metrics/reports.

### 4d. Permission roles still deny viewer / provisioning
```bash
vendor/bin/phpunit --filter InventoryPermission
```
Expected: all green — no default-admin; viewer cannot create/post/reverse/provision.

**Combined verification run (captured now):**
```
phpunit --filter 'ReportOrgIsolation|InventoryPermission|FifoLayerFidelity|TenantIsolation'
→ 19 passed, 74 assertions
```

---

## 4e. Log-watch / health check (use for every release)

Watch the live log for FIFO/table errors during and after a release:

```bash
# Live tail filtered to FIFO + tenancy + errors:
tail -f storage/logs/laravel.log | grep -iE "cost_layer_consumptions|CostLayerConsumption|StockTransfer|StockLedger|exception|ERROR"

# One-shot "anything bad since I started?" (set BASE to the line count you noted pre-release):
BASE=$(wc -l < storage/logs/laravel.log)         # capture before deploy
# ...after deploy/smoke...
NEW=$(( $(wc -l < storage/logs/laravel.log) - BASE ))
[ "$NEW" -gt 0 ] && tail -n "$NEW" storage/logs/laravel.log \
  | grep -iE "cost_layer|exception|ERROR" || echo "clean: no new FIFO/error lines"

# Confirm FIFO rows are flowing on a live inventory tenant (expect a growing count):
php artisan tinker --execute='echo DB::connection("mysql_admin")
  ->select("SELECT COUNT(*) c FROM tenant_000002.cost_layer_consumptions")[0]->c."\n";'
```
Green signal after the first real transfer: a new `cost_layer_consumptions` row
appears for that tenant and the log shows no exception.

## 4f. Controlled live smoke test (done — for reference / re-use)

A committed FIFO smoke test was run against reserved test tenant `tenant_990010`
(disposable, not customer data) through the **real services / live code path**.
Result — all green:

```
source seeded value = 130.00
destination layer count = 2 (separate layers, NOT blended $6)
destination layer costs = [5.0000, 8.0000]
cost_layer_consumptions rows = 2  (10@5.0000, 5@8.0000)
source value after = 40.00   destination value after = 90.00
total value conserved = 130.00
SMOKE RESULT: PASS   — 0 new log lines, no exception
```

> **Immutability caveat (important for future smoke tests):** a *committed* smoke
> test cannot be fully cleaned up — `stock_ledger` is append-only (a DB trigger
> blocks `DELETE`), so its rows persist by design. Run committed smoke tests
> **only on a disposable/reserved test tenant**, and accept that inert ledger
> rows remain. For residue-free verification use the phpunit `FifoLayerFidelityTest`
> instead (transaction rollback commits nothing, so the immutable trigger never
> fires on a real delete).

## 5. Rollback plan

The change is additive, so rollback is low-risk and data-preserving.

- **Code rollback (preferred):** redeploy the previous app build. The
  `cost_layer_consumptions` table can stay — old code ignores it; no FIFO data is
  lost. This is the normal rollback.
- **Full rollback (only if required):** after reverting code, drop the table per
  tenant. It is its own batch, so this removes only this table:
  ```bash
  for DB in tenant_000002 tenant_000008; do
    TENANT_DB_DATABASE=$DB php artisan migrate:rollback \
      --database=tenant --path=database/migrations/tenant --force
  done
  ```
  Expected: `2026_06_10_100015_create_cost_layer_consumptions_table .. DONE`.
  > Caution: only do this if no new-code FIFO writes have populated the table you
  > care about. Dropping it discards the per-layer consumption history written
  > since deploy (valuation/ledger rows are unaffected). Prefer code-only rollback.

---

## 6. Privilege separation — deferred (unchanged this release)

Per decision, the runtime/provisioning DB-user split stays **runbook only**
([DB_PRIVILEGE_SEPARATION.md](DB_PRIVILEGE_SEPARATION.md)). Do **not** change DB
users or `.env` credentials in this release. The blocking prerequisite — running
provisioning migrations under an elevated connection (§4b of that runbook) — must
be implemented and tested first.

---

## 7. Safe-now vs must-wait

| Item | Status |
|---|---|
| Backfill `cost_layer_consumptions` on `tenant_000002`, `tenant_000008` | **Safe now** (additive, idempotent, table-before-code) |
| Deploy FIFO layer-fidelity code (after backfill) | **Safe now** (zero-downtime) |
| FIFO writes populating the new table | **Safe now** |
| Privilege separation (`solavel_app` / `solavel_provisioner`) | **MUST WAIT** — needs the migrate-under-provisioner code change first |
| Dropping/altering any existing tenant table | **N/A** — not part of this release |
