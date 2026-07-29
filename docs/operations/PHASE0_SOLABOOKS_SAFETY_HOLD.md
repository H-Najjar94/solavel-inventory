# Phase 0 SolaBooks safety hold

Phase 0 is a preservation-only release. It does not deliver, ignore, retry,
cancel, reclassify, or repair an existing accounting event.

## Production flag

Set `SOLABOOKS_DELIVERY_ENABLED=false`, then clear only the SolaStock config
cache. The guard is enforced at the start of both `deliver()` and `deliverDue()`
and at the activation/retry controllers. A blocked request therefore occurs
before an event lock or update, attempt increment, signing nonce generation, or
HTTP request.

After Finance production verification proves
`LEGACY_INVENTORY_WRITES_ENABLED_FOR_SOLASTOCK_ORGS=false` is enforced, set the
SolaStock status mirror `SOLABOOKS_LEGACY_INVENTORY_WRITES_BLOCKED=true`. This
mirror never enforces writes; it only prevents the cross-application status
page from inferring the Finance state from the delivery flag.

The flag is narrowly scoped to SolaStock → SolaBooks journal delivery. Keep it
false until Phase 1 has written accounting approval. Re-enabling it is not part
of Phase 0 and must include the approved Phase 1 currency contract and its
production validation.

## Read-only evidence

Run once for each in-scope tenant:

```bash
php artisan integration:phase0-export \
  --database=tenant_000018 \
  --output=/home/hnajjar/release-artifacts/solabooks-solastock-phase0-20260729/evidence
```

The command accepts an explicit tenant only, performs SELECTs, sorts all records
by stable identities, emits JSON Lines plus a count/SHA-256 manifest, and omits
credentials, payload blobs, and business descriptions. Evidence output is
outside Git and must not be copied into the repository.

No scheduler or outbox worker is added by this release.
