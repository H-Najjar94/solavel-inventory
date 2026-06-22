# AUDIT — Solavel Finance Inventory Module

**Audited app:** `/var/www/html/solavel-finance`
**Audited by:** Lead architect review (read-only)
**Date:** 2026-06-10
**Purpose:** Establish exactly what the Finance app's inventory module already does, so SolaStock (`solavel-inventory`) can reuse the good parts, avoid the mistakes, and define a clean migration path.

> Scope note: this is an **audit of existing code**, not a spec. Every claim is grounded in real files; file:line references are included where they matter. Nothing in the Finance app was modified.

---

## A. Executive Summary

The Finance app already contains a **large, mature, but architecturally fragmented** inventory module: ~29 inventory migrations, 28 Eloquent models under `app/Models/Inventory/`, ~22 controllers, a dozen posting/costing services, 60+ Blade views across two parallel UIs (an older Bootstrap "admin" module and a newer Tailwind "finance v2" module), and 5 GL-integrated reports with PDF/Excel exports.

It is genuinely capable: multi-level warehouse hierarchy (location → zone → cell), batch/serial tracking, weighted-average costing, opening stock, stock adjustments, landed cost, supplier returns, loans, consumption/purchase requisitions, reorder alerts, and full journal-entry posting with a per-org account-defaults resolver.

**However**, it carries serious data-integrity debt that makes it unsuitable to lift wholesale:

1. **Two stock truths that never reconcile.** Aggregate quantity lives in `inventory_items.qty_on_hand` (written by costing/adjustment/opening services); batch quantity lives in `inventory_stock_items.remaining_quantity` (written by the receiving controller). Receiving never updates the aggregate; invoicing consumes from the aggregate. They drift apart with no reconciler.
2. **Competing stock writers.** At least **7 distinct code paths** write stock/cost. There is no single canonical ledger writer.
3. **No post-posting immutability.** Posted invoices/bills can still be edited, orphaning movements and corrupting COGS.
4. **Receiving has a race condition** (no `lockForUpdate`, raw SQL insert).
5. **Costing is weighted-average only**, despite batch/FIFO infrastructure existing and a `inventory_costing_method` config that is never honored.
6. **`inventory_movements.quantity` is an INTEGER column** — fractional units silently truncate.
7. **Repair/backfill services exist** (`InventoryIntegrityRepairService`, `backfillPostedNoteInventoryMovements`) — a strong signal the normal flow produces inconsistent data.

**Verdict (full detail in §M):** Treat Finance inventory as a **reference and a data source for migration**, not a codebase to fork. SolaStock should be built around **one canonical, append-only stock ledger** with a single writer, and integrate back to Finance for accounting.

---

## B. Current Finance Inventory Map

