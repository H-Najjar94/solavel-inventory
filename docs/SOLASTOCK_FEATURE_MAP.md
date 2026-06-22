# SolaStock — feature map & implementation roadmap

Audit date: 2026-06-14. Method: read services / models / controllers / routes /
React pages / tests directly. Where depth wasn't fully read, it's marked
"present, depth unverified" rather than claimed complete. No fake completions.

**Scale today:** ~30 services, 50 tenant models, 28 controllers, **141 API
routes**, ~57 React SPA pages, **21 test files** (125 tests green).

Legend: ✅ implemented & tested · 🟡 implemented, thin/no dedicated test ·
🟦 scaffolded/partial · ❌ missing · ⚠ unsafe/needs polish.

---

## 1. Dashboard
- **Exists:** `DashboardController`, `DashboardMetricsService` (org-scoped after C2 fix), `DashboardPage.jsx`, `DashboardLayout` model (saved layouts).
- **Partial:** metric breadth — KPIs present; drill-down depth unverified.
- **Missing:** valuation widgets (on-hand value trend, FIFO exposure), low-stock/expiry alert tiles wired to the rich report registry.
- **Tests:** org isolation ✅ (`ReportOrgIsolationTest`). No dedicated dashboard-content test 🟡.
- **Next:** add valuation + alert tiles in Phase 1/2.

