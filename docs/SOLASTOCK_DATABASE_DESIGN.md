# SolaStock — Database Design

**Status:** Design only — no migrations yet.
**Engine:** MySQL/MariaDB, InnoDB, utf8mb4.
**Conventions below apply to every table** unless noted.

---

## 0. Global Conventions (read first)

Applied to **every** table:

- **PK:** `id` BIGINT unsigned auto-increment.
- **Tenant isolation:** `organization_id` BIGINT unsigned, **NOT NULL**, indexed; enforced by a `BelongsToOrganization` global scope (auto-set on create, auto-filter on read). This is the single, consistent isolation mechanism — fixing the Finance app's mixed connection-vs-column approach.
- **Quantities:** `decimal(18,4)` — never integer (Finance's `inventory_movements.quantity` was INTEGER, truncating fractional units 🔴). 
- **Money/cost:** `decimal(18,4)` for unit cost, `decimal(18,2)` for totals/value.
- **Audit columns:** `created_by`, `updated_by` (nullable BIGINT), `created_at`, `updated_at`. Documents add `posted_by/at`, `reversed_by/at`.
- **Soft deletes:** `deleted_at` on master-data and document tables; **NOT** on `stock_ledger` (append-only, immutable) or `inventory_audit_logs`.
- **Money currency:** assume org base currency in v1; `currency_code` reserved on documents for later.
- **Composite tenant indexes:** every frequently-filtered query leads with `organization_id`.
- **Idempotency:** posting endpoints/documents carry a unique `posted_guard_key`.

**Document status enum** (all posted documents): `draft | posted | reversed | cancelled`.
Posted/reversed rows are immutable (model observer + app guard).

---

## 1. Tenant / Context

### `organizations` (reference only)
- **Purpose:** local mirror/reference of the central org id (source of truth = Solavel central). SolaStock does not own org lifecycle; it stores `central_organization_id`, `name`, `slug`, settings cache.
- **Key cols:** `central_organization_id` (unique), `name`, `base_currency`, `settings` (json).
- **Notes:** all other tables' `organization_id` FK → `organizations.id` (local id), mapped from central at SSO time.

### `inventory_settings`
- **Purpose:** per-org policy: default costing method, `allow_negative_stock` (bool), numbering schemes, barcode settings, approval rules.
- **Key cols:** `organization_id` (unique), `default_costing_method` enum(`average`,`fifo`,`standard`), `allow_negative_stock`, `numbering` (json), `barcode` (json), `approvals` (json).
- **Unique:** `(organization_id)`.

---

## 2. Warehouse Topology

### `warehouses`
- **Purpose:** physical/logical warehouses.
- **Key cols:** `code`, `name`, `type` (warehouse/retail/transit/virtual), `address` (json), `max_capacity_units` `decimal(18,4)`, `is_active`.
- **Indexes:** `(organization_id, is_active)`.
- **Unique:** `(organization_id, code)`.

### `warehouse_zones`
- **Purpose:** zones inside a warehouse.
- **Key cols:** `warehouse_id` FK, `code`, `name`, `keeper_user_id` (nullable), `is_active`.
- **Indexes:** `(organization_id, warehouse_id)`.
- **Unique:** `(warehouse_id, code)`.

### `warehouse_bins`
- **Purpose:** bins/racks/shelves (the demo's "cells").
- **Key cols:** `zone_id` FK, `warehouse_id` FK (denormalized for fast filter), `code`, `name`, `coords` (json, for 2D/3D map), `capacity` `decimal(18,4)`, `is_active`.
- **Indexes:** `(organization_id, warehouse_id, zone_id)`.
- **Unique:** `(zone_id, code)`.

---

## 3. Item Master

### `item_categories`
- **Purpose:** nested category tree.
- **Key cols:** `parent_id` (nullable self-FK), `name`, `path`/`level`, `is_active`.
- **Indexes:** `(organization_id, parent_id)`. **Unique:** `(organization_id, parent_id, name)`.

### `item_brands`
- **Key cols:** `name`, `is_active`. **Unique:** `(organization_id, name)`.

### `items`
- **Purpose:** product/service master (variant parent or standalone).
- **Key cols:** `sku` , `name`, `description`, `item_type` enum(`inventory`,`non_inventory`,`service`), `tracking_type` enum(`none`,`lot`,`serial`,`lot_serial`), `category_id`, `brand_id`, `base_unit_id` FK→units, `costing_method` enum(`average`,`fifo`,`standard`) nullable (falls back to org default), `is_variant_parent` bool, `reorder_point` `decimal(18,4)`, `reorder_qty` `decimal(18,4)`, `enable_reorder_alert` bool, `preferred_supplier_id` (nullable), `purchase_price` `decimal(18,4)`, `sales_price` `decimal(18,4)`, `tax_code` , `is_active`.
- **Accounting mapping cols (nullable; resolved via mappings if null):** `inventory_account_ref`, `cogs_account_ref`, `income_account_ref`, `purchase_account_ref` — stored as *refs to Finance accounts* (see §13), not local FKs.
- **Indexes:** `(organization_id, is_active)`, `(organization_id, category_id)`, fulltext(`name`,`sku`).
- **Unique:** `(organization_id, sku)`.
- **Notes:** carries forward the good Finance item columns; cleans up dual `avg_cost`/`average_cost` — **no cached qty/cost here**; current on-hand and cost come from `stock_balances` (single source).

### `item_variants`
- **Purpose:** concrete variants (size/color) under a parent item.
- **Key cols:** `item_id` FK (parent), `sku`, `variant_attributes` (json e.g. {size:L,color:red}), `barcode_primary`, `purchase_price`, `sales_price`, `is_active`.
- **Indexes:** `(organization_id, item_id)`. **Unique:** `(organization_id, sku)`.
- **Note:** ledger/balances key on **(item_id, variant_id)**; a non-variant item uses `variant_id = NULL`.

### `units`
- **Key cols:** `code`, `name`, `symbol`, `kind` (count/weight/volume/length). **Unique:** `(organization_id, code)`.

### `unit_conversions`
- **Purpose:** convert between units for an item (Finance gap).
- **Key cols:** `item_id` (nullable for global), `from_unit_id`, `to_unit_id`, `factor` `decimal(18,8)`.
- **Unique:** `(organization_id, item_id, from_unit_id, to_unit_id)`.

### `item_images`
- **Key cols:** `item_id` FK, `variant_id` (nullable), `path`, `is_primary`, `sort`.
- **Indexes:** `(organization_id, item_id)`.

### `item_barcodes`
- **Purpose:** multiple barcodes per item/variant (EAN/UPC/internal).
- **Key cols:** `item_id`, `variant_id` (nullable), `barcode`, `type`.
- **Unique:** `(organization_id, barcode)`.

### `item_suppliers`
- **Purpose:** supplier item codes + supplier-specific price lists + lead time.
- **Key cols:** `item_id`, `supplier_id` FK, `supplier_sku`, `supplier_price` `decimal(18,4)`, `lead_time_days`, `is_preferred`.
- **Unique:** `(organization_id, item_id, supplier_id)`.

---

## 4. Partners

### `suppliers`
- **Key cols:** `central_supplier_ref` (nullable, link to Finance/central), `name`, `code`, `contact` (json), `is_active`.
- **Unique:** `(organization_id, code)`.

### `customers`
- **Key cols:** `central_customer_ref` (nullable), `name`, `code`, `contact` (json), `is_active`.
- **Unique:** `(organization_id, code)`.
- **Note:** SolaStock holds operational copies; **accounting balances stay in Finance** (see split doc).

---

## 5. THE CANONICAL LEDGER (core)

### `stock_ledger`  ★ append-only, immutable, single-writer
- **Purpose:** the one and only record of every stock change. Reports and balances derive from this.
- **Key cols:**
  - `organization_id`
  - `item_id`, `variant_id` (nullable)
  - `warehouse_id`, `zone_id` (nullable), `bin_id` (nullable)
  - `lot_id` (nullable), `serial_id` (nullable)
  - `direction` enum(`in`,`out`)
  - `quantity` `decimal(18,4)` (always positive; sign conveyed by `direction`)
  - `unit_cost` `decimal(18,4)`, `total_cost` `decimal(18,2)`
  - `costing_method` enum snapshot, `cost_layer_id` (nullable, for FIFO lot-layer link)
  - **provenance:** `source_type` (document class), `source_id`, `source_line_id` (nullable) — **NOT NULL `source_type`+`source_id`** (every movement traces to a document)
  - `moved_at` datetime (business date), `posted_at`
  - `idempotency_key` (unique) — prevents duplicate posts
  - `balance_qty_after` `decimal(18,4)`, `balance_value_after` `decimal(18,2)` (running snapshot for fast item-ledger reports)
  - `created_by`
- **Indexes:**
  - `(organization_id, item_id, variant_id, warehouse_id, moved_at)` — item ledger / movement reports
  - `(organization_id, warehouse_id, moved_at)` — warehouse activity
  - `(source_type, source_id)` — provenance lookups
  - `(lot_id)`, `(serial_id)`
- **Unique:** `(idempotency_key)`.
- **Constraints/notes:** NO updates, NO deletes, NO soft delete. Corrections are new reversing rows. `quantity > 0` CHECK. This single table replaces Finance's `inventory_movements` + `inventory_transactions` + the role `inventory_valuations` was failing to fill.

### `stock_balances`  (derived projection, maintained transactionally)
- **Purpose:** fast current state per stock-keeping coordinate. Always rebuildable from `stock_ledger`.
- **Key cols:** `organization_id`, `item_id`, `variant_id` (nullable), `warehouse_id`, `lot_id` (nullable), `bin_id` (nullable), `on_hand_qty` `decimal(18,4)`, `reserved_qty` `decimal(18,4)`, `available_qty` (generated = on_hand − reserved), `average_cost` `decimal(18,4)`, `total_value` `decimal(18,2)`, `last_movement_at`.
- **Unique:** `(organization_id, item_id, variant_id, warehouse_id, lot_id, bin_id)` (NULLs normalized).
- **Indexes:** `(organization_id, warehouse_id)`, `(organization_id, item_id)`.
- **Notes:** updated inside the same transaction as ledger append by `StockLedgerService`; a periodic integrity job asserts `SUM(ledger) == balances` (should always hold by design).

### `cost_layers` (FIFO support)
- **Purpose:** open cost layers for FIFO/standard costing.
- **Key cols:** `item_id`, `variant_id` (nullable), `warehouse_id`, `lot_id` (nullable), `received_at`, `unit_cost`, `original_qty`, `remaining_qty`, `source_ledger_id` FK→stock_ledger.
- **Indexes:** `(organization_id, item_id, warehouse_id, received_at)` (FIFO order).
- **Notes:** consumed in received-order on `out` movements when item costing = FIFO.

---

## 6. Lots & Serials

### `lots`
- **Purpose:** batch/lot with expiry (Finance gap).
- **Key cols:** `item_id`, `variant_id` (nullable), `lot_code`, `mfg_date`, `expiry_date`, `supplier_id` (nullable), `attributes` (json).
- **Indexes:** `(organization_id, item_id, expiry_date)` (expiry reports). **Unique:** `(organization_id, item_id, lot_code)`.

### `serial_numbers`
- **Purpose:** first-class serial tracking.
- **Key cols:** `item_id`, `variant_id` (nullable), `serial`, `lot_id` (nullable), `status` enum(`in_stock`,`reserved`,`sold`,`returned`,`scrapped`), `warehouse_id` (current), `bin_id` (nullable).
- **Indexes:** `(organization_id, item_id, status)`. **Unique:** `(organization_id, item_id, serial)`.

---

## 7. Reservations & Allocations

### `reservations`
- **Purpose:** soft holds for sales orders/allocations; reduce available without moving stock.
- **Key cols:** `item_id`, `variant_id` (nullable), `warehouse_id`, `lot_id`/`serial_id` (nullable), `qty` `decimal(18,4)`, `source_type`, `source_id` (e.g. sales_order_line), `status` enum(`active`,`released`,`consumed`), `expires_at`.
- **Indexes:** `(organization_id, item_id, warehouse_id, status)`, `(source_type, source_id)`.
- **Notes:** `stock_balances.reserved_qty` = sum of active reservations; maintained transactionally.

---

## 8. Stock Operation Documents

All follow header+lines, status enum, posting to ledger, immutability, `posted_guard_key`, `journal_ref` (nullable, set after Finance posts).

### `stock_adjustments` / `stock_adjustment_lines`
- **Header:** `adjustment_number`, `adjustment_date`, `reason_code`, `status`, `warehouse_id`, `total_increase_value`, `total_decrease_value`, `posted_guard_key`, `journal_ref`.
- **Line:** `item_id`, `variant_id`, `direction` enum(`increase`,`decrease`), `qty`, `unit_cost`, `lot_id`/`serial_id` (nullable), `bin_id`, `qty_before`, `qty_after`, `account_ref` (variance acct).
- **Unique:** `(organization_id, adjustment_number)`.
- **Notes:** **one** adjustment concept (Finance had two — collapsed here).

### `stock_transfers` / `stock_transfer_lines`
- **Header:** `transfer_number`, `transfer_date`, `from_warehouse_id`, `to_warehouse_id`, `status` (`draft`,`in_transit`,`received`,`cancelled`), `posted_guard_key`.
- **Line:** `item_id`, `variant_id`, `qty`, `lot_id`/`serial_id` (nullable), `from_bin_id`, `to_bin_id`, `received_qty`.
- **Notes:** posts TWO ledger entries (out from source, in to dest); supports in-transit virtual warehouse.

### `stock_counts` / `stock_count_lines` (cycle count & full stock take)
- **Header:** `count_number`, `count_type` (`cycle`,`full`), `warehouse_id`, `zone_id` (nullable), `status` (`draft`,`counting`,`review`,`posted`), `posted_guard_key`.
- **Line:** `item_id`, `variant_id`, `lot_id`/`serial_id` (nullable), `bin_id`, `system_qty`, `counted_qty`, `variance_qty` (generated).
- **Notes:** posting emits adjustment ledger entries for variances; feeds the "stock accuracy" dashboard widget.

### `landed_costs` / `landed_cost_lines`
- **Header:** `lc_number`, `lc_date`, `allocation_method` (`qty`,`value`,`weight`), `status`, `posted_guard_key`, `journal_ref`.
- **Line:** `cost_type` (freight/duty/insurance), `amount`, `target_receipt_id` (nullable), `account_ref`.
- **Notes:** posts cost-only adjustments to affected cost layers / average cost (no qty change); clearly flagged as cost-only (Finance mixed these confusingly).

---

## 9. Purchasing

### `purchase_orders` / `purchase_order_lines`
- **Header:** `po_number`, `supplier_id`, `order_date`, `expected_date`, `warehouse_id`, `status` (`draft`,`approved`,`partially_received`,`received`,`closed`,`cancelled`), `currency_code`, totals, `posted_guard_key`.
- **Line:** `item_id`, `variant_id`, `ordered_qty`, `received_qty` (derived), `unit_price`, `tax_code`, `expected_date`, `backorder_qty`.
- **Unique:** `(organization_id, po_number)`. **Indexes:** `(organization_id, supplier_id, status)`.
- **Notes:** PO does **not** move stock; only GRN does.

### `goods_receipts` / `goods_receipt_lines` (GRN)
- **Header:** `grn_number`, `purchase_order_id` (nullable), `supplier_id`, `warehouse_id`, `receipt_date`, `status` (`draft`,`inspected`,`posted`), `posted_guard_key`.
- **Line:** `po_line_id` (nullable), `item_id`, `variant_id`, `received_qty`, `accepted_qty`, `rejected_qty`, `unit_cost`, `lot_id`/`serial capture`, `bin_id`.
- **Notes:** posting GRN is the **IN** ledger event for purchases (with cost) — fixes Finance's receiving that never set cost and never touched the ledger truth. Uses `lockForUpdate` via the single writer (fixes the race condition).

### `supplier_returns` / `supplier_return_lines`
- **Header:** `return_number`, `supplier_id`, `goods_receipt_id` (nullable), `status`, `posted_guard_key`.
- **Line:** `item_id`, `variant_id`, `qty`, `lot_id`/`serial_id`, `reason`.
- **Notes:** posts **OUT** ledger entries; Finance posts the debit-note accounting.

---

## 10. Sales / Fulfillment (net-new vs Finance)

### `sales_orders` / `sales_order_lines`
- **Header:** `so_number`, `customer_id`, `order_date`, `required_date`, `warehouse_id`, `status` (`draft`,`confirmed`,`allocated`,`partially_fulfilled`,`fulfilled`,`cancelled`), totals, `posted_guard_key`.
- **Line:** `item_id`, `variant_id`, `ordered_qty`, `allocated_qty`, `fulfilled_qty`, `unit_price`, `tax_code`.
- **Notes:** confirming SO creates `reservations`; does not move stock until shipment.

### `pick_lists` / `pick_list_lines`
- **Header:** `pick_number`, `sales_order_id`, `warehouse_id`, `status` (`pending`,`picking`,`picked`), `assigned_user_id`.
- **Line:** `so_line_id`, `item_id`, `variant_id`, `bin_id`, `lot_id`/`serial_id`, `qty_to_pick`, `qty_picked`.

### `shipments` / `shipment_lines`
- **Header:** `shipment_number`, `sales_order_id`, `customer_id`, `carrier`, `tracking_no`, `ship_date`, `status` (`draft`,`shipped`,`delivered`), `posted_guard_key`, `journal_ref`.
- **Line:** `so_line_id`, `item_id`, `variant_id`, `qty`, `lot_id`/`serial_id`, `unit_cost`.
- **Notes:** posting a shipment is the **OUT** ledger event (consumes reservation, computes COGS, emits COGS to Finance).

### `sales_returns` / `sales_return_lines` (a.k.a. returns)
- **Header:** `return_number`, `sales_order_id` (nullable), `customer_id`, `status`, `posted_guard_key`, `journal_ref`.
- **Line:** `item_id`, `variant_id`, `qty`, `lot_id`/`serial_id`, `condition` (restock/scrap), `reason`.
- **Notes:** restock → **IN** ledger; Finance posts credit-note accounting.

---

## 11. Opening Stock

### `opening_stock_entries` / `opening_stock_entry_lines`
- **Header:** `entry_number`, `opening_date`, `warehouse_id`, `status`, `posted_guard_key`, `journal_ref`.
- **Line:** `item_id`, `variant_id`, `qty`, `unit_cost`, `lot_id`/`serial capture`, `bin_id`.
- **Notes:** the migration-import vehicle (one opening doc per warehouse per org carries imported balances); posts **IN** ledger + Dr Inventory / Cr Opening-Equity in Finance. Mirrors the good Finance opening pattern.

---

## 12. Audit

### `inventory_audit_logs`  (append-only)
- **Purpose:** every state change (document create/post/reverse/cancel, master-data edits, setting changes).
- **Key cols:** `organization_id`, `actor_user_id`, `action`, `entity_type`, `entity_id`, `before` (json), `after` (json), `document_ref`, `ip`, `created_at`.
- **Indexes:** `(organization_id, entity_type, entity_id)`, `(organization_id, created_at)`.
- **Notes:** no soft delete, no updates. First-class (Finance only had repair services).

---

## 13. Accounting Mapping (refs, not FKs)

### `accounting_mappings`
- **Purpose:** map SolaStock concepts → Finance chart-of-accounts refs (per org, with per-item/category override). Port of Finance's `OrgAccountDefaultsContext` idea.
- **Key cols:** `organization_id`, `scope` (`org`,`category`,`item`), `scope_id` (nullable), `inventory_account_ref`, `cogs_account_ref`, `income_account_ref`, `purchase_account_ref`, `adjustment_gain_ref`, `adjustment_loss_ref`, `opening_equity_ref`.
- **Unique:** `(organization_id, scope, scope_id)`.
- **Notes:** `*_ref` are opaque Finance account identifiers (code or central account id); SolaStock never owns the chart of accounts.

### `finance_posting_outbox`  (integration reliability)
- **Purpose:** transactional outbox; every ledger post that needs accounting enqueues a row here in the same transaction.
- **Key cols:** `organization_id`, `event_type` (`grn.posted`,`shipment.posted`,`adjustment.posted`,…), `payload` (json), `status` (`pending`,`sent`,`failed`), `attempts`, `idempotency_key` (unique), `sent_at`, `last_error`.
- **Indexes:** `(status, created_at)`. **Unique:** `(idempotency_key)`.
- **Notes:** a worker drains it to Finance; stock ops never block on accounting availability.

---

## 14. Dashboard Customization

### `dashboard_layouts`
- **Purpose:** persist per-user, per-org draggable widget layout.
- **Key cols:** `organization_id`, `user_id`, `layout` (json: ordered widgets, sizes, positions), `updated_at`.
- **Unique:** `(organization_id, user_id)`.

---

## 15. Mapping: requested tables → SolaStock tables

| Requested | SolaStock table(s) |
|---|---|
| organizations | `organizations` (+ `inventory_settings`) |
| warehouses / zones / bins | `warehouses`, `warehouse_zones`, `warehouse_bins` |
| items / variants / categories / brands | `items`, `item_variants`, `item_categories`, `item_brands` |
| units / unit_conversions | `units`, `unit_conversions` |
| item_images / item_barcodes / item_suppliers | `item_images`, `item_barcodes`, `item_suppliers` |
| inventory_accounts / accounting_mappings | `accounting_mappings` (+ `finance_posting_outbox`) |
| stock_ledger / stock_balances | `stock_ledger` (★), `stock_balances`, `cost_layers` |
| stock_adjustments(+lines) | `stock_adjustments`, `stock_adjustment_lines` |
| stock_transfers(+lines) | `stock_transfers`, `stock_transfer_lines` |
| stock_counts(+lines) | `stock_counts`, `stock_count_lines` |
| purchase_orders(+lines) | `purchase_orders`, `purchase_order_lines` |
| goods_receipts(+lines) | `goods_receipts`, `goods_receipt_lines` |
| sales_orders(+lines) | `sales_orders`, `sales_order_lines` |
| pick_lists(+lines) | `pick_lists`, `pick_list_lines` |
| shipments(+lines) | `shipments`, `shipment_lines` |
| returns(+lines) | `sales_returns`(+lines), `supplier_returns`(+lines) |
| lots / serial_numbers | `lots`, `serial_numbers` |
| reservations | `reservations` |
| landed_costs(+lines) | `landed_costs`, `landed_cost_lines` |
| inventory_audit_logs | `inventory_audit_logs` |
| (added) opening stock | `opening_stock_entries`(+lines) |
| (added) dashboard | `dashboard_layouts` |

---

## 16. Design decisions that fix the audit's risks

| Audit risk | Fix in this schema |
|---|---|
| Two un-reconciled stock truths | One `stock_ledger`; `stock_balances` is derived, rebuildable, integrity-checked |
| 7 writers | Schema makes ledger the only stock table; app enforces single `StockLedgerService` writer |
| INTEGER quantity | `decimal(18,4)` everywhere |
| Mutable posted docs | status enum + `posted_guard_key` + immutability guard |
| Partial valuation log | ledger carries `unit_cost`/`total_cost`/running value — complete history |
| No FIFO | `cost_layers` + per-item `costing_method` |
| No expiry | `lots.expiry_date` + expiry report indexes |
| Mixed tenancy | `organization_id` NOT NULL on all tables + one global scope |
| Idempotency optional | `idempotency_key`/`posted_guard_key` are required, unique columns |
| Accounting coupling | `*_ref` mappings + outbox; SolaStock never writes the GL directly |