```
TENANCY
  TenantModel (per-tenant DB connection) + BelongsToOrganization trait (row-level org scope)
  → mixed: some models have organization_id + global scope, others rely on connection only  ⚠ inconsistent

MASTER DATA
  InventoryItem ─┬─ InventoryCategory (self-nested)
                 ├─ InventoryUnit
                 └─ accounts: purchase / income / inventory_asset (required) / cogs

WAREHOUSE HIERARCHY
  InventoryLocation (warehouse, max_capacity_units)
    └ InventoryZone (warehouse_keeper, per-user zone permissions)
        └ InventoryCell (bin)

STOCK (TWO TIERS — DO NOT RECONCILE  ⚠)
  Tier 1 (aggregate):  inventory_items.qty_on_hand / avg_cost
  Tier 2 (batch):      InventoryStock (unique item+loc+zone+cell) → InventoryStockItem (batch/lot/serial, FIFO remaining)

LEDGER (partial)
  InventoryMovement (IN/OUT, polymorphic source, unit_cost, total_cost)  ← closest thing to a ledger
  InventoryTransaction (document container for adjustments/usages/loans/valuations)
  InventoryValuation (selective cost log — NOT complete history  ⚠)

OPERATIONS
  Receiving:    InventoryReceiving → InventoryReceivingItem (GRN, QA accept/reject, batch codes)
  Returns:      InventoryReturnSupplier → ...Item
  Adjustments:  InventoryAdjustment (simple) AND InventoryStockAdjustment(+Lines) (GL-posted)  ⚠ two adjustment models
  Opening:      InventoryOpeningEntry(+Lines), InventoryOpeningStockApproval
  Loans:        InventoryLoan
  Consumption:  InventoryConsumptionRequest(+Items, Allocations), InventoryStockUsage
  Requisition:  InventoryPurchaseRequest(+Items)

POSTING / COSTING SERVICES
  InventoryCostingService (recordIn/recordOut, weighted-avg)
  InventoryStockAdjustmentPostingService / ...ReversalService
  InventoryOpeningPostingService / ...ReversalService
  LandedCostService
  COGSPostingService, BillPostingService, NotePostingService (credit/debit notes)
  OrgAccountDefaultsContext (account resolver — this part is good)

UI (TWO PARALLEL FRONT-ENDS  ⚠)
  admin/inventory/*   Bootstrap 5, RTL, ~full CRUD incl. transfer/disposal/loans
  finance/inventory/* Tailwind v2, items/units/categories/adjustments/reconciliation/opening only

REPORTS (read mix of ledger + recompute)
  stock-on-hand, valuation-summary, valuation-detail, movement, aging  (+ PDF/Excel)
```

---

## C. Existing Database Tables / Migrations

Location: `database/migrations/inventory/` (~29 files) plus `inventory_movements`, journal tables in `database/migrations/finance/`.

| Table | Purpose | Tenant/org cols | Notes / risks |
|---|---|---|---|
| `inventory_items` | Item master (SKU, barcode, tracking_type enum quantity/serial/batch, item_type enum inventory/non_inventory/service, valuation_method enum fifo/lifo/average **default average**) | `organization_id` + global scope | Carries `qty_on_hand`, `avg_cost`/`average_cost` (dual columns kept in sync), reorder fields, 5 account FKs (`inventory_asset_account_id` required, `cogs_account_id`, `purchase_account_id`, `default_purchase_account_id`, `income_account_id`) |
| `inventory_categories` | Self-nested category tree (`parent_id`, `level`) | TenantModel only | No org column |
| `inventory_units` | UoM (name, symbol) | TenantModel only | No unit conversions table ⚠ |
| `inventory_locations` | Warehouses (`location_type`, `max_capacity_units`) | TenantModel only | Capacity present |
| `inventory_zones` | Zones (warehouse_keeper, per-user perms) | TenantModel only | |
| `inventory_cells` | Bins | TenantModel only | |
| `inventory_stocks` | **Tier-1-of-2** aggregate per (item,loc,zone,cell), `total_quantity` | `organization_id` | UNIQUE(item,loc,zone,cell). Never updates item.qty_on_hand ⚠ |
| `inventory_stock_items` | **Tier-2** batch/lot/serial, `initial_quantity`, `remaining_quantity`, `unit_cost`, status enum | `organization_id` | `unit_cost` often unset by receiving ⚠; no expiry_date column ⚠ |
| `inventory_transactions` | Document container | TenantModel only | overlaps with movements conceptually |
| `inventory_movements` | IN/OUT ledger, polymorphic `source_type/source_id`, `related_line_id`, `unit_cost`, `total_cost`, requires `inventory_account_id` | `organization_id` | **`quantity` is INTEGER** 🔴 (fractional truncation); `idempotency_key` NOT in base migration (added conditionally → `Schema::hasColumn` guards) |
| `inventory_valuations` | Selective cost log | TenantModel only | Only landed-cost/some adjustments write here → cannot reconstruct COGS ⚠ |
| `inventory_adjustments` | Simple qty add/remove (qty_before/after) | `organization_id` | Distinct from the GL-posted adjustment below ⚠ duplicate concept |
| `inventory_stock_adjustments` (+`_lines`) | Formal GL-posted adjustment, draft→posted→reversed, `journal_entry_id`, `posted_guard_key` | `organization_id` | Good pattern (status + guard) |
| `inventory_receivings` (+`_items`) | GRN with QA accept/reject, auto batch codes | TenantModel only | Writer has race condition 🔴 |
| `inventory_return_suppliers` (+`_items`) | Supplier returns | TenantModel only | |
| `inventory_opening_entries` (+`_lines`) | Opening stock, draft→posted→reversed, JE | `organization_id` | Good pattern; duplicate-opening guard unverified ⚠ |
| `inventory_opening_stock_approvals` | Opening approvals/remediation | `organization_id` | |
| `inventory_loans` | Stock loans (returned/lost/damaged) | TenantModel only | |
| `inventory_consumption_requests`/`_items`/`inventory_consumption_allocations` | Internal stock requisition + fulfillment | TenantModel only | |
| `inventory_purchase_requests`/`_items` | Internal purchase requisition | TenantModel only | Not a real PO/GRN flow |
| `inventory_stock_usages` | Consumption records | TenantModel only | |

