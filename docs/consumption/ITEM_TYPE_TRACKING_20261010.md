# Item type controls inventory tracking — deployed 2026-10-10

Stock items require tracking; non-stock items and services disable it. Both applications show a locked tracking setting, bilingual explanations and an explicit legacy inconsistency warning. Sale and purchase remain independent. Native API/form/import/model and signed/durable synchronization paths use existing item classifications. Stock operations reject non-stock items; historical reversal paths remain intact.

Stock catalog writes serialize on the same item lock as stock posting. Tracked/untracked type changes are blocked for historical movements or nonzero quantity, reserved quantity or valuation balances. Finance protects movement history and stock balances under the existing item lock. Unrelated legacy edits preserve nullable or inconsistent tracking flags and accounting mappings; synchronization omits contradictory legacy flags instead of repairing them. Existing type aliases and optional non-stock units are preserved.

No migrations, schema defaults, baseline changes or Central deployment were needed. Existing nullable columns and SPSB paths are reused; no live customer records, balances, valuations or journals were backfilled or reclassified.

## Actual releases

- SolaStock: `20261010T163729Z-8985c37b`, commit `8985c37bdb729b2f37cda1026b38c8b6116d7804` (implementation `b9e87c1`, optional non-stock unit `4e92c86`, actionable translations `8985c37`).
- SolaCount: `20261010T164350Z-5ebe7887`, commit `5ebe78873dedd5974af66e45e5be8992e58f948f` (implementation `b20968a8`, native alias UI `359c3330`, Arabic punctuation `5ebe7887`).

Finance activated before Stock under the global and native release locks. Required native build/cache/release gates, scoped FPM refresh, worker SHA/heartbeat checks, HTTP health and tracked-source comparisons passed. Existing Internal Consumption and concurrent commercial-role/UI fixes remain ancestors. Stock foundation commits `5748e90` and `7a85afd` are preserved. All old hashed assets were retained.

## Focused verification

- Stock: 26 tests / 146 assertions, plus the real concurrency test / 15 assertions. The final catalog/stock delta rerun passed 11 tests / 90 assertions. Coverage includes three types, independent commercial flags, contradictory tracking writes, negative balances/history protection, legacy NULL/inconsistent flags, receipt/opening/adjustment/transfer rejection, consumption/returns and catalog type projection.
- Finance: 25 native contained tests / 177 assertions. Fresh SPSB baseline plus additive follow-ups, existing-tenant rerun and qualification input integrity passed. Tests include type rules, protected changes, legacy flags/accounts, signed service synchronization without a stock unit, tenant reference scope and existing consumption journal/default/pricing regressions.
- English/Arabic dictionaries: 3,545 matching keys, zero missing references.
- Final deployed bilingual browser smoke: 34 checks, zero browser/HTTP errors. All types have locked derived tracking; sale/purchase remain independent. Existing-item edit forms, consumption list/issue/report, connection defaults, quotes and plan screens passed. Production checks used GETs and unsaved form interactions only.

Receipts: `/var/tmp/item-type-tracking-20261010`, including `stock-qualified.log`, `stock-final-qualified.log`, `finance-tests-complete.log`, release stage/activation logs, `ui-smoke.json`, `live-final.json` and health/cleanup receipts.

## Rollback and cleanup

Retain Stock `20261010T132449Z-700aab09` and Finance `20261010T163956Z-b20968a8` (plus original Finance `20261010T133608Z-36cdc701`). A rollback uses the recorded previous pointer and native scoped FPM/cache/worker refresh; pause item settings edits during rollback. All targets retain compatible consumption support. No schema rollback or historical transaction rewriting is required.

Removed only task QA sessions/cookies, unused task Stock stages, superseded task qualification snapshots and the recreated task Finance worktree. Pushed commits, final qualification evidence, live/rollback releases and the user-requested original Stock worktree remain. No outstanding blocker.
