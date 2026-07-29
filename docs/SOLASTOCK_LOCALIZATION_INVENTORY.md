# SolaStock localization coverage inventory

Last updated: 2026-07-29.

This ledger distinguishes source migration, automated verification, and production verification. Production remains gated until the complete regression suite passes and the verified release is deployed.

## Coverage counters

| Scope | Migrated | Automated verification | Production verification |
|---|---:|---:|---:|
| Registered SPA routes (excluding wildcard fallback) | 71/71 | 71/71 source-accounted; route test pending full-suite gate | 0/71 |
| Original audited React module baseline | 55/55 | 55/55 | 0/55 |
| Current registered React page modules | 59/59 | 59/59 source scan/build | 0/59 |
| Shared component groups | 9/9 | 9/9 source scan/build | 0/9 |
| Backend/API user messages | Complete | 143 EN/AR keys, parity clean; locale tests pass | Pending |
| Reports/CSV/Excel/print-PDF | Complete | Localized titles, headings, summaries, UTF-8 CSV, RTL print HTML tests pass | Pending |

The current router contains four more registered page modules than the original 55-module audit baseline. They are included in the 59/59 current count rather than hidden or excluded.

## Route and workflow ledger

| Workflow / routes | Page modules and reachable surfaces | Frontend | Backend / documents | RTL / identifier handling | Automated state | Production |
|---|---|---|---|---|---|---|
| Shell, navigation, permissions, 404 | `AppShell`, navigation, permission guard, `NotFoundPage`, dialogs, errors | Migrated | Localized 403/404, entitlement and recoverable API messages | RTL shell and localized permission state; technical values isolated | Scanner/build pass | Pending |
| Dashboard `/dashboard` | Metrics, alerts, activity, charts, layout controls | Migrated | Tenant-supplied labels preserved | RTL-safe cards and values | Scanner/build pass | Pending |
| Items `/items`, `/items/new`, `/items/:id`, `/items/:id/edit` | List, bulk actions, form, detail, variants, media, attachments, valuation, FIFO, barcodes, labels, movements | Migrated | Item validation, barcode, attachment and image messages localized | SKU/barcode/user content preserved with bidi isolation | Scanner/build pass | Pending |
| Warehouses `/warehouses/*` | List, form, detail, zones, bins, stock, movement, media, floor map, audit | Migrated | Warehouse validation, structure constraints and authorization localized | Codes/names preserved; floor map RTL-safe | Scanner/build pass | Pending |
| Suppliers `/suppliers/*`, customers `/customers/*` | List, form, detail, contacts, performance | Migrated | Unique-code messages localized | Names, contacts and codes preserved | Scanner/build pass | Pending |
| Balances `/balances`, ledger `/ledger` | Availability, quantities, movement history, audit filters | Migrated | Stock ledger, bin, organization and reservation rules localized | Numeric and technical audit values isolated | Scanner/build pass | Pending |
| Opening stock `/opening-stock/*` | List, form, detail, import, ledger, reversal | Migrated | Import and reversal messages localized | CSV/user values preserved | Scanner/build pass | Pending |
| Adjustments `/adjustments/*` | List, form, detail, reasons, posting, reversal, audit | Migrated | Validation, reason and direction rules localized | Quantities and reason codes preserved | Scanner/build pass | Pending |
| Transfers `/transfers/*` | List, form, detail, dispatch, in-transit, receiving, audit | Migrated | Warehouse constraints and stock rules localized | References and technical values preserved | Scanner/build pass | Pending |
| Counts `/counts/*` | List, form, entry, variance, review, reconciliation | Migrated | Count/reservation shared rules localized | Arabic counts and quantity formatting covered | 4 tests / 30 assertions pass | Pending |
| Purchase orders `/purchase-orders/*` | List, form, detail, approval, receipt links | Migrated | Draft/edit/approval messages localized | PO numbers and supplier data preserved | Scanner/build pass | Pending |
| Goods receipts `/goods-receipts/*` | List, form, partial/full receipt, disposition, traceability, detail | Migrated | Receipt, quarantine and reversal messages localized | GRN, lots and serials preserved | Scanner/build pass | Pending |
| Sales orders `/sales-orders/*` | List, form, detail, reservations and actions | Migrated | Shared reservation/stock messages localized | Order numbers, customer data and SKUs isolated | 9 tests / 90 assertions pass | Pending |
| Fulfillment `/pick-lists/*`, `/packs/*`, `/shipments/*` | Lists, details, picking, packing, shipment and tracking | Migrated | Shipment stock rules localized | Tracking identifiers preserved | 3 tests / 35 assertions pass | Pending |
| Sales returns `/sales-returns/*` | List, form, detail, source reversal, disposition | Migrated | Return and reversal rules localized | Return references and user notes preserved | Scanner/build pass | Pending |
| Traceability `/traceability/*` | Lots, serials, lifecycle, FEFO, capture and selectors | Migrated | Lot/serial validation and stock rules localized | Mixed Arabic/Latin values isolated | 20 tests / 77 assertions pass | Pending |
| Recalls `/recalls/*`, scanner `/scanner` | List, form, detail, trace, CSV and scanner results | Migrated | Recall defaults and scanner errors localized | Recall, lot, serial and scan codes preserved | Scanner/build pass | Pending |
| Reports `/reports` | Catalog, filters, schedules, tables, summaries and charts | Migrated | 23 localized report titles; localized column/summary metadata | Result values bidi-isolated | Report/locale tests pass | Pending |
| CSV/Excel/print-PDF | Report exports and recall CSV | Migrated | Localized metadata/headings, UTF-8 BOM/XML, RTL print HTML | Arabic font stack, shaping by browser print engine, `dir=rtl`, bidi identifiers | Export tests pass | Pending |
| Settings and integrations | Inventory policy, master data, permissions, taxes, currencies, reorder, SolaBooks mapping/events | Migrated | Settings, sync and integration errors localized; raw provisioning exception removed | IDs, API values, currency codes preserved | Scanner/build pass | Pending |
| Onboarding source module | Welcome, setup steps, provisioning guidance | Migrated (central launcher owns live route) | Tenant readiness/provisioning messages localized | Migration marker intentionally exact | Scanner/build pass | Pending |