**Notably absent:** unit_conversions, item_variants, item_brands, item_barcodes (multi-barcode), item_images table, item_suppliers (supplier price list / supplier item codes), serial_numbers as first-class, reservations, sales orders / pick / pack / ship, stock_counts (cycle count), stock_transfers as a posted document with lines (admin transfer exists as a controller flow, weak), stock_balances as a clean derived table.

---

## D. Existing Models and Relationships

28 models under `app/Models/Inventory/`. Full per-model map captured during audit; highlights:

- **InventoryItem** — `HasFactory, SoftDeletes, BelongsToOrganization`. Relations to category, unit, stocks, stockItems, usages, loans, adjustments, valuations, movements, and 4 `Account` FKs. Helpers: `currentStock()`, `isLowStock()`, `calculateCOGS(qty)` (= `qty * avg_cost`), `hasSufficientStock(qty)` (honors `finance.allow_negative_inventory`).
- **InventoryStock** — `BelongsToOrganization`. `findOrCreateUnique(item,loc,zone,cell)`, `addStock()` (creates batch, FIFO), `reduceStock()` (FIFO consume, `lockForUpdate`), `recalculateTotals()`.
- **InventoryStockItem** — batch/serial with status state machine (`in_stock`, `reserved`, `used`, `partially_used`, `fully_used`, `returned`, `damaged`), `deductQuantity`, `addQuantity`, `scopeAvailable`, `scopeAtLocation`.
- **InventoryMovement** — `morphTo('source')` resolving Bill/Invoice/SalesReceipt/CreditNote/DebitNote/InventoryStockAdjustment/InventoryOpeningEntry/LandedCost; scopes `inbound/outbound/bySource/forItem`.
- **InventoryStockAdjustment / ...Line** and **InventoryOpeningEntry / ...Line** — both use `STATUS_DRAFT/POSTED/REVERSED`, `journal_entry_id`, `posted_by/at`, `posted_guard_key` (idempotency). **This status+guard+JE pattern is the single best thing to carry forward.**

**Tenancy inconsistency (key finding):** Two mechanisms coexist — per-tenant DB connection (`TenantModel`) AND a row-level `organization_id` global scope (`BelongsToOrganization`). Only ~7 of 28 models use the org scope; the rest rely on the connection. Mixed isolation is a correctness and auditing hazard.

---

## E. Existing Services and Posting Flow

**Seven stock/cost writers** (the core problem):

