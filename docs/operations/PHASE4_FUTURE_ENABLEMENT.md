# Phase 4 future enablement runbook

Do not use this runbook before rollout approval.

1. Resolve reviewed mapping conflicts and capture an approved manifest hash.
2. Verify Finance and SolaStock served commits, health, SSO, EN/AR/RTL, and
   additive schema.
3. Confirm the Finance receiver remains blocked while selecting only newly
   approved v2 events for `ready`; never promote historical pending/ignored rows.
4. Set organization `transport_enabled=true` and an explicit
   `transport_enabled_workflows` allow-list.
5. Enable the dedicated worker flag for one approved tenant/organization only.
6. Enable the Finance receiver immediately before the canary worker and verify
   one approved non-historical canary.
7. Confirm Finance receipt/journal idempotency and Stock acknowledgement, then
   expand per organization/workflow.
8. Leave nightly reconciliation disabled until Phase 6 approval.

Rollback disables the worker first, then Finance receiver, waits for graceful
shutdown, and leaves leases to expire. Never drop transport, receipt, transition,
review, or mapping evidence.
