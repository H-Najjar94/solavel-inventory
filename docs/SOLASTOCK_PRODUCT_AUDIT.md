# SolaStock — full product/UX audit

Audit date 2026-06-14. Method: production error logs + request-rule vs column-type
analysis + per-page React inspection + live tenant schema checks. Audit only — no
code changed. Honest: "good" only where it's genuinely good for users.

The two bugs already fixed (Items: blank→null→NOT-NULL price; Warehouses:
address array→string column) were not one-offs. The **same classes** recur across
the app. This audit names every instance.

---

## 0. Systemic findings (the patterns behind every page)

1. **Create breaks from the real UI payload** (proven in production logs):
   - `items.purchase_price cannot be null` — FIXED.
   - `warehouses` Array→string (address) — FIXED.
   - `opening_stock_entries.entry_number has no default (1364)` — **OPEN** (×4 in logs).
   - `purchase_orders` insert failures — **OPEN** (×8 in logs — most-failed create).
   - `goods_receipts` table missing / FK fail on some tenants — **migration drift**.
2. **Manual document numbers.** Every document create (`entry_number`, `po_number`,
   `grn_number`, `adjustment_number`, `count_number`, `transfer_number`,
   `order_number`) is `required` and **typed by the user** — no auto-generation.
   This is both a UX anti-pattern (users shouldn't invent unique numbers) and a
   failure source (blank → required error or null → 1364).
3. **Raw IDs shown to users — ~38 of ~40 pages** render `#{warehouse_id}` /
   `#{item_id}` / `#{customer_id}` instead of names. Pervasive.
4. **Generic "save failed."** 12 forms show inline field errors for 422s but fall
   back to a generic toast for 500s — exactly when a real bug occurs, the user
   sees nothing actionable.
5. **Tenant schema drift.** Tenants provisioned at different times have different
   tables (real "table doesn't exist" / "unknown column warehouse_id|bin_id"
   errors). The 2 live inventory tenants (000002, 008) are now complete; the
   risk persists for newly-activated/older tenants and is invisible until a user
   hits it.
6. **Weak detail pages / empty tabs.** Item detail (now redesigned) and Warehouse
   detail (banner added) were the only two touched; the rest are thin admin
   tables with placeholder tabs.
7. **Media only on Items/Warehouses.** No other entity has images (fine — most
   don't need them).

Legend: ✅ good · 🟡 works but admin-grade/weak UX · 🟦 partial · 🔴 broken/bug · ❌ missing

---

## 1. Full page inventory

| # | Page | Status | Main problems | Backend | FE good? | Tests | Priority |
|---|---|---|---|---|---|---|---|
| 1 | Dashboard | 🟡 | raw IDs in recent activity; KPIs ok but no valuation/alert tiles | ✅ | no | iso ✅ | P3 |
| 2 | Items list | ✅ (just done) | thumbnail+search ok | ✅ | ✅ | ✅ | — |
| 3 | Item create/edit | ✅ (fixed+redesigned) | — | ✅ | ✅ | ✅ | — |
| 4 | Item detail | ✅ (redesigned) | — | ✅ | ✅ | ✅ | — |
| 5 | Warehouses list | ✅ (banner thumb) | — | ✅ | ✅ | ✅ | — |
| 6 | Warehouse create/edit | ✅ (fixed+redesigned) | — | ✅ | ✅ | ✅ | — |
| 7 | Warehouse detail | 🟡 | banner added but body still thin admin tables; raw item IDs in stock; many tabs | ✅ | partial | img ✅ | **P3 (next)** |
| 8 | Stock balances | 🟡 | raw `#item_id`/`#warehouse_id`; no names; no totals row; no drawer | ✅ | no | lock ✅ | P4 |
| 9 | Ledger / movements | 🟡 | page is a flat table; movement drawer/consumed-layers exist on Item but not here; raw IDs | ✅ | partial | engine ✅ | P4 |
| 10 | Transfers list/create/detail | 🔴/🟡 | create needs `transfer_number` (manual); detail doesn't show cost-layer movement; raw IDs | ✅ | no | fifo ✅ | **P1/P3** |
| 11 | Adjustments list/create/detail | 🔴/🟡 | `adjustment_number` manual; no value-impact display; raw IDs | ✅ | no | none 🟡 | **P1** |
| 12 | Stock counts list/create/detail | 🟡 | `count_number` manual; no blind/guided flow; raw IDs | ✅ | no | live-qty ✅ | P2 |
| 13 | Purchase orders / GRN | 🔴 | **8 PO insert failures in prod**; `po_number`/`grn_number` manual; goods_receipts migration drift; raw IDs | ✅ | no | none 🟡 | **P1 (highest)** |
| 14 | Sales orders / pick / pack / ship | 🟡 | `order_number` manual; pipeline status weak; raw IDs (customer/item) | ✅ | no | foundation ✅ | P2 |
| 15 | Returns | 🟡 | raw IDs; thin | ✅ | no | foundation ✅ | P2 |
| 16 | Lots / batches | 🟡 | raw IDs; functional | ✅ | partial | fefo ✅ | P4 |
| 17 | Serials | 🟡 | raw IDs; functional | ✅ | partial | serial ✅ | P4 |
| 18 | Traceability / recall | 🟡 | no visual trace tree; raw IDs | ✅ | no | trace ✅ | P5 |
| 19 | Reports | 🟡 | rich registry but flat output, no drill-down, raw IDs in some | ✅ | no | registry ✅ | P5 |
| 20 | Integrations / outbox | 🟡 | `integration_settings` missing-table errors on some tenants; no journal preview UI | ✅ (outbox-only, safe) | no | foundation ✅ | P5 |
| 21 | Settings | 🟡 | thin; no per-setting help | ✅ | no | none 🟡 | P5 |
| 22 | Users / permissions | 🟡 | no view of effective permissions; assignment lives in central | ✅ | no | perm ✅ | P5 |

---

## 2. Bug list (evidence-based)

**Broken create/edit (from production logs):**
- **B1 (P1, highest):** Purchase Order create — **8 insert failures** in prod. Cause TBD (reproduce like Items/Warehouses).
- **B2 (P1):** Opening Stock create — `entry_number doesn't have a default (1364)` ×4. Manual number + a path that drops it.
- **B3 (P1):** Document-number class — every document create (`*_number`) is manual + `required` + DB no-default ⇒ blank/missing → fail. Affects Opening Stock, PO, GRN, Adjustment, Count, Transfer, SO.
- **B4 (migration):** `goods_receipts` / `integration_settings` "table doesn't exist" + FK fail on some tenants (historical on 000002, still a drift risk). Verify ALL live tenants are migrated to head before those features are used.

**Validation / payload:**
- **B5:** Generic "save failed" on 500s (12 forms) — server errors aren't surfaced field-wise.
- **B6 (watch):** Any create-request with `'x' => ['array']` mapped to a non-JSON-cast column repeats the Warehouse address bug. Audit each: Adjustment/Count/Transfer/SO line payloads, any JSON header fields.
- **B7:** `toDateTimeString() on string` in logs — a date field received a string where Carbon expected; find and guard.

**Raw IDs (B8, P3):** ~38 pages. Highest-impact: Balances, Ledger, Warehouse detail stock, all document details (item/warehouse/customer/supplier shown as `#id`).

**Media gaps:** none beyond Items/Warehouses (acceptable).

**Empty/weak tabs (B9):** Warehouse detail (zones/bins/audit thin); document details generally one flat table.

---

## 3. Design system / redesign plan

**Standard list page:** thumbnail (where media exists) · fast search · status badge ·
**resolved names not IDs** · key value column (qty/value) · row→detail. Later: filters + saved views.

**Standard create/edit page (the Item/Warehouse pattern — now the template):**
two-column; header with title + status + Save/Cancel + **error summary**; grouped
section cards; **inline field errors**; sidebar with preview card + setup checklist;
loading/disabled save; **auto-generated document numbers** (read-only, server-issued)
for all document forms.

**Standard detail page (the Item pattern):** hero (image/name/status/key meta +
quick actions) · 4–5 grouped tabs max · rich Overview with metric cards + cards/
drawers · **no raw IDs** · strong empty states · no empty top-level tabs.

**Patterns:** Drawer (movement/valuation/line detail) · Card (KPI/warehouse/layer) ·
Badge (status/sync/recon) · private media (item square, warehouse banner).

**Sidebar/menu:** the grouped structure from SOLASTOCK_FEATURE_MAP (Catalog / Stock /
Operations / Purchasing / Sales / Traceability / Reports / Integrations / Settings).

---

## 4. Prioritized roadmap

**Phase A — Fix broken workflows (P1).** PO create (B1), Opening Stock create (B2),
the document-number class (B3), audit every document create against the
blank→null→NOT-NULL and array→string patterns (B6), verify live-tenant migrations
(B4). Every fix with a request-payload regression test.

**Phase B — Unify create/edit design (P2).** Convert all document forms to the
Item/Warehouse template; **auto-generate document numbers server-side** (read-only
in UI); resolved name pickers (not ID inputs).

**Phase C — Redesign detail pages (P3).** Warehouse detail first (hero + KPI tiles +
named stock table + Media), then document details (line tables with names, status,
value-impact, drawers).

**Phase D — Lists/tables (P4).** Resolve names everywhere; thumbnails; status badges;
totals rows; filters; (later) saved views. Kills the raw-ID problem app-wide.

**Phase E — Reports/operations polish (P5).** Visual reports + drill-down; ledger
page gets the movement/consumed-layer drawers; valuation dashboards; traceability
tree; integration journal preview (outbox-only).

---

## 5. Recommended next 5 slices (in order)

### Slice 1 — Fix Purchase Order + Opening Stock create (P1, highest)
- **Deliverables:** reproduce both from real payloads; fix root causes (likely the
  document-number/required + blank-numeric/array-cast patterns); clear inline errors.
- **Files:** `StorePurchaseOrderRequest`, `StoreOpeningStockRequest`, their services/
  models, possibly the form pages.
- **Tests:** request-payload regression for PO + Opening Stock create (UI-style payload).
- **Migration/build notes:** none expected; FE rebuild if forms change.
- **Risks:** PO/SO tables renamed to `inventory_*` (migration 100014) — confirm model table names; stock-writing services must keep ledger/FIFO intact.

### Slice 2 — Document-number auto-generation (P1)
- **Deliverables:** server-issued numbers (e.g. `PO-000123`) for all 7 document
  creates; UI shows them read-only; blank can never break a create again.
- **Files:** the 7 Store*Request/services (a shared numbering helper), the 7 form pages.
- **Tests:** create without a typed number succeeds + number is unique per org.
- **Notes:** read-only numbering; no schema change if a numbering table/column exists, else a small additive migration (flag for live backfill).
- **Risks:** uniqueness under concurrency — generate inside the create transaction.

### Slice 3 — Live-tenant migration audit + guard (P1)
- **Deliverables:** a command/report listing each live inventory tenant's missing
  tables vs head; backfill the gaps (additive); document the deploy step.
- **Files:** a small artisan command (read-only report) + run the additive migrations.
- **Tests:** the report asserts head-parity on the test tenants.
- **Notes:** **must run before** PO/GRN/integration features are used on a tenant.
- **Risks:** none if additive/idempotent; do NOT touch DB users/grants.

### Slice 4 — Warehouse detail redesign (P3)
- **Deliverables:** banner hero + KPI tiles (stock value, item count, low-stock,
  zones/bins) + named stock table + keep Media; small read-only `show` enrichment
  (total value, item count, item names).
- **Files:** `WarehouseController@show` (read-only), `WarehouseDetailPage.jsx`, CSS.
- **Tests:** show returns value/count + resolved names; org-scoped.
- **Risks:** read-only; none to FIFO/ledger.

### Slice 5 — App-wide "names not IDs" pass (P3/P4)
- **Deliverables:** resolve item/warehouse/customer/supplier names in Balances,
  Ledger, and all document detail/line tables (read-only joins, org-scoped).
- **Files:** the relevant controllers (eager-load names) + list/detail pages.
- **Tests:** payloads include resolved names; org-scoped; no N+1.
- **Risks:** keep joins org-scoped (don't bypass the scope).

---

## Guardrails (apply to every slice)
No Step 3 / DB users / grants / `.env`; no Finance-table writes (outbox/preview only);
no FIFO change unless a tested bug; additive migrations only (flag live backfill);
keep the suite green; build via Node 20.20.2 (backup `public/build` first).