## Localization architecture

- Frontend uses `I18nProvider`, stable contextual keys, and isolated namespace files under `resources/js/solastock/i18n/`.
- The retired broad English-to-Arabic `phrases` replacement map has been removed.
- Backend uses `lang/en/inventory.php` and `lang/ar/inventory.php`.
- `SetInventoryLocale` resolves `locale`, `X-Locale`, `Accept-Language`, then the persisted session locale, and emits `Content-Language`.
- Database/API enum values and customer-entered data remain unchanged; only display labels are translated.
- Unknown shared statuses use a localized fallback that retains the original technical value.

## Scanner allowlist

The coverage scanner has two narrow reviewed exclusions:

| Exact value | Exact scope | Reason |
|---|---|---|
| `SKU` | `ItemsPage.jsx` table heading | Internationally recognized inventory abbreviation; identifier must remain exact |
| `migrated_at_inv` | `OnboardingPage.jsx` administrator guidance | Immutable tenant migration marker used by the server command |

No directory, page, component, controller, service, validator, report, or document surface is broadly excluded.

## Verification log

- `scripts/check-localization-coverage.sh`: exit 0 after scanning React pages/components and PHP controllers/services/requests.
- Backend dictionaries: 143 EN keys / 143 AR keys at the backend-message checkpoint, no missing keys. Report keys were added subsequently with matching parity.
- Locale and report/export tests: 14 passed, 707 assertions.
- Sales order tests: 9 passed, 90 assertions.
- Fulfillment tests: 3 passed, 35 assertions.
- Counts tests: 4 passed, 30 assertions.
- Traceability tests: 20 passed, 77 assertions.
- Latest `npm run build`: passed; chunk-size warning only.
- Latest `git diff --check`: passed.

## Remaining release gates

1. Run the complete relevant backend inventory regression suite and final frontend/static checks.
2. Confirm the final production build and clean worktree.
3. Push the verified commit range to `origin/main`.
4. Deploy using the established repository process.
5. Confirm production-served SHA and bundle identity.
6. Verify representative English and Arabic workflows, locale persistence, and desktop/tablet/mobile RTL in production.
