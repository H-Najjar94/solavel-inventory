# Internal consumption implementation status

This branch is an incomplete foundation. It is not release-ready and has not been deployed.
No migrations, customer-data writes, stock movements, accounting deliveries, or provisioning changes were performed for this task.

## Preserved baseline

Production Stock was release `20261010T074109Z-3b9835ca` with an in-place shell hotfix documented in `HOTFIX_20261010.txt`. Commit `5748e90` captures that hotfix from the live source, including its six new files, without changing production. The feature worktree is based on that snapshot. Production Finance was `20261010T113010Z-912502b2`; its tracked source matched commit `912502b2` during inspection. Main application checkouts contain unrelated changes, including unresolved Finance merge entries, and were not modified.

## Implemented and verified

`ConsumptionAccountSelection` selects override → item → category → connection without guessing. Unauthorized overrides and nonpositive selected IDs fail closed. Its account predicate requires an active, postable, undeleted expense account in the mapped Finance organization. No application endpoint calls it yet. Missing configuration returns null for the eventual localized posting guard.

Focused standalone PHPUnit execution: 6 tests, 18 assertions, passed. These tests cover only selection precedence, unauthorized overrides, missing selection, invalid selection without fallback, and account eligibility. They do not establish database isolation, stock protection, or production readiness.

## Existing mechanisms to extend

- `StockLedgerService::post` is the sole physical stock writer and uses `CostingEngine`, balance locks, quantity policies, serial handling, and deterministic idempotency namespaces. Do not introduce a second ledger.
- `StockLedgerService::applyExactReversal` restores captured FIFO consumption layers. Current public reversal operates on a whole namespace. Partial consumption returns need a reviewed extension preserving exact layers, original costs, cumulative allocation locks, and final monetary rounding; a blended generic inbound movement is insufficient.
- `IntegrationOutboxService::record` persists within the local stock transaction; delivery is separate. A truly standalone operation records no Finance event. Existing connection ownership must not silently become standalone while disconnected.
- `IntegrationEvents`, `EventPayloadBuilder`, `AccountingJournalBuilder`, `SolaStockJournalContractBuilder`, workflow lifecycle mapping, workflow currency resolution, and the Finance receiver validators jointly implement the durable journal contract. New issue and return events must be qualified throughout this chain.
- `AccountRolePolicy` is a byte-identical shared Stock/Finance contract. Current consumption-expense role and workflows are absent. Do not misuse `cogs`, `adjustment_loss`, or `grni` for consumption.
- `InventorySetting.approvals` and the established document approval transitions are available; approval must remain separate from stock posting.
- `InventoryPermissionService` applies central membership/app authority, local role bundles, and the central permission ceiling. Routes additionally use `inv.access`, `inv.tenant`, `perm`, and `feature` middleware. New role grants and route-feature registration must extend the central manifest, too.
- `SolaBooksItemCatalogBridge` sends explicit signed catalog owner commands, reuses verified identity mappings, and deliberately disables automatic bidirectional imports. Extend this path; do not resurrect automatic catalog reconciliation.
- Finance `InventoryItem` has legacy `is_resold`, type/usage fields, accounting defaults, and Stock-owned valuation guards. Inspect their actual UI/import/posting semantics before deciding flag equivalence. Stock has `item_type`, trace tracking, and costing overrides; trace tracking is not an inventory-tracking flag.
- Finance has legacy `InventoryConsumptionRequestController`; it is not the new SolaStock feature and must not become a competing stock writer.

## SPSB and release requirements still outstanding

SPSB is the repository's deterministic schema candidate/provisioning system, not an ordinary tenant migration loop. Stock's `config/spsb.php` declares its ordered migration group and explicit ownership/contract tables. The guarded runner replays Shared Core, pinned Finance fragments, and Stock into private socket-only MariaDB, checks collisions, verifies repeatability, and generates immutable candidates. The currently pinned Finance candidate in Stock config is `solacount-sv2-b0004-362a2189`. Updating migration files alone does not update new-tenant provisioning. Actual runtime candidate registration and Central provisioning must be traced and updated together.

Remaining: item settings and migrations; both applications' sales/import/API restrictions with historical-return compatibility; mapped account adapter and localized settings messages; connection-default UI; issue/return documents and approvals; exact partial stock reversal; durable accounting events and receiver validation; permissions/features; English/Arabic UI and report; existing/fresh tenant SPSB proof; all requested focused integration/concurrency tests; builds; compatible deployments; worker/cache steps; rollback/health/version verification. None of these are claimed complete by the foundation unit tests.

A future release must preserve the captured shell hotfix and compare again against then-current releases. Do not deploy this branch as a completed consumption feature.
