# SolaStock SPSB ownership and lifecycle

Status: application candidate rules; no production approval and no deployment.

SolaStock is the only operational inventory authority for connected
organizations. It owns `items`, `warehouses`, `stock_ledger`, `stock_balances`,
cost layers, inventory documents, fulfillment, traceability, and their
projections. SolaCount remains authoritative for customers, suppliers,
currencies, taxes, accounts, journals, and every other accounting table.

The deterministic application group is `solastock.inventory` at order 300,
after `shared-core` and `solacount`. It contains the 49 enabled migration files under
`database/migrations/tenant`, sorted by basename. Existing tenants are never a
candidate-generation input and are never migrated by the SPSB runner.

The SolaPOS consumption receiver is intentionally disabled. Its migration,
tables, middleware, route, event types, and processing service are excluded
from the canonical group. SolaStock remains inventory authority and emits the
guarded Finance receiver-v2 contract; this does not activate a SolaPOS writer.

## Ownership counts

| Class | Tables | Rule |
|---|---:|---|
| SolaStock-owned | 60 | Must be absent before SolaStock; created only by SolaStock |
| Shared Core contributor | 2 | Exact capability shape; never silently altered |
| Integration contract | 21 | Exact shape or fail before mutation |
| Forbidden duplicate/collision | 0 | Candidate generation stops if any remains |

The full table lists are the executable authority in `config/spsb.php` and are
materialized into each immutable candidate's ownership manifest.

## Collision audit

SolaStock and the pinned SolaCount candidate share eleven table names. Ten are
integration contracts and are exact at the column, index, and FK level:

- `integration_organization_mappings`
- `integration_master_data_mappings`
- `integration_mapping_discovery_runs`
- `integration_mapping_discovery_results`
- `integration_mapping_audits`
- `integration_document_lifecycle_mappings`
- `integration_document_lifecycle_links`
- `integration_document_lifecycle_audits`
- `integration_historical_repair_attempt_audits`
- `integration_historical_repair_batches`

`tenant_entitlements_snapshots` is Shared Core. The audit found that SolaStock
added `created_at` and `updated_at`; the represented timestamp migration is now
an intentional no-op because runtime code already treats those legacy columns
as optional. The resulting table exactly matches the pinned Shared Core shape.

SolaCount's legacy `inventory_items`, `inventory_locations`, `inventory_zones`,
`inventory_cells`, `inventory_stocks`, `inventory_units`,
`inventory_movements`, and `inventory_valuations` remain foreign accounting
history objects with their connected-organization write paths blocked. They are
neither read as operational stock authority nor evolved by SolaStock. They are
included in the forbidden-foreign-authority manifest.

## Guarded proof

`scripts/spsb/run-guarded-candidate.sh` creates a fresh socket-only MariaDB
instance under a private temporary directory and two guarded databases. It
validates a sealed descriptor and challenge table before mutation, replays a
SolaStock-only reference, then proves this lifecycle:

1. empty database
2. Shared Core fragments from the pinned SolaCount candidate
3. SolaCount product fragments
4. exact collision preflight
5. SolaStock migrations
6. schema and integration-contract verification
7. migration rerun with no schema change
8. candidate generation and checksum validation

The runner refuses dirty worktrees, TCP database connections, tenant-style
database names, changed SolaCount pins, partial contract tables, accounting
schema changes, duplicate inventory authority, migration drift, safety-hold
drift, rerun drift, and any attempt to overwrite an immutable candidate.
