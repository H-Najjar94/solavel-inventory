# GRN currency correction — 2026-10-06

The GRN form and StoreGoodsReceiptRequest have no transaction-currency input.
GoodsReceiptService persists the source PO and receipt date, not a separate GRN
currency. Save & post first saves a draft, then validates its operational workflow.
Previously WorkflowCurrencyResolver rejected a standalone receipt because it had
neither a direct currency nor a source PO, even when the authoritative organization
base currency was valid. Its ValidationException contained JSON instead of a
localized user message.

Standalone connected receipts now use the organization's Finance-authoritative
base currency. Linked receipts resolve a live, organization-scoped purchase order;
when an older PO has no integration snapshot, its existing transaction currency is
retained. Linked sources never fall back to base currency. Enabled currencies,
verified authority, dated Finance-owned FX, and stock valuation conversion retain
their existing checks. Posting freezes resolved currency/FX in the outbox payload;
no new GRN column, migration, global default, or hardcoded currency is introduced.

Request validation rejects another organization's/deleted PO. Service validation
also guards direct callers and mismatched source lines on create, edit, and post.
English/Arabic currency messages reach the API envelope and highlight source PO or
receipt date when actionable. Organization configuration errors appear in a banner.
A failed posting retry updates the already-saved draft; unknown outcomes retain the
existing status-check flow.

Validation used a disposable MariaDB server with networking disabled:

- Focused workflow/API/UI tests: 15 passed, 105 assertions.
- Final affected GRN, traceability, reversal and warehouse tests: 35 passed,
  206 assertions; final additional cross-tenant assertion passed separately.
- Production-bundle browser regression: English and Arabic passed; localized FX
  date highlighting, corrected-date retry, one create, one update, two post calls.
- JavaScript regression: 9 passed. Dictionary parity: 3,321 EN/AR keys, no missing
  keys or references. Localization scanner: 113 existing findings, zero new findings
  compared with serving baseline (line numbers normalized).
- Production asset build passed after correcting a duplicate import caught by the
  build. Manifest validation and git diff --check passed.
- Full backend release run performed once: 682 tests, 631 passed, 24 failures,
  26 errors, one skipped. Seven errors from an attempted legacy-setting lookup were
  corrected and affected tests passed. All remaining 43 failures/errors reproduced
  on the unchanged serving baseline 54ad3ba7; they are existing access/fixture,
  delivery, and schema-count failures. The complete suite is not green.

Release uses a separate worktree based on the serving release, leaves shared staged
changes untouched, checks remote/current release identities, archives the committed
source, installs the qualified assets, rebuilds caches, and atomically switches the
current symlink under the deployment lock. The previous release remains the rollback
point. No production tenant migration is required.