## 2. Items / Products
- **Exists ✅:** `Item`, `ItemVariant`, `ItemCategory`, `ItemBrand`, `ItemBarcode`, `ItemImage`, `Unit`, `UnitConversion`; `ItemController` (incl. `/items/{item}/movements`); `ItemsPage/ItemFormPage/ItemDetailPage`.
- **Partial:** ItemDetail shows per-warehouse on_hand/reserved/available/avg_cost/total_value, but **no FIFO layer breakdown** (the data exists, isn't surfaced).
- **Missing:** item valuation panel (layers + reconciliation), supplier price lists, faceted search.
- **Tests:** covered indirectly via stock engine; no item-CRUD feature test 🟡.

## 3. Warehouses / Locations
- **Exists ✅:** `Warehouse`, `WarehouseZone`, `WarehouseBin`; `WarehouseController` + `WarehouseStructureController`; `WarehousesPage/Detail/Form` + `WarehouseFloorMap.jsx` (visual map).
- **Missing:** bin capacity enforcement depth, putaway rules.
- **Tests:** none dedicated 🟡.

## 4. Stock Ledger
- **Exists ✅:** `StockLedger` (immutable: `stock_ledger_no_update/no_delete` triggers), `StockLedgerService` (single writer), `/ledger` endpoint (filterable item/wh/direction/source/date), `LedgerPage.jsx`. Rows carry `balance_qty_after`/`balance_value_after` (running balance).
- **Partial:** UI surfacing of running balance / per-movement cost / consumed-layer linkage unverified — likely thin.
- **Tests ✅:** `StockEngineTest`, `SingleWriterGuardTest`, `BalanceLockTest`, immutability proven.
- **Next:** richer movement history (Phase 1).

## 5. Stock Balances
- **Exists ✅:** `StockBalance` projection, `StockBalanceController` (`average_cost`, `total_value`, on_hand/reserved/available), `BalancesPage.jsx`.
- **Tests ✅:** `BalanceLockTest` (first-row lock race), reconciliation in `StockEngineTest` (IntegrityChecker).

## 6. FIFO / Cost Layers
- **Exists ✅ (engine):** `CostLayer`, `CostLayerConsumption`, `CostingEngine` (FIFO + average), layer fidelity on transfer/reversal.
- **❌ Visibility gap:** `CostLayer` is **not exposed via any API route or UI**. The shipped FIFO fidelity has zero user-facing surface.
- **Tests ✅:** `FifoLayerFidelityTest` (per-layer transfer, reversal, valuation).
- **Next:** **Phase 1** — valuation/cost-layer endpoint + UI.

## 7. Stock Transfers
- **Exists ✅:** `StockTransferService` (OUT/IN, per-layer FIFO recreate, reversal), `StockTransferController`, `Transfers/Detail/Form` pages, `/transfers-available`.
- **Partial ⚠:** transfer/reversal screens don't explain cost-layer movement to the user.
- **Tests ✅:** `FifoLayerFidelityTest` covers transfer + reversal.
- **Next:** cost-layer-aware transfer detail (Phase 2).

## 8. Stock Adjustments
- **Exists 🟡:** `StockAdjustmentService`, controller, `Adjustments/Detail/Form` pages, outbox `adjustment.posted/reversed`.
- **Tests:** no dedicated adjustment posting test 🟡 (engine-level only).

## 9. Stock Counts
- **Exists ✅:** `StockCountService` (TOCTOU-safe: variance recomputed vs live locked on-hand), controller, `Counts/Detail/Form` pages, `count-variance` report.
- **Tests ✅:** `StockCountLiveQtyTest`.
- **Next:** safer guided count workflow (freeze/blind count) — Phase 3 candidate.

## 10. Reservations
- **Exists 🟡:** `StockReservationService`, `Reservation` model, SO `reserve`/`release-reservation` routes (`manage_reservations`), `available_qty` on balances.
- **Tests:** `SalesFulfillmentFoundationTest` (foundation) ✅; reservation race/expiry depth unverified 🟡.

## 11. Purchase Receiving / GRN
- **Exists 🟡:** `PurchaseOrder(+Line)`, `GoodsReceiptService`, GRN controller (remaining-qty logic), PO/GRN list/detail/form pages, outbox `grn.posted` (Dr inventory / Cr GRNI).
- **Tests:** no dedicated GRN posting test 🟡.

## 12. Sales Shipment / Issue
- **Exists 🟡:** `SalesOrderService`, `PickListService`, `PackService`, `ShipmentService`, `SalesReturnService`; full pick→pack→ship→return pages; outbox `shipment.posted` (Dr COGS / Cr inventory), `sales_return.posted`.
- **Tests ✅ (foundation):** `SalesFulfillmentFoundationTest`. Per-stage depth thin 🟡.

## 13. Batch / Lot Tracking
- **Exists ✅:** `Lot`, `LotService`, FEFO logic, lot movements endpoint, `Lots/LotDetail` pages.
- **Tests ✅:** `FefoAndCaptureTest`, `TraceabilityFoundationTest`.

## 14. Serial Number Tracking
- **Exists ✅:** `SerialNumber`, `SerialService`, serials endpoints/pages.
- **Tests ✅:** `SerialAndConcurrencyTest`.

## 15. Traceability / Recall
- **Exists ✅:** `TraceabilityService`, `RecallService` (+actions/lines), recall pages, `lot-trace`/`recall` reports. (Org-scoped after C2 fix.)
- **Tests ✅:** `TraceabilityFoundationTest`, isolation via `ReportOrgIsolationTest`.

## 16. Reports
- **Exists ✅ (rich):** `InventoryReportService` registry — inventory-valuation, item-ledger, low-stock, dead-stock, fast-moving, expiry-risk, lot-expiry, lot-trace, count-variance, fulfillment-status, capacity, **layers**, etc.; `ReportController`, `ReportExportService`, `ReportFilters`, `ReportsPage.jsx`.
- **Tests ✅:** `ReportRegistryTest`; per-report correctness thin 🟡.
- **Note:** a `layers` report key exists — Phase 1 should reuse/extend it rather than duplicate.

## 17. Finance / SolaBooks Integration
- **Exists ✅ (outbox-only, SAFE):** `IntegrationOutboxService` writes **only** to tenant `integration_outbox_events`; `IntegrationEvents` (event→suggested Dr/Cr mapping), `EventPayloadBuilder`, `IntegrationStatusService`, `IntegrationAccountMapping`, settings/events pages. **Verified: zero direct Finance DB/table writes.**
- **Missing:** clear GRN/shipment journal **payload schemas**, preview/status screens for "what will sync."
- **Tests ✅:** `IntegrationFoundationTest`.
- **Rule:** keep outbox/preview/status only until explicitly approved.

## 18. Access Control
- **Exists ✅:** `InventoryPermissionService` (fail-closed; role from central `user_organizations.role`), `perm:` route middleware throughout.
- **Tests ✅:** `InventoryPermissionTest`.

## 19. Multi-org / tenant safety
- **Exists ✅:** per-client DB + `OrganizationScope` + `BelongsToOrganization` (now: create-stamp + **update-immutable** org_id), `TenantManager`, `LiveTenantResolver` (X-Org-Id hardened), `TenancySafetyGuard`, elevated provisioning seam.
- **Tests ✅:** `TenantIsolationTest`, `ReportOrgIsolationTest`, `HeaderHardeningTest`, `OrgIdImmutableOnUpdateTest`, `ProvisioningElevatedConnectionTest`, `TenancySafetyGuardTest`.

## 20. Settings
- **Exists 🟡:** `InventorySetting` (costing method, negative-stock, numbering/barcode/approvals JSON), `SettingsController`, `SettingsPage.jsx`.
- **Tests:** none dedicated 🟡.

## 21. Audit / Security
- **Exists 🟡:** `InventoryAuditLog` model; immutable ledger; fail-closed perms; demo-off-in-prod.
- **Partial:** audit-log coverage breadth (which actions are logged) unverified.
- **Deferred:** DB privilege separation (Steps 1–2 done; Step 3 runbook ready, parked).

## 22. UX / tablet-style interface
- **Exists 🟡:** React SPA (`solastock-app.blade.php` + router, ~57 pages), `WarehouseFloorMap`, error boundary, pickers, shared `ui.jsx`.
- **Missing:** tablet/touch-optimized layouts, valuation/layer visualizations, cost-layer-aware document screens.
- **Tests:** no JS tests present (backend feature tests only) 🟡.

---

## Cross-cutting honest notes
- Backend breadth is high; **test depth is concentrated** on the stock engine,
  tenancy, traceability, and sales/integration *foundations*. Many document
  workflows (GRN, adjustments, shipment stages) lack dedicated posting tests.
- The **valuation/FIFO-layer data exists but is invisible** to users — highest
  user-facing value for the least risk.
- All isolation/immutability/FIFO/outbox invariants are currently intact.

---

## Roadmap — next 3 safe phases

### Phase 1 — Valuation & FIFO-layer visibility + clearer movement history (START HERE)
Highest value, read-only, zero risk to invariants.
- **1a. Cost-layer / valuation API** (read-only): `GET /items/{item}/valuation`
  → per-warehouse on_hand, average_cost, total_value, **FIFO layer list**
  (remaining_qty, unit_cost, original_qty, source, created_at), and a
  reconciliation (Σ layer value == balance total_value). Gated by
  `perm:inventory.view_stock`. Reuse the `layers` report logic where possible.
- **1b. Item valuation panel** (ItemDetailPage): show the layer stack + on-hand
  value + avg vs FIFO, with reconciliation badge.
- **1c. Richer movement history**: surface `balance_qty_after`/`balance_value_after`
  (running balance) and per-movement cost in `LedgerPage`; for OUT rows, expose
  which layers were consumed (via `cost_layer_consumptions`).
- **Tests:** feature test for the valuation endpoint (multi-layer item: layers
  returned in FIFO order, reconciliation holds, org-scoped/fail-closed,
  permission-gated); movement-history endpoint test (running balance + consumed
  layers for an OUT).

### Phase 2 — Cost-layer-aware documents + transfer/reversal clarity
- Transfer detail shows source layers consumed → destination layers created.
- Reversal screens explain what will be restored (layer-by-layer).
- Adjustment/GRN posting **dedicated tests** (close the 🟡 gaps).
- **Tests:** transfer-detail valuation test; adjustment & GRN posting tests.

### Phase 3 — Finance preview/status + safer counts
- **Finance integration preview/status** screens: render the outbox payload that
  *will* sync (GRN/shipment journals) with clear Dr/Cr from `IntegrationEvents`,
  plus per-event status — **still outbox-only, no Finance writes**. Define exact
  GRN/shipment journal payload schemas.
- Safer guided stock count (blind/freeze workflow).
- **Tests:** outbox payload-schema test (GRN/shipment journal shape); count
  freeze-workflow test.

Every phase keeps the suite green, isolation fail-closed, ledger immutable, FIFO
fidelity intact, and Finance strictly outbox/preview/status.
