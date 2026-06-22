# SolaStock — Implementation Roadmap

**Status:** Plan only. No code, no migrations, no Finance changes until you say **"start implementation"**.

This sequences the build so the **canonical stock engine lands first and correctly**, the UI (already prototyped) sits on top, and Finance integration is additive and reversible.

---

## Guiding rules (non-negotiable, from the audit)
1. One canonical `stock_ledger`; one `StockLedgerService` writer.
2. Decimal quantities; posted documents immutable; everything carries `source_type/source_id` + idempotency.
3. `organization_id` on every table + one global scope.
4. Reports read the ledger / its derived `stock_balances`.
5. No database connection to Finance; integrate via the event contract + outbox.
6. Mock data until the UI is fully converted and stable (per Phase-2 UI plan).

---

## Phase 0 — Foundations (already mostly done)
- [x] Laravel app scaffolded at `solavel-inventory`, live at `/inventory/dashboard`.
- [x] SolaStock demo UI running (CDN React) + favicon (amber `#e09921`).
- [x] Apache subpath mount, `.env` subpath/session config, central SSO env.
- [ ] Decide DB connection (own tenant DB) + base CI (lint, phpunit, pint).
- **Exit:** repo builds, app serves, team aligned on the five laws.

## Phase 1 — Canonical Stock Engine (THE core; ship this first) ⭐ recommended Phase-1 scope
Backend only; provable correctness before any operations UI.
- [ ] Migrations: `organizations`, `inventory_settings`, master data (`items`, `item_variants`, `item_categories`, `item_brands`, `units`, `unit_conversions`, `item_barcodes`, `item_images`, `item_suppliers`), topology (`warehouses`, `warehouse_zones`, `warehouse_bins`), `lots`, `serial_numbers`.
- [ ] Migrations: `stock_ledger`, `stock_balances`, `cost_layers`, `reservations`, `inventory_audit_logs`.
- [ ] `BelongsToOrganization` global scope + `OrganizationContext`.
- [ ] `StockLedgerService` (the only writer): post(), lockForUpdate on balances, decimal math, idempotency, transactional balance projection, audit log.
- [ ] `CostingEngine` strategy: weighted-average (v1) + FIFO via `cost_layers`.
- [ ] Opening-stock document (`opening_stock_entries`) — also the import vehicle.
- [ ] Adjustment document (single, GL-ready) posting to ledger.
- [ ] Integrity job: assert `SUM(ledger) == stock_balances` (must always pass).
- [ ] Test suite: concurrency (parallel posts), negative-stock policy, FIFO layer consumption, idempotent retries, reversal correctness.
- **Exit:** can receive/adjust/open stock via service+tests with perfect ledger↔balance consistency. **No UI required to prove this.**

## Phase 2 — UI Conversion + Read APIs
- [ ] Convert demo from CDN/Babel → Vite React modules (per `PHASE2-VITE-REACT-PLAN.md`).
- [ ] JSON read APIs: items, warehouses, balances, item ledger, dashboard widgets.
- [ ] Wire dashboard widgets + draggable layout (`dashboard_layouts`) to real read APIs (keep mock fallback until stable).
- [ ] Items module UI (list/detail/create-edit, variants, barcodes, images) on real APIs.
- **Exit:** dashboard + items run on real data; light/dark; tablet-smooth; no console errors.

## Phase 3 — Inbound Operations (Purchasing + Receiving)
- [ ] `purchase_orders`/lines, `goods_receipts`/lines, `supplier_returns`/lines.
- [ ] GRN posting → IN ledger with cost (fixes Finance race + missing cost).
- [ ] Partial receiving, backorders, expected dates.
- [ ] UI: PO list/create, GRN receive/inspect, supplier returns.
- **Exit:** full PO→GRN→stock flow, ledger-correct.

## Phase 4 — Outbound Operations (Sales + Fulfillment)
- [ ] `sales_orders`/lines, `reservations`, `pick_lists`, `shipments`, `sales_returns`.
- [ ] Reservation/availability math (`available = on_hand − reserved`).
- [ ] Shipment posting → OUT ledger + COGS computation.
- [ ] UI: SO, pick/pack/ship, returns, reserved-vs-available.
- **Exit:** full SO→pick→pack→ship→return flow.