| # | Writer | Writes | Txn + lock | Risk |
|---|---|---|---|---|
| 1 | `InventoryCostingService::recordInMovement` | items.qty_on_hand, avg_cost; movements(IN) | ✅ `lockForUpdate` | safe |
| 2 | `InventoryCostingService::recordOutMovement` | items.qty_on_hand; movements(OUT) at avg_cost | ✅ | safe; weighted-avg only |
| 3 | `InventoryStockAdjustmentPostingService::post` | items.qty/avg; movements; lines; JE | ✅ | reversal cascade unchecked ⚠ |
| 4 | `InventoryOpeningPostingService::post` | items.qty/avg; movements; JE | ✅ | duplicate-opening guard unverified ⚠ |
| 5 | `InventoryReceivingController::approveWithSelection` | inventory_stocks, inventory_stock_items (raw SQL) | ❌ no lock | **race condition** 🔴; **never touches items.qty_on_hand** 🔴 |
| 6 | `LandedCostService::post` | items.avg_cost; valuations; movements(qty=0) | ✅ | cost-only movements confuse reconciliation ⚠ |
| 7 | `NotePostingService` (credit/debit notes) | movements (reverse) | weak | needs backfill repair ⚠ |

**Posting flows that are well-built:** Bill → Dr Inventory / Cr AP (+VAT handling, reverse charge). Invoice/SalesReceipt → COGS JE Dr COGS / Cr Inventory at avg_cost (auto via `finance.auto_post_cogs`). Adjustment increase → Dr Inventory / Cr Adj-Gain; decrease → Dr Adj-Loss / Cr Inventory. Opening → Dr Inventory / Cr Opening-Equity. All linked by `source_type/source_id` with `posted_guard_key` idempotency. **The accounting side is solid; the stock side is fragmented.**

**Negative stock:** enforced in `InventoryCostingService` and `InventoryItem::hasSufficientStock` via `config('finance.allow_negative_inventory', false)` — but only on the aggregate tier, so receiving/batch flows bypass it.

---

## F. Existing UI and Routes

- **Two parallel UIs.** `admin/inventory/*` (Bootstrap 5, RTL/Arabic) is the most feature-complete (receiving inspect/approve, transfer, disposal, loans, consumption, purchase requests, labels). `finance/inventory/*` (Tailwind v2 design system) covers items/units/categories/adjustments/reconciliation/opening-remediation only. Divergent look, behavior, and coverage.
- **Tech:** Blade + Tailwind/Bootstrap + Alpine.js. No Livewire/Vue reactivity in inventory; forms are static; cascading dropdowns for location→zone→cell.
- **Routes:** ~85 inventory routes across `routes/modules/inventory.php` and `routes/finance.php`, guarded by `auth`, org middleware (`org.resolve/membership/selected`, `central.project:finance`), and granular `permission:*` / `feature:tracker.manage_items`.
- **Limitations called out:** no warehouse map/visualization; weak search (name/sku/barcode only, no faceted/location filters); no bulk ops / no item import flow in UI; document item-selector has no inline search and shows no live availability/cost; no expiry tracking UI; reports are static (no drill-down); finance UI lacks transfer/disposal/opening screens that admin has.

---

## G. Existing Reports

| Report | Exists | Data source | Honest assessment |
|---|---|---|---|
| Stock on hand | ✅ | `InventoryItem.qty_on_hand * avg_cost` (recompute, **not** ledger) | OK but reads aggregate tier only |
| Valuation summary | ✅ | InventoryItem aggregate | same |
| Valuation detail | ✅ | `InventoryMovement` (per-movement cost) | ledger-based ✅ |
| Stock movement | ✅ | `InventoryMovement` | ledger-based ✅ |
| Stock aging | ✅ | `inventory_stock_items.received_at` DATEDIFF buckets | batch-tier; **uses received_at, no real expiry** |
| COGS report | ❌ | — | not implemented (COGS only posts to GL) |
| Item ledger (per-item running balance) | ❌ | — | not implemented |
| Warehouse stock (by location rollup) | ❌ | — | not implemented |
| Low stock report | ❌ (alerts only) | reorder fields | alert flag, no report |
| Dead/slow-moving | ❌ | — | no velocity tracking |
| Fast-moving | ❌ | — | — |
| Item profitability | ❌ | — | — |
| Reorder report | ❌ | — | — |
| Batch/expiry report | ❌ | — | no expiry data |

