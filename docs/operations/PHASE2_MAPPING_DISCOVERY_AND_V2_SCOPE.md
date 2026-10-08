# Phase 2 mapping discovery and v2 scope

## Holds and non-mutation

These procedures require delivery, replay, legacy contract and historical repair
to remain disabled, with Finance legacy inventory writes blocked. Discovery is
read-only. Applying a reviewed manifest changes only additive Phase 2 mapping,
discovery and audit tables. It never changes master data, inventory, events,
journals, attempts, nonces, API usage or legacy keys.

## Discovery

Run in an explicitly selected entitled tenant:

```text
php artisan integration:phase2-discover \
  --organization-mapping=<mapping-uuid> \
  --tenant-database=<tenant_XXXXXX> \
  --solastock-organization=<id> \
  --output=<approved-permanent-evidence-file.json>
```

The deterministic JSON contains only stable record IDs, scope IDs, safe
classifications and hashes. It reports exact, missing, ambiguous, conflicting,
cross-organization, archived, schema, unit-conversion, tax and account
incompatibilities. It excludes secrets and private descriptions.
An exact item/customer/supplier/unit match requires a unique stable candidate
key (SKU, barcode, party code or unit symbol) with no conflicting populated
stable key. Name-only candidates, including categories and warehouses, are
`review_required` and are never auto-applied.

Review the report. For an approved manifest only:

```text
php artisan integration:phase2-discover \
  --organization-mapping=<mapping-uuid> \
  --tenant-database=<tenant_XXXXXX> \
  --solastock-organization=<id> \
  --apply \
  --approved-manifest-hash=<64-char-hash> \
  --output=<approved-permanent-apply-evidence-file.json>
```

The command recomputes and locks the before image. A changed manifest or a
concurrent/duplicate/conflicting mapping fails closed. Only deterministic
one-to-one results are added; unresolved results are persisted for review.

## V2 signing scope

Provision only after the immutable organization mapping and deterministic
backfill are verified:

```text
php artisan integration:phase2-provision-v2-scope \
  --organization-mapping=<mapping-uuid> \
  --tenant-database=<tenant_XXXXXX> \
  --solastock-organization=<id> \
  --confirm-maintenance-hold
```

The operation is idempotent. Output contains mapping UUID, key ID and held
status, never the secret. Existing legacy keys remain. The v2 key is scoped to
the exact client, central organization, tenant, Finance organization, SolaStock
organization and immutable mapping. The Finance receiver safety middleware
executes before signature/nonce/API-meter processing, so possession of the key
cannot bypass the hold.

## Rollback

Roll back code and leave additive mapping/audit rows intact. If a v2 scope must
be withdrawn, use the existing audited key revocation workflow with explicit
approval; do not delete either key record or mapping evidence. Never enable
delivery as a rollback action.
