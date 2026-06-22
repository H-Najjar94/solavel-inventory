# SolaStock ↔ Finance Integration Plan

**Status:** Design only.
**Principle:** SolaStock owns **inventory operations**; Finance (Solabooks) owns **accounting**. They communicate through a stable, idempotent contract — never by sharing a database or duplicating each other's write logic.

---

## 1. Ownership Split (Part 4 decision)

### Finance (Solabooks) owns
- Invoices, bills, sales receipts
- Journal entries (the general ledger)
- VAT / tax engine
- AR / AP
- Financial reports (P&L, balance sheet, trial balance, tax returns)
- Customer / supplier **accounting balances**
- Chart of accounts (system of record for accounts)

### SolaStock owns
- The canonical **stock ledger** (the only stock truth)
- Warehouses, zones, bins
- Receiving (PO → GRN), partial receiving, backorders
- Picking, packing, shipping
- Stock counts (cycle + full), stock transfers
- Item availability / reservations / allocation
- All inventory operations
- Item-movement and inventory reports (valuation, item ledger, aging, dead/fast-moving, COGS detail)

### Shared / synced (with a defined system-of-record each)
| Entity | System of record | Synced to |
|---|---|---|
| Items / products | **SolaStock** (operational) | Finance gets item refs for invoice/bill lines |
| Customers | Central / Finance (accounting identity) | SolaStock holds operational copy (`central_customer_ref`) |
| Suppliers | Central / Finance | SolaStock holds operational copy (`central_supplier_ref`) |
| Accounting mappings | **SolaStock `accounting_mappings`** points at | Finance account refs |
| Purchase/sales document refs | Each app owns its own; cross-linked by ref | both |

> Rule of thumb: if it changes a balance sheet / P&L number, Finance owns it. If it changes a bin quantity, SolaStock owns it. COGS is *computed* by SolaStock (it knows cost layers) and *posted* by Finance (it owns the GL).

---

## 2. What we keep from the Finance audit

Reuse these **contracts/concepts** (not the code):
- **Account-defaults resolver** → becomes SolaStock `accounting_mappings` (org/category/item scope) holding Finance account *refs*.
- **JE posting recipes** (Dr/Cr per document) → become the payloads SolaStock sends; Finance executes them.
- **`source_type/source_id` provenance + `posted_guard_key` idempotency** → preserved on both sides so a posting is traceable and exactly-once.
- **Config knobs** (`allow_negative_inventory`, `auto_post_cogs`, account codes 1300/5001/5002/3501/4303/6804) → mirrored as SolaStock settings + mappings.

---

## 3. Integration Contract (events → postings)

SolaStock emits **business events** when documents post. Finance translates each into a journal entry. Transport: a **transactional outbox** (`finance_posting_outbox`) drained by a worker calling Finance's posting API (or a queue/webhook). The accounting Dr/Cr below mirrors what Finance already does today (audit §H), so Finance's existing posting services can be reused.

| SolaStock event | Stock effect (ledger) | Finance JE (Dr / Cr) | Notes |
|---|---|---|---|
| `grn.posted` (goods receipt) | IN at cost | Dr Inventory asset / Cr GR-IR (or AP if no bill yet) | cost from GRN line |
| `bill.matched` (Finance-owned) | — | Dr GR-IR / Cr AP | three-way match PO/GRN/Bill in Finance |
| `shipment.posted` (sales issue) | OUT, COGS computed | Dr COGS / Cr Inventory asset | SolaStock supplies `total_cost` from cost layers |
| `sales_return.posted` (restock) | IN | Dr Inventory / Cr COGS | reverse COGS |
| `supplier_return.posted` | OUT | Dr AP (or GR-IR) / Cr Inventory | |
| `adjustment.posted` (increase) | IN | Dr Inventory / Cr Adjustment-gain (4303) | |
| `adjustment.posted` (decrease) | OUT | Dr Adjustment-loss (6804) / Cr Inventory | |
| `count.posted` (variance) | IN/OUT per line | same as adjustment | cycle/full count |
| `transfer.posted` | OUT src + IN dest | usually no JE (same asset acct) or inter-branch JE | configurable |
| `landed_cost.posted` | cost-only (no qty) | Dr Inventory / Cr Clearing(freight/duty) | adjusts cost layers |
| `opening_stock.posted` | IN | Dr Inventory / Cr Opening-equity (3501) | migration vehicle |