## Phase 5 — Warehouse Ops + Counts + Transfers + Landed Cost
- [ ] `stock_transfers` (out+in, in-transit), `stock_counts` (cycle + full), `landed_costs`.
- [ ] 2D bin map (demo `floor.jsx`), zone/bin management, zone permissions.
- [ ] Stock-accuracy widget fed by counts.
- **Exit:** complete internal stock operations.

## Phase 6 — Reports (all ledger-based)
- [ ] Valuation, item ledger (running balance), stock movement, warehouse stock, low stock, dead stock, fast-moving, profitability, COGS, reorder, stock aging, batch/expiry.
- [ ] Drill-down from report → item ledger; PDF/Excel export.
- **Exit:** the report set that beats Zoho/QBO/Odoo, all from one ledger.

## Phase 7 — Finance Integration (additive, feature-flagged)
- [ ] `accounting_mappings` + `finance_posting_outbox`.
- [ ] Outbox worker → Finance posting API; round-trip `journal_ref`.
- [ ] Enable events in order: `opening_stock` → `grn` → `shipment/COGS` → adjustments/counts/returns/landed cost.
- [ ] Customer/supplier/account ref sync from central/Finance.
- [ ] Reconciliation job (SolaStock valuation vs Finance GL).
- **Exit:** SolaStock postings drive Finance GL, exactly-once, reconciled.

## Phase 8 — Migration & Cutover (per org)
- [ ] Import master data + current balances from Finance (opening docs); reconcile to Finance valuation totals.
- [ ] Freeze Finance mini-inventory writes for migrated org.
- [ ] Go-live, monitor reconciliation.
- **Exit:** SolaStock is system-of-record for the org.

## Phase 9 — Differentiators / Later
- [ ] Standard costing + variance postings; 3D warehouse map; AI smart alerts (reorder forecasting, dead-stock detection, rebalancing suggestions — demo already shows the UI); barcode/label printing; mobile picking; advanced approval workflows.

---

## Dependency graph (what blocks what)
```
Phase 0
  └─ Phase 1 (engine) ──┬─ Phase 2 (UI) ──┬─ Phase 3 (inbound)
                        │                  └─ Phase 4 (outbound)
                        ├─ Phase 5 (warehouse ops)
                        └─ Phase 6 (reports)
Phase 1 + 6 ─ Phase 7 (finance integ) ─ Phase 8 (migration) ─ Phase 9
```
Phase 1 gates everything. Do not start operations UI before the engine's consistency tests are green.

---

## Recommended Phase-1 implementation scope (when you say "go")
**Build the canonical stock engine, headless, with tests — nothing else.** Concretely:
1. Core migrations: master data + topology + `stock_ledger` + `stock_balances` + `cost_layers` + `reservations` + `inventory_audit_logs`, all with `organization_id` + decimal quantities.
2. `OrganizationContext` + `BelongsToOrganization` global scope.
3. `StockLedgerService` (single writer) + `CostingEngine` (average + FIFO).
4. Two posting documents to exercise it end-to-end: **Opening Stock** and **Stock Adjustment**.
5. Integrity job + a hard test suite (concurrency, negative policy, FIFO, idempotency, reversal).

This delivers the irreplaceable foundation, proves the architecture that fixes every critical audit risk, and unblocks all later UI/operations work — without touching Finance or committing to UI churn.

---

## Risks to watch during build
- **Scope creep into UI before the engine is proven** — resist; Phase 1 is headless.
- **Re-introducing a second stock writer** for "convenience" — forbidden by design; code-review gate.
- **Tenant scope gaps** — every new table/query must lead with `organization_id`; add a test that fails if a model lacks the scope.
- **Finance coupling creep** — keep it to the event contract + outbox; no shared DB.
- **Production safety** — `solavel-inventory` is live at `/inventory`; keep new work behind the SPA/feature flags until stable (see memory: solavel prod = live dir).
