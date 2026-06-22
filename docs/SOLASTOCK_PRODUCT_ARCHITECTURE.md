# SolaStock — Product Architecture

**App name:** SolaStock
**Repo/folder:** `solavel-inventory`
**Live mount:** `https://solavel.com/inventory` (main page `/inventory/dashboard`)
**Primary color:** `#e09921` (amber)
**Status:** Design only — no code yet.

Goal: the best inventory app on the market — cleaner and bigger than Zoho Inventory, QuickBooks inventory, and Odoo inventory — built standalone but integration-ready with Solavel central auth/SSO and Solabooks finance.

---

## 1. Architectural North Star

> **One canonical, append-only stock ledger. Exactly one writer. Everything else is a projection of it.**

Every design decision below serves that principle, which directly fixes the Finance audit's worst findings (two un-reconciled truths, seven writers, mutable posted docs).

The five laws (enforced in code and DB):

1. **Single ledger.** All stock change flows through `stock_ledger` (append-only, immutable rows).
2. **Single writer.** Only `StockLedgerService` may append. No controller, observer, or other service writes stock.
3. **Every document posts to the ledger.** A document (receipt, issue, transfer, adjustment, count, shipment, etc.) is just a typed header+lines that, when *posted*, emits ledger entries carrying `source_type/source_id/source_line_id`.
4. **Posted is immutable.** Posted documents are locked at the model and DB layer. Corrections create reversal or new documents — never edits.
5. **Balances are derived.** `stock_balances` is a materialized projection (maintained transactionally by the single writer); reports may read it for speed but it can always be rebuilt from the ledger. The ledger is the truth.

---

## 2. System Architecture

```
┌────────────────────────────────────────────────────────────┐
│ Browser (SolaStock SPA)                                      │
│  React 18 + Vite, Tailwind, amber #e09921, light/dark,       │
│  draggable dashboard, tablet-smooth interactions             │
└───────────────▲───────────────────────────┬─────────────────┘
                │ JSON API (Sanctum)         │ @vite assets
┌───────────────┴───────────────────────────▼─────────────────┐
│ Laravel (solavel-inventory)                                   │
│  HTTP: API controllers + Blade shell (/inventory/dashboard)   │
│  Application services (use-cases)                              │
│   ├─ StockLedgerService   ← THE ONLY STOCK WRITER             │
│   ├─ CostingEngine (avg / FIFO / standard, strategy)          │
│   ├─ Document posting services (Receipt/Issue/Transfer/…)     │
│   ├─ ReservationService, AllocationService                    │
│   └─ ReportingService (reads ledger / balances)               │
│  Domain models (Eloquent) + policies                          │
│  Tenancy: organization_id everywhere + global scope           │
│  Audit log (every state change)                               │
│  Integration: Finance contract (events/outbox → API)          │
└───────────────┬───────────────────────────┬─────────────────┘
                │                            │
        ┌───────▼────────┐          ┌────────▼─────────┐
        │ Solavel central │          │ Solabooks finance │
        │ SSO / identity  │          │ JE posting, AR/AP │
        └─────────────────┘          └──────────────────┘
```

- **Standalone first:** SolaStock runs and is fully usable with mock/seeded data and no external dependency (matches current Phase-1 demo). Integrations are additive and feature-flagged.
- **Integration-ready:** central SSO (mirrors `solavel-projects`: `APP_URL`/`SESSION_COOKIE`/`CENTRAL_APP_URL` already configured), and a Finance posting contract via a transactional **outbox** (see Integration Plan) so accounting never blocks stock ops.

---

## 3. Tech Stack

| Layer | Choice | Why |
|---|---|---|
| Backend | Laravel 11, PHP 8.4 | Matches Solavel suite |
| Auth | Laravel Sanctum + Solavel central SSO | Same pattern as projects/finance |
| Frontend | React 18 + Vite + `@vitejs/plugin-react` | Phase-2 plan already written; SPA mounted in Blade `/inventory/dashboard` |
| Styling | Tailwind, design tokens, primary `#e09921` | Light/dark via `data-theme`; tablet-smooth |
| Dashboard | `dnd-kit` (or equivalent) for draggable widgets, persisted layout | Customizable cards requirement |
| Charts | Lightweight SVG charts (already present in demo: AreaChart, Donut, Gauge, Sparkline) | No heavy dep |
| State/data | TanStack Query against JSON API | Cache, optimistic UI |
| DB | MySQL/MariaDB (tenant), `decimal(18,4)` quantities | Fix integer-qty bug from Finance |
| Async | Laravel queue (jobs) for Finance posting, reports, AI alerts | Decouple |
| Tenancy | `organization_id` column + global scope on every table | Consistent isolation (Finance was mixed) |

