# Phase 4 durable SolaStock → SolaBooks transport

## Safety boundary

The dedicated command is `integration:transport-work`. It processes only
`solastock-journal.v2` rows that are explicitly `ready` or due
`retry_scheduled`, have `transport_eligible_at`, complete mappings, a verified
held organization mapping, and an enabled organization/workflow scope.

Both `SOLABOOKS_DELIVERY_ENABLED=true` and
`SOLASTOCK_TRANSPORT_WORKER_ENABLED=true` are required before the first query,
lock, attempt, nonce, or HTTP request. Production keeps both false during
Phase 4. Existing `pending` and `ignored` rows are never selected.

## State machine

Allowed transitions are defined in `OutboxStateMachine::TRANSITIONS` and every
transition writes `integration_outbox_transition_audits`.

- `pending` → review/block/ready/ignored/superseded
- `review_required` → blocked/ready/ignored/superseded
- `blocked_mapping` or `blocked_contract` → review/ready/ignored/superseded
- `ready` → processing/review/block/superseded
- `processing` → sent/retry_scheduled/failed/dead_letter/ready
- `retry_scheduled` → processing/review/block/dead_letter
- `failed` or `dead_letter` → reviewed recovery only
- `sent` → reversed only
- ignored, superseded, and reversed are terminal

## Claim and acknowledgement

The claim transaction takes a short row lock, verifies ordering and dependency
state, stores owner/token/timestamps/expiry, increments the attempt, writes the
transition audit, and commits. Payload construction, signing, and HTTP then run
without any database transaction. Success/failure acknowledgement starts a new
transaction and accepts only the same unexpired lease token.

An expired lease returns to `ready`. A stale process cannot overwrite the new
lease. A remote commit followed by local failure is retried with the identical
organization-scoped idempotency key; Finance returns its durable original
result.

## Failure and retry policy

Network failures, timeouts, HTTP 429, and HTTP 5xx are retryable. HTTP 4xx
business/identity/mapping/currency/account/tax/period/workflow/idempotency
failures are permanent. Exponential backoff starts at 30 seconds, caps at one
hour, applies ±20% jitter, honors bounded Retry-After, and dead-letters after
eight attempts.

Safe error text is capped at 500 characters. Secrets, signatures, keys, and
stack traces are never retained in event or review records.

## Ordering and concurrency

Dependencies require an original event to be sent/reversed before its reversal.
`ordering_key` serializes one organization+aggregate while tenant and
organization scopes allow unrelated workflows to proceed independently.
No transaction is held during HTTP.

## Dead letters

Dead letters retain the source identity, workflow, attempts, failure category,
payload hash, failure timestamps, safe error, and lease history. Review actions
require `inventory.integration.retry`, an explicit note, resolved mappings,
enabled transport, and the Phase 0 delivery flag. Reviews append permanent
`integration_dead_letter_reviews`; they never delete the event.

## Reconciliation

`integration:reconcile --database=tenant_XXXXXX --organization=N` is read-only
and reports stock balance/ledger/layer differences, event/journal
classification, and lifecycle quantities. It returns explicit zero mutation
counters. The nightly systemd timer is installed disabled for Phase 4.
