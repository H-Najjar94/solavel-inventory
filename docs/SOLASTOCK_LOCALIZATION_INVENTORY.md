# SolaStock localization coverage inventory

Audit baseline: 2026-07-29. This is deliberately a gap inventory, not a claim of completion.

## Execution ledger

States distinguish source migration from automated verification and production verification. A migrated page has no known hard-coded system copy in its own module; route completion additionally requires all reachable shared/backend/document surfaces.

| Workflow / route | Page modules | Source migration | Build / targeted scan | Backend / documents | Production |
|---|---|---|---|---|---|
| Items `/items`, `/items/new`, `/items/:id`, `/items/:id/edit` | `ItemsPage`, `ItemFormPage`, `ItemDetailPage` | In progress — list and form migrated; detail still has unmigrated specifications, variants, labels and FIFO/movement copy | Builds pass; route scan not clean | Pending | Not deployed |
| Warehouses `/warehouses`, `/warehouses/new`, `/warehouses/:id`, `/warehouses/:id/edit` | `WarehousesPage`, `WarehouseFormPage`, `WarehouseDetailPage` | Migrated, including zones, bins, stock, movements, media, floor map and audit | Build passes; targeted scanner clean for detail and reachable warehouse media/map components | Warehouse backend messages pending; regression test blocked by missing `solastock_test_a` test database | Not deployed |
| Stock balances `/balances` | `BalancesPage` | Migrated | Build passes | Export/backend availability messages pending | Not deployed |
| Stock ledger `/ledger` | `LedgerPage` | Migrated | Build passes | Audit action mappings and backend messages pending | Not deployed |
| Adjustments `/adjustments/*` | `AdjustmentsPage`, `AdjustmentFormPage`, `AdjustmentDetailPage` | Migrated in page modules | Build passes | Reachable shared document dialogs and backend rules still pending | Not deployed |
| Transfers `/transfers/*` | `TransfersPage`, `TransferFormPage`, `TransferDetailPage` | Migrated, including dispatch/receive actions, traceability, ledger and audit | Build passes; targeted scanner clean | Transfer API/domain messages pending; regression test blocked by missing `solastock_test_a` | Not deployed |
| Purchase orders `/purchase-orders/*` | `PurchaseOrdersPage`, `PurchaseOrderFormPage`, `PurchaseOrderDetailPage` | Migrated, including lines, approvals, linked receipts, dialogs and audit | Build passes; targeted frontend scanner clean | PO controller/domain messages and document output pending | Not deployed |
| Goods receipts `/goods-receipts/*` | `GoodsReceiptsPage`, `GoodsReceiptFormPage`, `GoodsReceiptDetailPage` | Migrated, including partial receipt, disposition, traceability, ledger and reversal | Build passes; targeted frontend scanner clean | Receipt service/API messages and document output pending | Not deployed |
| Onboarding `/onboarding` | `OnboardingPage` | Migrated | Build and targeted source scan pass | Provisioning API localization pending | Not deployed |

Current source-migration count: **20/55 React page modules**. Current route production-verification count remains **0/71** because the localization release has not been pushed or deployed.

## Surface inventory

| Surface | Scope audited | Current state | Required work |
|---|---:|---|---|
| React routes | 71 registered route entries in `resources/js/solastock/router/router.jsx` | English literals remain in page modules | Replace each system-owned literal with a stable namespace key |
| React pages | 55 page modules | Titles and navigation have partial keys; forms, tables, drawers and errors are mixed | Add page namespaces and contextual Arabic phrases |
| Shared React components | 9 components | Several buttons, empty states, labels and accessibility strings are hard-coded | Key all reusable component copy |
| API controllers | 30+ V1 controllers | API/domain messages are often English literals or raw exception messages | Return stable message codes and localized display messages |
| Request validation | Item, warehouse, document and workflow requests | Laravel validation output is not consistently localized | Add `validation.php` keys and locale-aware messages |
| Status/type enums | Stock, transfer, receiving, sales, count, serial/lot, integration | API values are stable but display mapping is incomplete | Centralize `statuses.php` and `types.php` mappings with safe fallback |
| Reports/exports | Report service, export service, CSV/Excel views | Headings and filter labels require audit | Add export/report namespaces and RTL-safe output |
| Print/PDF | Browser print and generated documents | Arabic font/shaping not verified | Add Arabic font configuration and route-level verification |
| Backend shell/errors | Tenant, entitlement, permission, integration middleware | Several user-visible messages are English/raw exceptions | Localize messages and sanitize exception output |
| RTL/responsive | Blade shell plus SPA CSS | `dir=rtl` exists; page-level directional behavior is not fully verified | Test desktop/tablet/mobile for every workflow |

## Route groups

| Namespace | Routes |
|---|---|
| dashboard | `/dashboard` |
| products | `/items`, `/items/new`, `/items/:id`, `/items/:id/edit` |
| warehouses | `/warehouses`, `/warehouses/new`, `/warehouses/:id`, `/warehouses/:id/edit` |
| suppliers | `/suppliers`, `/suppliers/new`, `/suppliers/:id`, `/suppliers/:id/edit` |
| stock | `/balances`, `/ledger`, `/opening-stock/*`, `/adjustments/*` |
| transfers | `/transfers/*` |
| counts | `/counts/*` |
| receiving | `/purchase-orders/*`, `/goods-receipts/*` |
| sales | `/sales-orders/*`, `/customers/*`, `/pick-lists/*`, `/packs/*`, `/shipments/*`, `/sales-returns/*` |
| traceability | `/traceability/*`, `/recalls/*` |
| reports | `/reports` |
| settings | `/settings`, `/settings/solabooks`, `/integrations/solabooks/events` |
| fallback | `*` (404) |

## Verification gate

Localization is not complete until a scanner reports zero unexplained system-owned English literals in React, Blade, PHP messages, exports and print templates; technical identifiers and user-entered values must be explicitly allowlisted. The current scanner result is **not zero**.