All exports (PDF/Excel) exist for the 5 implemented reports. **Reports inconsistently read aggregate vs ledger** — a symptom of the two-truths problem.

---

## H. Accounting Integration

**This is the strongest part of the module and should be preserved conceptually.**

- **Account resolution:** centralized in `OrgAccountDefaultsContext` (config → per-org `OrgAccountDefault` table → postable-child fallback → hardcoded code → exception). Per-item overrides win. No scattered hardcoded accounts in posting logic.
- **Config:** `config/finance.php` keys — `inventory_asset_code` (1300), `cogs_products_code` (5001), `cogs_services_code` (5002), `opening_balance_equity_code` (3501), `inventory_adjustment_gain_code` (4303), `inventory_adjustment_loss_code` (6804), `allow_negative_inventory` (false), `inventory_costing_method` (`average` — **not honored**), `auto_post_cogs` (true).
- **JE source types:** `Bill`, `Invoice`, `SalesReceipt`, `CreditNote`, `DebitNote`, `InventoryStockAdjustment`, `InventoryOpeningEntry`, `LandedCost` — each JE carries `source_type/source_id/source` and a `posted_guard_key`.
- **Postings:** documented per type in §E. COGS auto-posts at invoice/receipt time using item `avg_cost`. Returns reverse via contra accounts and reversing movements.

---

## I. Data Integrity Risks (ranked)

🔴 **Critical**
1. **Two stock truths never reconcile** — `items.qty_on_hand` vs `stock_items.remaining_quantity`. Receiving updates only batch; invoicing consumes only aggregate.
2. **Receiving race condition** — `approveWithSelection` has no `lockForUpdate`, uses raw SQL insert; concurrent approvals lose writes.
3. **No post-posting immutability** — posted invoices/bills/receipts can be edited, orphaning movements and corrupting COGS. No observer/DB guard.
4. **`inventory_movements.quantity` is INTEGER** — fractional UoM silently truncates.

⚠ **High**
5. **7 competing stock writers**, no canonical ledger writer.
6. **Weighted-average only** despite `inventory_costing_method` config and batch infra → no FIFO/standard, COGS may not satisfy some tax regimes.
7. **`inventory_valuations` is a partial log** → cannot reconstruct valuation/COGS history from it.
8. **Reversal cascades unvalidated** — reversing an opening/adjustment doesn't check for dependent downstream OUT movements → can drive negative/inconsistent stock.
9. **Idempotency optional** — `idempotency_key` guarded by `Schema::hasColumn`; if absent, retries duplicate movements.
10. **Repair/backfill services exist** (`InventoryIntegrityRepairService`, note-movement backfill) → normal flow demonstrably yields inconsistent data.

⚠ **Medium**
11. **Tenancy inconsistency** — connection-based vs `organization_id` scope mixed across models.
12. **Two adjustment models** (`InventoryAdjustment` simple vs `InventoryStockAdjustment` GL) — concept duplication.
13. **Batch cost not set at receiving**; **batch not recorded on consumption** → FIFO/traceability impossible.
14. **Reorder = alerts only**, no history, no auto-PO.
15. **No expiry data** despite an aging report.
16. **Performance:** reports recompute over `inventory_items`; no `stock_balances` materialized table; movement table has reasonable indexes but no `(organization_id, item_id, warehouse_id, date)` composite for ledger reads.

---

## J. What CAN be reused (concepts/data, not code wholesale)

