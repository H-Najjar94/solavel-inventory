# Finance–Stock integration prerequisites (held deployment)

This change does not activate a connection, release accounting delivery, replay historical events, change plans/grants, or add Inventory workspace pages. All Production holds remain required.

## Authority

Central's organization-scoped `connection_activation_delivery_entitled` is canonical. Both apps must independently have verified commercial access through their paid-through windows. Receiver action permissions, active Central membership/application assignment, immutable organization mapping and warehouse assignments are independent gates. A service signature never implies owner authority. Stale-but-valid snapshots remain usable; expiry/revocation does not.

Connected catalog ownership:

| Fields | Owner |
| --- | --- |
| SKU, name, description, operational item type, tracking, reorder settings, activity | Stock; shared identity is a Finance projection via the signed mapped `items.show` command |
| Accounting assignments, Finance billing price, billing-only service settings | Finance |
| Physical quantities, valuation pool, warehouse/location/lot/serial state | Stock only; never copied back into Finance legacy quantity/cost fields |
| Finance-only unmapped service/non-inventory records | Finance; a mapped record does not gain this exception |

The old bidirectional observers no longer write to the other application's tables. Legacy bulk import/reconcile and Stock-side creation of missing Finance catalog records fail explicitly rather than guessing/remapping SKU identities. Existing mappings and records remain. `ConnectedCatalogProjection::refresh` requires Finance permission, mapping, an exact expected local revision, and applicable owner-configured approvals. Duplicate refreshes preserve local accounting/billing values. Creating missing mapped Finance catalog items is not yet a supported automatic connection-wizard action; it remains held for a reviewed Finance-owner creation flow in the workspace phase.

## Signed interface

POST `/inventory/api/internal/finance-workspace`, protocol `finance-stock-workspace.v1`; server-only `FINANCE_STOCK_WORKSPACE_SECRET` (minimum 32 bytes), no redirects or browser credential storage. Signature binds version, POST, fixed path, timestamp, nonce and exact body digest. Five-minute clock window; durable-cache nonce retention 610 seconds. Credentials are not installed by this code deployment: the new endpoint fails closed until configured.

`WorkspaceActions::ALLOWED` is the explicit native Stock action list: dashboard, items, warehouses/zones/bins, balances/ledger/audit, transfers, counts, adjustments, lots and serials. Stock validates the actual Central actor, exact client/org/Finance mapping, both application assignments, native per-action commercial/role requirements and warehouse scope. Mutations require an active connection AND the delivery safety gate; all remain held in Production.

Mutations carry a 16–128 character idempotency key. A mapping-row lock serializes commands; existing immutable audit rows atomically retain request hash and response with the domain mutation. Same-content retries return the original response; changed content with the same key conflicts. Revisions protect existing records and their lines. Warehouse responses include child location revisions. Requests never provision/migrate tenants. Unknown actions, forged identity and mismapped resources fail closed.

## Accounting correction and limits

Finance accepts Stock's historical `solabooks_authoritative_snapshot` transport label only when the exact dated rate matches Finance's own saved authoritative rate. Newly built Stock events read Finance's dated rate with exact mapped organization scope and carry its actual source.

New connected physical costs require an explicitly reviewed `finance_currency_contract.inventory_valuation_basis=finance_base.v1` and opening valuation/cutoff. This is an activation prerequisite, never populated by deployment. GRN transaction unit cost is converted once into the Finance base pool. Shipments and returns consume/restore that pool independently of sales currency. Journal construction converts base values into transaction amounts and verifies the round-trip base amount. Unrepresentable amounts fail within the originating physical transaction. Later source reversals retain original immutable currency/rate/conversion snapshots and their outbox dependency.

The current Stock schema supports base money scale 2 (ledger total DECIMAL(18,2)) and cost scale 4. Other base scales require separately scoped additive schema/SPSB qualification; they are explicitly blocked, not silently rounded. This phase introduces no migration and never reinterprets existing mixed-currency history. Partial receipt and full linked source return are qualified. The existing shipment-linked return service performs a full immutable source reversal; partial linked returns are not claimed as implemented here.

Finance keeps authoritative posting periods, journal ownership (Stock GRNI/inventory/COGS only), document conversion identity and configured financial-document approvals. Stock journals are system effects, not manual journals; the canonical manual-journal approval target is not expanded to system entries. Approval never posts or allocates money automatically.

## Executed qualification

Stock integration: 85 tests / 721 assertions; entitlement unit tests: 18 / 45. Finance affected selection: 45 / 211. Disposable private-network MariaDB runtimes, b0010 Finance schema and DML-only Finance runtime. Includes signatures/replay, actor/warehouse denials, revision conflicts, atomic command receipts, FX/base pool, duplicate movements, later-date return, catalog ownership and approval blocking, journal ownership/idempotency, financial lifecycle links and Production holds.

Actual HTTP lost-ack recovery uses the repository's isolated durable receiver simulator; it is not represented as a live Finance-to-Stock end-to-end test. Finance journal acceptance/posting and Stock physical workflows are separately exercised. Historical replay and broader partial-return acceptance remain operational qualification requirements before activation.

No frontend, dependencies, migrations, seeds or SPSB provisioner source changes. Existing built assets and immutable b0010 are retained. Evidence: `/root/inventory-prerequisites-20260907/`.
