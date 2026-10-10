# Internal consumption delivery evidence — 2026-10-10

Implemented issue drafts, configured approval, engine-cost posting, exact-cost partial/full returns, independent sale/purchase/inventory flags, item/category/connection expense defaults, authorized overrides, durable Finance delivery with visible synchronization state, reciprocal document/journal references, permission bundles, English/Arabic screens and a filtered net-consumption report. No second ledger, costing service, accounting transport, approval framework, or signed SPSB baseline was introduced.

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
