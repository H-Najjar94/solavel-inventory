# SolaStock — product roadmap & screen-by-screen plan

Written as PM + inventory/accounting + UX + senior Laravel/React. Audit date
2026-06-14. This is the product spec, not a backend checklist. It is honest about
where "it exists" is **not** the same as "good enough for real users."

---

## 0. Honest product-readiness verdict

**Backend:** strong. ~30 services, 50 models, 141 routes, immutable ledger, FIFO
engine, fail-closed tenancy, outbox-only Finance. Production-grade foundations.

**Frontend/product:** **functional but not yet a polished SaaS.** Concrete gaps
seen in the code today:
- Screens are **plain data tables**, not visual. ItemDetail shows warehouses as
  `#{warehouse_id}` — **raw IDs, no names**. That alone is a "not production"
  tell.
- **No drawer pattern, no cards, no charts, no global search** primitive. The
  shared UI kit is only: Breadcrumbs, Skeleton, EmptyState, Tabs, StatusBadge,
  Field, ConfirmModal, QuickCreateSelect.
- **Several detail tabs are empty placeholders** (suppliers, barcodes, variants,
  audit on ItemDetail are just `EmptyState`). They look done in a list but aren't.
- **FIFO value is invisible** to users (the data exists; no UI). Users can't see
  what their stock is worth by layer, or what a transfer/reversal did.
- **No i18n / RTL** — English strings are hardcoded; Arabic is not wired.
- **No JS tests** at all; quality rests on backend feature tests.

So: the plumbing is real; the **product experience is roughly an internal admin
tool, not a Zoho/Odoo-class app.** Phase 1 is about turning the highest-value
data into a genuinely good experience, starting with valuation/FIFO visibility.

Legend: ✅ good enough · 🟡 exists but basic/not production-grade UX ·
🟦 scaffolded/placeholder · ❌ missing.

---

## 1. Modules — user-facing definition (20 modules)

For each: **Sees · Does · Data · Alerts · Components · Backend · Missing · Tests.**