Each payload includes: `organization_id`, `idempotency_key`, `source_type`, `source_id`, item/account `*_ref`s, quantities, and amounts. Finance returns a `journal_ref` which SolaStock stores on the document (`journal_ref` column) — completing the round-trip link.

### COGS responsibility
- **SolaStock computes COGS** (it owns cost layers / FIFO / average) and includes `total_cost` in `shipment.posted`.
- **Finance posts COGS** to the GL. This is cleaner than Finance recomputing cost from stale aggregate data (the audit's weak spot).

---

## 4. Data Sync

- **Items:** SolaStock is operational SoR. On item create/update, emit `item.upserted` so Finance can reference it on invoice/bill lines (by `item_ref`). Finance does not need full item data — just ref + name + tax/account hints.
- **Customers/Suppliers:** Central/Finance is identity SoR. SolaStock pulls/links via `central_*_ref`; a periodic sync (or webhook) keeps operational copies fresh.
- **Accounts/COA:** Finance is SoR. SolaStock stores only **refs** in `accounting_mappings`; never mirrors the chart of accounts.
- **Tax/VAT:** Finance owns the tax engine; SolaStock stores `tax_code` on items/lines and passes them through.

---

## 5. Reliability & Correctness

- **Transactional outbox:** outbox row written in the SAME DB transaction as the ledger append → no lost or phantom postings.
- **Idempotency end-to-end:** `idempotency_key`/`posted_guard_key` on every event; Finance dedupes; retries are safe.
- **Decoupling:** Finance downtime never blocks stock operations; outbox drains when Finance is back.
- **Reconciliation job:** nightly compare SolaStock inventory valuation vs Finance inventory-asset GL balance per org; alert on drift.
- **No shared writes:** SolaStock never writes Finance tables and vice-versa. The only coupling is the event contract.

---

## 6. Auth / SSO

- SolaStock uses **Solavel central SSO** (same pattern as `solavel-projects`): `CENTRAL_APP_URL`, central login bounce, Sanctum tokens for API.
- Org context resolved from the SSO session at request boundary → maps central org id to local `organizations.id`.
- Already scaffolded: `.env` has `APP_URL/ASSET_URL=https://solavel.com/inventory`, `SESSION_COOKIE=solavel_inventory_session`, `CENTRAL_APP_URL=https://solavel.com`.

---

## 7. Migration Coexistence (during cutover)

- For an org migrated to SolaStock, **freeze Finance's mini-inventory write paths** (make them read-only) to avoid two writers across systems.
- Finance keeps displaying inventory *financial* values from its GL (fed by SolaStock postings).
- Finance's existing inventory UI can link out to SolaStock for operations, or be retired per org.
- Non-migrated orgs keep using Finance inventory unchanged until migrated.

---

## 8. Integration Phasing

1. **Phase A — none.** SolaStock runs standalone with seeded/mock then real data; no Finance calls. (Matches current demo.)
2. **Phase B — read sync.** Pull customers/suppliers/accounts refs from central/Finance; populate `accounting_mappings`.
3. **Phase C — posting (one event at a time).** Start with `opening_stock.posted` (migration), then `grn.posted`, then `shipment.posted`/COGS, then adjustments/counts/returns/landed cost. Each behind a feature flag, validated against Finance GL before enabling the next.
4. **Phase D — full cutover.** Freeze Finance inventory writes for the org; SolaStock is system-of-record; reconciliation job live.
