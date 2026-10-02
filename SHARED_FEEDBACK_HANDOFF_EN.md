# Stock consequential-action batch — ready for coordinated integration

Base includes root shared keys through `bf85929`; previous runtime is `20261002T185836Z-192a788b`.

Added one reusable `ConfirmedActionButton` over shared WorkflowConfirmation. Covered save-and-post on adjustment/count/opening-stock/transfer/goods-receipt forms; image/attachment/barcode/variant/supplier-price removal; transfer ship/receive; sales-order confirm/cancel; role removal; integration reset/discard/freeze/approval/activation (both settings and guided assistant); lot/serial availability changes; picked/packed completion; recall close; item creation with opening stock. Original permission predicates, API call arguments, typed safeguards and server rules remain intact. Cancel performs no request. Whole save-and-post sequence has a synchronous duplicate guard; if a draft was saved but posting failed, inputs stay visible and repeat saves are disabled with an explicit status path to that saved draft. Unknown create outcome offers the document list for status review.

Focused actual-component tests: eight EN/AR desktop/mobile CountForm partial-post failure and ItemAttachments delete cases passed. Cancel0; double Confirm sends one draft update plus one post (count) or one delete (attachment); pending Escape blocked; partial-post failure prevents a second draft sequence. Screenshots and `action-button-results.json` in tests/feedback/evidence. `action-payload-contract.cjs` confirms identical API expressions/arguments across21modified application files; JSX parse passes. Build `/tmp/stock-final-actions-build.txt` PASS; dictionary3305EN/AR PASS. No migrations/backend changes. Additional integration/lifecycle callers share this tested presentation wrapper; those specific production actions are not yet individually verified.

Previous deployed192a788 now has actual production8-case evidence: customer Laravel422 focus/value preservation and unknown503 repeat protection; existing synthetic count pending/error with unchanged server draft/ledger. Evidence `/root/feedback-release-evidence-20261002/stock-production/20261002T185836Z-192a788b/`. No new customer/draft creation in this verification, no stock posting.

Finite inventory `tests/feedback/flow-register.json`:76route entries (including redirect aliases/not-found),4reusable boundaries,6explicit retained/noncustomer exceptions. This is a route ledger, not a percentage claim. Remaining migration: inline document-line required summaries, aggregate invalid media messages, scanner offline review. Representative verification is explicitly separate from route-wide verification. Release owner alone deploys; preserve current compatible rollback192a788.

---

# Stock outcome and pending batch — 2026-10-02

Base: shared `9d7a0d6` after deployed `d0f695d5`. No migrations, backend, permission, accounting or endpoint payload changes. Parent coordinator owns the shared package.

Completed user-facing behavior:
- Shared request adapter distinguishes Laravel422 field validation, definitive permission/failure responses, and uncertain mutation outcomes. Identical in-flight payloads share one request; distinct payloads remain independent. Unknown outcomes lock that organization/endpoint until reload and show status-check guidance, with no blind retry. Existing access-denied event criteria remain intact.
- Customer/supplier/item/warehouse/document and settings/integration callers consume one failure adapter rather than showing duplicate raw exception messages. Field errors retain values, announce the error and focus the first invalid input. Validation with no rendered field uses one concise shared summary.
- Shipment, adjustment, opening-stock, goods-receipt and sales-return confirmation callbacks await completion and remain open on failure. Reversal reason entry uses the shared dialog with original minimum/maximum lengths; Cancel remains available before submission, pending dismissal is blocked, and failures retain the reason.

Focused evidence: `api-outcome-results.json` (4 locale/viewport cases), `count-page-results.json` (4), `reversal-results.json` (4), `api-contract-results.json` (concurrent distinct payloads, original permission-event codes, offline unknown lock). All in `tests/feedback/evidence`. Production build and dictionary check pass. Exact API endpoint-wrapper block compared unchanged. Existing production count evidence is `/root/feedback-release-evidence-20261002/stock-production/20261002T184307Z-d0f695d5/pending-results.json`: 4 actual-route cases with intercepted posting responses and unchanged server draft/ledger. This newer batch is not yet deployed or production verified.

Release verification: preserve existing organization entry; repeat actual synthetic count1 pending/error check with all POST intercepted, EN/AR desktop/mobile. Check synthetic customer form intercepted422 and subsequent synthetic success response without writing a customer. Do not post/reverse/adjust stock. Rollback is the immediately prior release `20261002T184307Z-d0f695d5` (or newer compatible current owner release). Required server release gates remain deployment-owner responsibility.

Remaining: finite flow inventory review, unconfirmed destructive attachments/images and consequential direct actions/save-and-post callers. Inline field validation, setup/read-only notices, report-empty states remain intentionally contextual. This is a coherent request-boundary batch, not 100% application completion.

---

# SolaStock shared feedback first batch

Base: serving commit97ac11d4c030cec9ea95c340c6d10e3953773ef9. Isolated branch ui/shared-feedback-20261002; no database/backend/auth changes or migrations.

The existing toast API now uses the shared localized React feedback provider: routine success/info notifications, blocking error/warning feedback. ConfirmModal now uses the shared accessible workflow confirmation, preserves caller messages/actions, and blocks repeated activation and pending dismissal. Sales return cancellation and reversal reason input use shared action-specific dialogs with document reference. Existing API payloads, state transitions, authorization and accounting decisions remain in their original callers.

Verification: existing dependency install and Vite build pass. Four actual Stock ConfirmModal/provider browser combinations (English/Arabic,1440/390px) pass Cancel0, Confirmdouble1, pending Escape guard, focus restoration, deduplication, error modal, RTL/mobile fit. Evidence tests/feedback/evidence/standalone-stock-results.json and screenshots. Harness tests/feedback/provider.jsx; orchestration currently /root/feedback-finance-20261002/tests/feedback/standalone-stock-browser.mjs uses installed browser tooling. No production mutation occurred.

Remaining: request-outcome handling and raw errors, full route-level and API-response inventory including inline validation/persistent notices, production verification. This first batch does not claim whole-app completion. Shared files owned by root coordinator; do not modify cross-app contracts independently.

Release owner must merge current main safely, run Stock required gates and existing integration safety checks, use standard serialized deployment, preserve rollback to20261001T235451Z-97ac11d4. Synthetic approved QA only. Verify an allowed Stock page, a Cancel confirmation with no request, routine success only on synthetic data, locale/mobile, permissions/setup unaffected. Never provision an unavailable QA organization merely for UI testing.

Follow-up: shared dialogs now inherit each application's brand token even outside the provider host. Actual Stock stylesheet browser assertion confirms amber action buttons across four EN/AR desktop/mobile cases. Added missing `salesOrders.common.unit` English/Arabic keys; dictionary gate passes 3303 keys/locale with zero missing references. Full conservative localization scan still reports 28 unchanged baseline files (evidence: tests/feedback/evidence/localization-baseline.json); this initial feedback batch is not full Stock localization completion. Existing registered brands and scanner syntax matches are retained; backend exception presentation remains in the next request-failure review batch.