### 1. Dashboard 🟡
- **Sees:** KPI cards (on-hand value, # items, low-stock count, pending docs),
  value-by-warehouse, recent movements, alerts.
- **Does:** click a card → drill into the filtered list/report; pick date range.
- **Data:** total inventory value (FIFO), value by warehouse, low-stock & expiry
  counts, open POs/SOs, last N movements.
- **Alerts:** low stock, expiring lots, negative/blocked stock, unreconciled value.
- **Components:** KPI cards, value bar/donut chart, alert tiles, recent-activity list.
- **Backend:** `DashboardMetricsService` (org-scoped). **Missing:** valuation &
  alert widgets, charts. **Tests:** isolation ✅; content test ❌.

### 2. Items / Products 🟡
- **Sees:** searchable item list (image, SKU, name, on-hand, value, status); item
  detail with **valuation panel**, stock-by-warehouse **cards**, movements.
- **Does:** create/edit, search/filter, open valuation drawer, open movement drawer,
  deactivate.
- **Data:** item master, per-warehouse on-hand/avg/FIFO value, movement history.
- **Alerts:** below reorder point, no cost layers but has stock, inactive-with-stock.
- **Components:** list with thumbnail + fast search; detail with cards + drawers.
- **Backend:** full CRUD, `/items/{item}/movements`, **new `/items/{item}/valuation`**
  ✅. **Missing:** valuation **UI**, real suppliers/barcodes/variants tabs (now
  placeholders), faceted search. **Tests:** valuation endpoint ✅; item CRUD ❌.

### 3. Warehouses / Locations 🟡
- **Sees:** warehouse cards (name, type, on-hand value, # items, utilization),
  zone/bin structure, floor map.
- **Does:** create/edit, drill into a warehouse's stock, view bins.
- **Data:** warehouse → zones → bins; per-warehouse value & item count.
- **Alerts:** over-capacity bins, empty active warehouse.
- **Components:** warehouse cards, `WarehouseFloorMap` (exists), bin tree.
- **Backend:** structure CRUD ✅. **Missing:** value/utilization rollups on cards,
  capacity enforcement. **Tests:** ❌.

### 4. Stock balances 🟡
- **Sees:** balances grid (item, warehouse-name, on-hand, reserved, available,
  avg cost, value) with fast search + filters; **value totals**.
- **Does:** search, filter by warehouse/low-stock, open item valuation drawer.
- **Data:** `stock_balances` projection.
- **Alerts:** negative available, below reorder, zero-cost-with-stock.
- **Components:** dense but readable grid, sticky totals, row → drawer.
- **Backend:** `StockBalanceController` ✅. **Missing:** warehouse **names** (shows
  IDs), totals row, drawer. **Tests:** lock race ✅.

### 5. Stock movements (ledger) 🟡
- **Sees:** movement timeline (date, direction badge, qty, unit cost, **running
  balance**, source link); per-OUT, **which FIFO layers were consumed**.
- **Does:** filter (item/warehouse/direction/source/date), open **movement drawer**
  with full detail + consumed layers, jump to source document.
- **Data:** `stock_ledger` (+ `balance_qty_after/value_after`), `cost_layer_consumptions`.
- **Alerts:** reversal markers, negative-stock movements.
- **Components:** timeline/table with badges, movement drawer.
- **Backend:** `/ledger`, `/items/{item}/movements` ✅; running balance in data.
  **Missing:** consumed-layer endpoint, drawer, source deep-links. **Tests:**
  engine ✅; movement-history-with-layers ❌.

### 6. FIFO / valuation ✅ engine / ❌ UI
- **Sees:** per-warehouse **layer stack** (received date, unit cost, original vs
  remaining qty, layer value), FIFO value vs average value, reconciliation badge.
- **Does:** open **valuation drawer** from item/balance/warehouse; expand a layer
  to see the movements that consumed it.
- **Data:** `cost_layers`, `cost_layer_consumptions`, `stock_balances`.
- **Alerts:** FIFO≠average divergence explained (not an error), qty mismatch (real).
- **Components:** layer-stack visual, value comparison, reconciliation badge.
- **Backend:** engine + **`/items/{item}/valuation`** ✅. **Missing:** the UI.
  **Tests:** fidelity ✅, valuation endpoint ✅.

### 7. Stock transfers 🟡
- **Sees:** transfer list/detail; on detail, **which source layers were consumed
  → which destination layers were created**; reversal explanation.
- **Does:** create draft, post, reverse — with a clear "what will happen" preview.
- **Data:** transfers + lines + the two-leg ledger + layers.
- **Alerts:** insufficient stock, expired/quarantined lot (override-gated), reversal effects.
- **Components:** document form, cost-layer movement view, confirm drawer.
- **Backend:** per-layer FIFO transfer/reversal ✅. **Missing:** layer-aware detail
  UI, pre-post preview. **Tests:** fidelity ✅; detail/preview ❌.

### 8. Stock adjustments 🟡
- **Sees:** adjustment list/detail (reason, qty change, value impact).
- **Does:** create increase/decrease, post, reverse.
- **Data:** adjustments + lines + ledger; outbox `adjustment.posted/reversed`.
- **Alerts:** large variance, negative result.
- **Backend:** service + UI 🟡. **Missing:** value-impact display, posting **test** ❌.

### 9. Stock counts ✅ logic / 🟡 UX
- **Sees:** count session (expected vs counted, variance, value impact); **blind**
  option (hide expected).
- **Does:** start count (freeze?), enter counts, review variance, post.
- **Data:** counts + lines; TOCTOU-safe variance vs live on-hand.
- **Alerts:** large variance, recount needed, stale count.
- **Backend:** `StockCountService` (live-qty safe) ✅. **Missing:** guided/blind/
  freeze workflow UX. **Tests:** live-qty ✅; freeze-flow ❌.

### 10. Purchase receiving / GRN 🟡
- **Sees:** PO list → receive against PO; GRN detail (received vs remaining, cost).
- **Does:** create PO, receive (full/partial), post GRN.
- **Data:** PO/lines, GRN/lines; outbox `grn.posted` (Dr inventory / Cr GRNI).
- **Alerts:** over-receipt, price variance, partial remaining.
- **Backend:** services + UI 🟡. **Missing:** receiving UX polish, posting **test** ❌.

### 11. Sales shipment / delivery 🟡
- **Sees:** SO → pick → pack → ship → return pipeline; status per stage.
- **Does:** confirm SO, reserve, pick, pack, ship, return.
- **Data:** SO/pick/pack/shipment/return; outbox `shipment.posted` (Dr COGS / Cr inv).
- **Alerts:** short pick, unshipped reserved, return-to-stock.
- **Backend:** full pipeline services + pages 🟡. **Missing:** pipeline/status UX,
  per-stage **tests** (foundation only).

### 12. Reservations 🟡
- **Sees:** reservations against available stock; what's reserved vs free.
- **Does:** reserve, release; see available = on-hand − reserved.
- **Data:** `reservations`, `available_qty`.
- **Alerts:** over-reservation, stale reservation.
- **Backend:** service + routes 🟡. **Missing:** reservation UI surface, expiry,
  race **tests** (foundation only).

### 13. Lots / batches ✅
- **Sees:** lots per item (lot #, qty, expiry, status), lot movements, FEFO order.
- **Does:** view lot trace, see expiry risk.
- **Data:** `lots`; FEFO consumption.
- **Alerts:** expiring/expired, quarantined.
- **Backend:** `LotService` + pages ✅. **Missing:** richer lot detail UX. **Tests:** ✅.

### 14. Serials ✅
- **Sees:** serial list/detail, status, current location.
- **Does:** look up a serial, see its history.
- **Backend:** `SerialService` ✅. **Missing:** serial timeline UX. **Tests:** ✅.

### 15. Traceability / recall ✅ logic / 🟡 UX
- **Sees:** trace a lot up/down stream; recall workspace (affected lots, actions).
- **Does:** run a trace, open a recall, record actions.
- **Backend:** trace/recall services + reports ✅ (org-scoped). **Missing:** visual
  trace tree UX. **Tests:** foundation ✅.

### 16. Reports ✅ engine / 🟡 UX
- **Sees:** report catalog (valuation, item-ledger, low/dead/fast, expiry, count
  variance, lot-trace, layers, …); run + export.
- **Does:** filter, run, drill, export.
- **Backend:** rich `InventoryReportService` registry + export ✅. **Missing:**
  visual report UX, drill-down, saved/scheduled reports. **Tests:** registry ✅;
  per-report correctness ❌.

### 17. Finance integration preview ✅ safe / 🟡 incomplete
- **Sees:** per-event **journal preview** (Dr/Cr from `IntegrationEvents`), sync
  **status**, retry; "what will sync to SolaBooks."
- **Does:** view outbox, preview journal, retry failed, view status — **never**
  writes Finance tables.
- **Data:** `integration_outbox_events` (tenant-local only).
- **Alerts:** failed sync, unmapped account, pending backlog.
- **Backend:** outbox + status + event→Dr/Cr map ✅ (verified no Finance writes).
  **Missing:** preview/status **screens**, retry UX, journal payload schemas.
  **Tests:** foundation ✅; payload-schema ❌.
- **RULE:** outbox / preview / status / retry / journal-preview **only** until
  explicitly approved. No direct Finance/SolaBooks writes.

### 18. Settings 🟡
- **Sees:** costing method, negative-stock policy, numbering, barcode, approvals.
- **Backend:** `InventorySetting` + page 🟡. **Missing:** clearer settings UX,
  per-setting help, **tests** ❌.

### 19. Users / permissions ✅ logic / 🟡 UX
- **Sees:** who has which inventory role; what each role can do.
- **Does:** (role comes from central `user_organizations.role`) view effective
  permissions; assignment likely lives in central app.
- **Backend:** `InventoryPermissionService` fail-closed ✅. **Missing:** a
  permissions **view** screen; clarity on where assignment happens. **Tests:** ✅.

### 20. Audit log 🟡
- **Sees:** who did what, when (posts, reversals, edits).
- **Backend:** `InventoryAuditLog` model + immutable ledger 🟡. **Missing:**
  audit timeline UI, coverage of which actions are logged. **Tests:** ❌.

---

## 2. UX direction (design system to build)

Target feel: **smooth tablet app**, visual, fast. Concretely, build these shared
primitives (most don't exist yet):

- **Drawer** (right-side slide-over) — the core detail pattern: movement drawer,
  valuation drawer, document preview. Keeps users in context instead of full-page
  navigation.
- **Cards** — KPI cards, warehouse stock cards, layer cards. Replace raw tables on
  overview surfaces.
- **Charts** — value-by-warehouse bar, value-over-time line, layer composition.
  Use a small lib (e.g. Recharts) — additive, no backend change.
- **Status badges** — consistent color language: draft/posted/reversed,
  in-stock/low/out, synced/pending/failed, reconciled/divergent.
- **Global search** (⌘K / top bar) — items, SKUs, lots, serials, documents.
- **Strong empty states** — every list/drawer has a helpful empty state with a
  primary action (some exist; standardize).
- **Names not IDs** — never show `#{warehouse_id}`; always resolve to names.
- **i18n / RTL** — wrap all strings; Arabic-ready layout (logical CSS, `dir`),
  English default. Build the harness now even if Arabic copy lands later.
- **Mobile/tablet** — responsive grid, large touch targets, sticky action bars.

These are **front-end-only and additive** — zero risk to ledger/FIFO/tenancy.

---

## 3. Sidebar / menu structure

```
SolaStock
├── Dashboard
├── Catalog
│   ├── Items
│   ├── Categories / Brands
│   └── Units
├── Stock
│   ├── Balances
│   ├── Movements (Ledger)
│   ├── Valuation (FIFO)
│   └── Warehouses
├── Operations
│   ├── Transfers
│   ├── Adjustments
│   └── Stock Counts
├── Purchasing
│   ├── Purchase Orders
│   └── Goods Receipts (GRN)
├── Sales & Fulfilment
│   ├── Sales Orders
│   ├── Pick / Pack / Ship
│   ├── Reservations
│   └── Returns
├── Traceability
│   ├── Lots / Batches
│   ├── Serials
│   ├── Trace
│   └── Recalls
├── Reports
├── Integrations (SolaBooks)
│   ├── Outbox & Status
│   └── Journal Preview
└── Settings
    ├── Inventory Settings
    ├── Users & Permissions
    └── Audit Log
```

---

## 4. Screen list (target)

List + Detail for every document module, plus: Dashboard; Item list/detail
(+valuation drawer, movement drawer); Balances grid; Movements timeline
(+drawer); Valuation view; Warehouse cards/detail (+floor map); Transfers
(+layer view); Adjustments; Counts (blind/guided); PO; GRN; SO; Pick/Pack/Ship;
Reservations; Lots; Serials; Trace tree; Recall workspace; Reports catalog +
runner; Integration outbox/status + journal preview; Settings; Users &
permissions; Audit timeline; Global search.

---

## 5. Phased roadmap

### Phase 1 — Make inventory **value** legible (START HERE)
Highest value, read-only, zero risk. Users finally see **how much stock they
have, where, what it's worth, which FIFO layers make it up, and which movements
consumed which layers.**
- 1a. Valuation API (done ✅) + **consumed-layers** on movements (new, read-only).
- 1b. **Item detail upgrade**: warehouse **stock cards** (names, on-hand, avg, FIFO
  value), **valuation drawer** (layer stack + FIFO-vs-average + reconciliation).
- 1c. **Movement drawer + clearer history**: running balance, per-movement cost,
  and for OUT rows the **consumed layers**; reversal/transfer clearly labelled.
- 1d. Build the **Drawer + Card + Badge** primitives (foundation for everything).

### Phase 2 — Cost-layer-aware **documents** + dashboard value
- Transfer detail: source layers consumed → destination layers created; pre-post
  preview. Reversal: "what will be restored" layer-by-layer.
- Dashboard valuation + alert widgets (cards + charts).
- Close backend **test gaps**: adjustment & GRN posting tests.

### Phase 3 — Finance **preview/status** + safer counts + polish
- Integration **journal preview + status + retry** screens (outbox-only; define
  GRN/shipment journal payload schemas). **No Finance writes.**
- Blind/guided/freeze **stock count** workflow.
- Global search, i18n/RTL harness, empty-state pass.

---

## 6. Phase 1 — exact deliverables

**Backend (read-only, additive):**
1. ✅ `GET /items/{item}/valuation` — per-warehouse on-hand/avg/FIFO value, layer
   stack, qty reconciliation. (Shipped.)
2. **New** `GET /movements/{ledger}/consumed-layers` (or include on the movement
   payload): for an OUT ledger row, the `cost_layer_consumptions` rows
   (layer id, qty, unit cost) — so the UI can show "this issue consumed 10@\$5 +
   5@\$8". Gated `perm:inventory.view_ledger`.
3. Ensure movements payload exposes `balance_qty_after` / `balance_value_after`
   and resolved **warehouse name** (join, don't ship raw IDs).

**Frontend:**
4. **Drawer** primitive (right slide-over) + **Card** + extended **StatusBadge**.
5. **Item detail upgrade**: replace the raw stock table with **warehouse stock
   cards** (name, on-hand, reserved/available, avg cost, FIFO value, reconciled
   badge); add a **"Valuation" drawer** showing the layer stack + FIFO-vs-average.
6. **Movement drawer**: open from item/ledger row → full movement detail, running
   balance, and consumed layers for OUT rows; label reversals/transfers.
7. Resolve warehouse **names** everywhere they're currently IDs.

## 7. Phase 1 — tests
- **Backend (PHPUnit):**
  - ✅ `ItemValuationTest` (done): multi-layer order, FIFO-vs-average divergence,
    reconciliation, org-scope.
  - **New** `MovementConsumedLayersTest`: an OUT movement returns its consumed
    layers (10@\$5 + 5@\$8 for a 15-out over 10@\$5,10@\$8); IN movement returns
    none; org-scoped; permission-gated.
  - **New** `ItemMovementsPayloadTest`: movements include running balance and a
    resolved warehouse name.
- **Frontend:** introduce a minimal JS test setup (Vitest + React Testing
  Library) and cover the valuation drawer rendering layers + the reconciliation
  badge. (First JS tests in the app — also a Phase-1 deliverable.)

## 8. Phase 1 — files likely to change
- `app/Http/Controllers/Api/V1/ItemController.php` (movements payload: name +
  running balance; valuation done)
- `app/Http/Controllers/Api/V1/StockLedgerController.php` (consumed-layers, name)
- `routes/api.php` (consumed-layers route)
- `resources/js/solastock/components/ui.jsx` (Drawer, Card, badges)
- `resources/js/solastock/pages/ItemDetailPage.jsx` (cards + drawers)
- `resources/js/solastock/pages/LedgerPage.jsx` (movement drawer)
- `resources/js/solastock/services/api.js` (new endpoints)
- `tests/Feature/Stock/MovementConsumedLayersTest.php`,
  `tests/Feature/Stock/ItemMovementsPayloadTest.php`
- (new) `package.json` + Vitest config + first `*.test.jsx`
- CSS for drawer/cards

## 9. Risks
- **Frontend build on a live tree:** `/var/www/html/solavel-inventory` is
  production; built JS assets must be compiled and deployed carefully. Node 18 vs
  Vite's Node ≥20.19 requirement is an open host issue — verify the build host
  before shipping bundle changes. Until then, keep changes buildable and test the
  asset pipeline on staging.
- **Adding a chart lib** increases bundle size — keep it lean (tree-shakeable).
- **i18n refactor** touches many strings — stage it; don't block Phase 1 on it.
- **Warehouse-name joins** must stay org-scoped (don't bypass the scope for a join).

## 10. What NOT to touch
- The **immutable ledger** (append-only; never add update/delete paths).
- **FIFO layer fidelity** (per-layer transfer/reversal already correct).
- **Tenant/org isolation** (fail-closed; org_id immutable on update).
- **Finance/SolaBooks tables** — outbox/preview/status/retry only, no direct writes.
- **DB privilege separation** Step 3 (parked; no DB-user/.env changes).
- Keep the **full suite green** after every change.

---

**Bottom line:** SolaStock has a strong engine and a thin product skin. Phase 1
turns the most valuable hidden data (inventory value + FIFO layers + movement
truth) into a genuinely good, drawer-based, visual experience — read-only and
safe — and stands up the design-system primitives (drawer/card/badge) everything
else will reuse.
