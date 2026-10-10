# Internal Consumption and item tracking — final delivery report

# Internal consumption delivery evidence — 2026-10-10

Implemented issue drafts, configured approval, engine-cost posting, exact-cost partial/full returns, independent sale/purchase flags, item/category/connection expense defaults, authorized overrides, durable Finance delivery with visible synchronization state, reciprocal document/journal references, permission bundles, English/Arabic screens and a filtered net-consumption report. No second ledger, costing service, accounting transport, approval framework, or signed SPSB baseline was introduced.

Preserved Stock foundation commits `5748e90` and `7a85afd`, live hotfix branches, and the cash-return fixture fix. Unrelated main checkouts were untouched. Other active agent owns Finance release activation; Stock activation was serialized under the same global root release lock.

## Actual deployment

- Stock: `700aab09dc6bc5758ca2c9f7361a9bb8a0f76282`, release `20261010T132449Z-700aab09`. Native cache validation, scoped FPM identity, dedicated accounting worker identity/heartbeat and HTTP health passed.
- Central: `a9336dd72b09c12172e5b276c0529033f47ad37a`, release `20261010T125710Z-a9336dd7`. Established deployment, queue replacement, migration status, internal/public health and release identity passed.
- Finance: `36cdc701cb429e1e2210147d6e1c864be0b3db9f`, release `20261010T133608Z-36cdc701`. Native release gate, scoped FPM refresh, repeated HTTP identity and dedicated worker identity passed. This includes the other agent’s Sales/Purchasing roles, UI fixes and middleware correction (`8b45c6818`) plus native consumption settings (`ef00aaa35`), pricing preservation (`ba2180a7c`) and React control consistency (`36cdc701c`).

## Migrations and provisioning

The actual Central tenant manifest/SPSB additive followup path registers Finance `160000` and Stock `160000`/`161000`; nullable item settings preserve legacy behavior and existing customer mappings. Real tenant verification completed for 70 provisioned Finance tenants and 52 Stock tenants; Stock operational permission upgrades completed for all 52. Before/after legacy-row digests matched. Two never-ready, economically empty Finance workspaces had incomplete pre-existing baselines and were excluded from the completed rollout; no baseline fabrication or economic history was generated.

Native isolated signed SPSB baseline plus registered additive followups passed fresh provisioning and repeated existing-tenant upgrades. The Stock private native lifecycle and Central manifest tests also cover operational role provisioning and preservation of custom bundles. No live customer consumption documents were manufactured.

## Focused verification

- Stock broader valuation/functional qualification: 27 tests / 118 assertions plus a real concurrent issue/return test / 15 assertions. Latest options, source-link and serialized-return regression: 15 tests / 56 assertions plus concurrent test / 15 assertions. Pure account resolution: 6 tests / 18 assertions.
- Finance consumption receiver/defaults/native-form and pricing preservation: 20 tests / 132 assertions; native SPSB fresh/upgrade/rerun marker and qualification input monitor passed. The other agent’s combined permissions gate passed 87 tests / 371 assertions; its private bilingual role workflows and selective data exposure checks passed.
- Central manifests/tenant scope: 40 tests / 818 assertions.
- Production Stock six English/Arabic list/form/report screens: HTTP 200, translated headings, Arabic RTL, no browser or HTTP errors. Both language item forms: disabling sale hides sales price while purchase and inventory tracking stay enabled; no save. Finance item lists, native item forms and expense-default settings passed in English/Arabic, including RTL and no-save flag checks. Quote creation and plan screens also passed both languages. Final combined smoke: 18 checks, zero browser/HTTP errors.
- Both dictionaries: 3,542 keys, no missing English/Arabic/referenced keys. Production builds and native release gates passed.

Evidence receipts are retained under `/var/tmp/internal-consumption-20261010`; coordinated Finance receipts are under `/var/tmp/commercial-roles-evidence-20261010`. Relevant files include `stock-tests-compatibility.log`, `finance-tests-pricing-preservation.log`, `central-tests-permissions.log`, `migrations.log`, `permission-migrations.log`, `stock-activate-final-ui.log`, `finance-activate-complete.log`, `central-deploy.log` and `ui-smoke.json`, `final-release-report.json`.

## Practical rollback

Keep the recorded prior releases. Pause new consumption posting before an application rollback; preserve durable pending events and do not mark unsent accounting as complete. Stock can fall back to `20261010T130807Z-08a72b1a` while retaining the compatible Finance receiver. Finance’s previous compatible target is `20261010T133105Z-ba2180a7`. Central’s previous target is in its release metadata. Do not roll Finance back to a receiver without consumption support while consumption events exist; pause its transport and restore compatible receiver code first. Use native application deployment/cache/worker refresh steps after changing release pointers. Retain additive schemas and transaction history; do not run destructive migration downs or delete posted documents. Accounting correction uses authorized original-cost returns/reversals and period checks.

Cleanup removes only proven task-created unused stages and task authentication artifacts; all live and required rollback releases remain retained.

There is no outstanding implementation or deployment blocker. The original helper-only status is superseded by these implementation, native qualification, migration and actual deployment receipts. The final documentation commit records the deployed runtime SHAs; documentation does not require a further runtime release.

Final cleanup verified: removed task-created unused staging releases, older private Finance qualification snapshots, the two completed Finance/Central task worktrees, and the task QA cookies/sessions. Pushed commits, final qualification evidence, all live/rollback releases and the requested original Stock worktree remain preserved. Final health checks still passed after cleanup; disk free space was 15 GiB.

The focused follow-up now makes inventory tracking follow item type. See [the deployed item-type tracking evidence](ITEM_TYPE_TRACKING_20261010.md) for the newer Stock/Finance releases and checks.


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


## Final cleanup requested by owner

Task worktrees and temporary evidence folders were removed after preserving this report in both applications. Historical receipt paths above describe the checks performed; temporary receipt files are removed. All pushed commits, live releases and required rollback releases remain preserved.

### Final verification summary

```json
{
  "status": "implemented_tested_deployed",
  "releases": {
    "stock": {
      "release": "/var/www/solavel-stock/releases/20261010T163729Z-8985c37b",
      "sha": "8985c37bdb729b2f37cda1026b38c8b6116d7804",
      "differences": [],
      "hotfix_files": []
    },
    "finance": {
      "release": "/var/www/solavel-finance/releases/20261010T164350Z-5ebe7887",
      "sha": "5ebe78873dedd5974af66e45e5be8992e58f948f",
      "differences": [],
      "hotfix_files": []
    }
  },
  "stock_tests": {
    "tests": 27,
    "assertions": 161,
    "real_concurrency_included": true
  },
  "finance_tests": {
    "tests": 25,
    "assertions": 177
  },
  "ui": {
    "checks": 34,
    "result": "PASS",
    "browser_errors": 0,
    "http_errors": 0
  },
  "schema_changes": false,
  "native_fresh_and_existing_spsb": "PASS",
  "cleanup": "PASS",
  "post_cleanup_health": "PASS",
  "stock_evidence_commit": "c6c904b",
  "blockers": []
}
```