1. **Account-defaults resolver pattern** (`OrgAccountDefaultsContext`) — port the *idea* to SolaStock's accounting-mapping layer.
2. **Status + posted_guard_key + journal_entry_id pattern** from `InventoryStockAdjustment`/`InventoryOpeningEntry` — this is the right way to do posted, idempotent, reversible documents.
3. **Polymorphic `source_type/source_id/related_line_id`** on movements — keep as the ledger's provenance contract.
4. **Warehouse hierarchy model** (location→zone→cell with capacity, zone keeper, per-user zone permissions) — good domain model; reuse the shape.
5. **Item master data + account-link fields** — `tracking_type`, `item_type`, account FKs, reorder fields — a good starting column set.
6. **Existing item/warehouse/category/unit DATA** — migrate the rows (see §L), not the schema verbatim.
7. **JE posting recipes** (Dr/Cr per document type) — reuse as the contract SolaStock calls Finance with.

## K. What must NOT be reused

1. **The two-tier stock model.** SolaStock uses ONE canonical ledger; `stock_balances` is a derived/materialized projection, never an independent truth.
2. **The 7 separate stock writers.** Exactly one writer (a `StockLedgerService`) may append to the ledger.
3. **`InventoryReceivingController::approveWithSelection`** raw-SQL/no-lock approach.
4. **`inventory_movements.quantity` INTEGER** — use `decimal(18,4)` in SolaStock.
5. **Two parallel UIs** (admin Bootstrap + finance v2). SolaStock has one React UI.
6. **The simple `InventoryAdjustment`** model (the non-GL one) — collapse into one adjustment document type.
7. **Direct edits of posted documents.** Posted = locked; corrections = reversal/new document only.
8. **`inventory_valuations` as a selective log** — SolaStock's ledger IS the valuation history.
9. **Repair/backfill services** — if you need them, the design is wrong; SolaStock writes correctly the first time inside one transaction.

---

## L. Recommended migration path to SolaStock

Phased, non-destructive. Finance keeps running throughout.

1. **Freeze the contract, not the code.** Catalog the JE recipes and account mappings (§H) as SolaStock's integration contract.
2. **Build SolaStock canonical schema** (see `SOLASTOCK_DATABASE_DESIGN.md`) with a single `stock_ledger` + derived `stock_balances`.
3. **One-time data import (read-only from Finance):**
   - Master data: items, categories, units, locations/zones/cells, suppliers/customers → map to SolaStock master tables (dedupe by SKU/name within org).
   - **Opening balances, not history:** compute current on-hand and average cost per (item, warehouse) from Finance and import as a single **Opening Stock document** per warehouse in SolaStock (one ledger batch). Do **not** try to replay 7-writer history — that would import the inconsistencies.
   - Optionally archive Finance movement history into a read-only `legacy_movements` table for reference.
4. **Reconcile import** against Finance valuation-summary totals; sign off per org before go-live.
5. **Switch ownership** (see `SOLASTOCK_FINANCE_INTEGRATION_PLAN.md`): SolaStock becomes system-of-record for stock ops; Finance receives postings via API/events. Finance's mini-inventory write paths are frozen (read-only) for migrated orgs.
6. **Backfill nothing.** Going forward, every stock-affecting document writes the canonical ledger once, in one transaction.

---

## M. Final Verdict

The Finance inventory module is a **rich functional reference and a usable data source — but an anti-pattern as an architecture.** Its accounting integration (account resolver, posted-guard documents, JE recipes, polymorphic provenance) is worth carrying forward conceptually. Its stock engine (two un-reconciled tiers, seven writers, integer quantities, mutable posted docs, repair services) must be replaced, not extended.

**Build SolaStock fresh around a single append-only canonical stock ledger with exactly one writer, derived balances, locked posted documents, decimal quantities, consistent `organization_id` isolation, and an audit log — then integrate to Finance for accounting via a stable contract.** Migrate Finance's master data and current balances; do not replay its history.

This is the foundation the rest of the design documents build on.