---

## 4. Multi-Tenancy & Isolation

- **Single mechanism:** every table has `organization_id`; every model uses a `BelongsToOrganization` global scope (auto-set on create, auto-filter on read). No mixed connection-vs-column ambiguity (the Finance pitfall).
- **Context:** `OrganizationContext` resolved from the SSO session / token at request boundary; jobs carry the org id explicitly.
- **Authorization:** Laravel policies per resource; warehouse-level and zone-level permissions (carry forward Finance's per-user zone permission idea).
- **Production-grade SaaS:** rate limiting, per-org settings, soft deletes + audit log, signed integration webhooks, idempotency keys on all posting endpoints.

---

## 5. Core Modules (product scope)

Each module is a thin UI + API over the canonical ledger and its documents.

### 5.1 Dashboard
Widgets: inventory value, low stock, out of stock, stock accuracy (count vs system), pending purchase orders, sales-order fulfillment, warehouse capacity, stock-movement activity, AI/smart alerts. **Draggable/reorderable, resizable, per-user persisted layout** (`dashboard_layouts` table). Light/dark. Already prototyped in the Phase-1 demo (`screens-dashboard.jsx`, `screens-widgets.jsx`).

### 5.2 Items
Products + services; variants; SKU + multi-barcode; categories (nested) and brands; images; serial numbers; batch/lot with **expiry** (fixes Finance gap); units of measure + **unit conversions** (Finance gap); reorder point/qty; preferred supplier + supplier item codes + supplier price lists; purchase & sales prices (with price tiers); tax mapping; inventory/non-inventory/service flag; costing-method per item.

### 5.3 Warehouses
Multiple warehouses; zones; bins (racks/shelves); capacity; stock-by-warehouse; transfers (as posted documents); warehouse users/permissions (incl. zone-level). 2D bin map now (demo has `floor.jsx`), 3D later.

### 5.4 Stock Operations
Receipt, issue, adjustment, transfer, cycle count, full stock take, landed cost, reservation, allocation, pick/pack/ship. **All are documents that post to the one ledger.**

### 5.5 Purchasing
Purchase orders; goods-received notes (partial receiving, backorders); expected arrival dates; supplier price lists; supplier bills handed to Finance later. (Finance only had a weak internal "purchase request"; SolaStock owns real PO→GRN.)

### 5.6 Sales / Fulfillment
Sales orders; picking; packing; shipping; delivery status; partial fulfillment; returns; reserved vs available stock. (Finance has none of this — net-new.)

### 5.7 Partners
Suppliers, customers; supplier item codes; customer price lists (later). Synced with Finance as the accounting system-of-record for balances.

### 5.8 Reports (all read the canonical ledger or its balance projection)
Inventory valuation, **item ledger (running balance)**, stock movement, warehouse stock, low stock, **dead stock**, **fast-moving**, **profitability by item**, COGS, **reorder report**, **stock aging**, **batch/expiry report**. (The bolded ones are missing in Finance.)

### 5.9 Settings
Warehouses, units, categories, numbering schemes, barcode settings, costing method, negative-stock policy, approval rules, integrations, accounting mapping.

### 5.10 Integrations (later, feature-flagged)
Solavel central SSO; Solabooks finance (sales invoices, bills, customer/supplier sync, chart-of-accounts mapping, JE posting, tax/VAT mapping).

---

## 6. The Canonical Stock Engine (the heart)

### 6.1 Documents → Ledger
Every stock-affecting document type implements one interface:

```
interface PostsToStockLedger {
    public function buildLedgerEntries(): array; // pure: returns intended movements
}
```

Posting a document = `StockLedgerService::post($document)`:
1. `lockForUpdate` the affected `(item, warehouse, lot/serial)` balance rows.
2. Validate (negative-stock policy, reservations, lot/serial availability).
3. Compute cost via `CostingEngine` (per-item strategy: weighted-avg | FIFO | standard).
4. Append immutable `stock_ledger` rows with `source_type/source_id/source_line_id`, signed `quantity` (`decimal(18,4)`), `unit_cost`, `total_cost`, running fields.
5. Update `stock_balances` projection in the same transaction.
6. Write `inventory_audit_logs` + enqueue Finance posting (outbox).
All inside one DB transaction with idempotency key. **No other code path may write stock.**

### 6.2 Costing
Strategy pattern, honored per item (Finance defined the config but never honored it):
- **Weighted average** (default, parity with Finance).
- **FIFO** using lot layers (`lots` + ledger consumption order).
- **Standard cost** with variance postings.
COGS is computed at issue/ship time and emitted to Finance via the contract.

### 6.3 Reservations & Availability
`available = on_hand − reserved`. Sales orders/allocations create `reservations` (soft holds) that the ledger respects but that are not themselves stock movements until pick/ship posts.

### 6.4 Immutability & corrections
Posted documents: status `draft → posted [→ reversed]`, `posted_guard_key` (idempotency), `journal_ref`. Model observers + a DB-level guard reject updates/deletes on posted rows. Corrections = reversal document (emits inverse ledger entries) or a new adjustment.

### 6.5 Audit
`inventory_audit_logs` records actor, org, action, before/after snapshot, document ref for every state transition — first-class, not a repair afterthought.

---

## 7. Frontend Architecture (UX)

- **Single React SPA** (one UI, unlike Finance's two) mounted at `/inventory/dashboard`; routes are client-side within the SPA, server provides JSON.
- **Design system:** amber `#e09921` primary; tokens drive light/dark via `data-theme`; "tablet-smooth" = generous hit targets, momentum scrolling, optimistic updates, skeleton loaders, 60fps drag.
- **Draggable dashboard:** grid of widget cards, drag to reorder/resize, layout persisted per user+org; widget catalog/gallery (demo already has `WidgetGallery`).
- **Smart document selectors:** item picker with inline search + live availability + cost (fixes a Finance limitation).
- **Reports:** interactive, drillable into the item ledger.
- **Phase-2 conversion** of the current CDN/Babel demo to Vite modules is already specced in `PHASE2-VITE-REACT-PLAN.md`; this architecture assumes that conversion as the UI baseline.

---

## 8. Non-Functional Requirements

| Concern | Approach |
|---|---|
| Performance | `stock_balances` projection for hot reads; composite indexes `(organization_id, item_id, warehouse_id, moved_at)`; cursor pagination; queue heavy reports |
| Concurrency | `lockForUpdate` on balance rows in the single writer; idempotency keys |
| Correctness | One writer, append-only ledger, decimal quantities, posted-immutability, transactional projection |
| Auditability | `inventory_audit_logs` + immutable ledger = full history |
| Security | Sanctum, policies, signed integration webhooks, per-org isolation, rate limits |
| Reliability | Outbox pattern for Finance posting (stock never blocked by accounting downtime) |
| Observability | Structured logs, posting metrics, ledger-vs-balance integrity check job (should always pass by design) |

---

## 9. How SolaStock beats the incumbents

| Capability | Zoho | QuickBooks | Odoo | **SolaStock target** |
|---|---|---|---|---|
| Single canonical ledger | partial | weak | yes | **yes, enforced single-writer** |
| FIFO + avg + standard, per item | avg/FIFO | avg | yes | **all three, strategy per item** |
| Bin/zone + visual map | bins | no | bins | **bins + 2D map now, 3D later** |
| Draggable custom dashboard | limited | limited | dashboards | **fully draggable/resizable, per-user** |
| Batch/lot + expiry reports | yes | limited | yes | **yes, first-class** |
| Reservations/allocation/pick-pack-ship | yes | limited | yes | **yes** |
| Native accounting integration | Zoho Books | yes | yes | **Solabooks contract, outbox-decoupled** |
| Tablet-smooth modern UI | ok | dated | functional | **best-in-class React UI** |
| Multi-tenant SaaS isolation | yes | n/a | yes | **strict org_id + audit** |

The differentiators: **provably-consistent stock engine**, **per-item costing strategies**, **customizable modern UX**, and **clean accounting decoupling**.

---

## 10. Module Ownership (summary; full split in §4 doc)

- **SolaStock owns:** stock ledger, warehouses, receiving, picking/packing/shipping, counts, transfers, availability, all inventory operations and item-movement reports.
- **Finance owns:** invoices, bills, sales receipts, JEs, VAT/tax, AR/AP, financial reports, customer/supplier accounting balances.
- **Shared/synced:** items/products, customers, suppliers, accounting mappings, purchase/sales document references.

See `SOLASTOCK_DATABASE_DESIGN.md` for tables and `SOLASTOCK_FINANCE_INTEGRATION_PLAN.md` for the contract.
