# SolaStock shared feedback first batch

Base: serving commit97ac11d4c030cec9ea95c340c6d10e3953773ef9. Isolated branch ui/shared-feedback-20261002; no database/backend/auth changes or migrations.

The existing toast API now uses the shared localized React feedback provider: routine success/info notifications, blocking error/warning feedback. ConfirmModal now uses the shared accessible workflow confirmation, preserves caller messages/actions, and blocks repeated activation and pending dismissal. Sales return cancellation and reversal reason input use shared action-specific dialogs with document reference. Existing API payloads, state transitions, authorization and accounting decisions remain in their original callers.

Verification: existing dependency install and Vite build pass. Four actual Stock ConfirmModal/provider browser combinations (English/Arabic,1440/390px) pass Cancel0, Confirmdouble1, pending Escape guard, focus restoration, deduplication, error modal, RTL/mobile fit. Evidence tests/feedback/evidence/standalone-stock-results.json and screenshots. Harness tests/feedback/provider.jsx; orchestration currently /root/feedback-finance-20261002/tests/feedback/standalone-stock-browser.mjs uses installed browser tooling. No production mutation occurred.

Remaining: request-outcome handling and raw errors, full route-level and API-response inventory including inline validation/persistent notices, production verification. This first batch does not claim whole-app completion. Shared files owned by root coordinator; do not modify cross-app contracts independently.

Release owner must merge current main safely, run Stock required gates and existing integration safety checks, use standard serialized deployment, preserve rollback to20261001T235451Z-97ac11d4. Synthetic approved QA only. Verify an allowed Stock page, a Cancel confirmation with no request, routine success only on synthetic data, locale/mobile, permissions/setup unaffected. Never provision an unavailable QA organization merely for UI testing.
