# Phase 6A authoritative unit-conversion contract

Contract version: `solastock-unit-conversion.v1`.

SolaStock is the sole authority for inventory units and item-scoped conversions. It converts the signed source quantity to the base quantity with decimal arithmetic, the configured quantity precision, and `HALF_UP` rounding. It persists an immutable snapshot containing the item, source/base units, conversion identity, eight-decimal factor, precision, rounding mode, and SHA-256 hash.

Both units and the item must have verified immutable mappings under the exact organization mapping. The signed `solastock-journal.v2` payload retains the source and base quantities and mapping UUIDs. Finance validates scope, mappings, active Finance records, base-unit ownership, the arithmetic relationship, snapshot hash, signature, and reversal equality. Finance retains the source-unit snapshot as journal audit metadata and uses the supplied base quantity without applying another conversion. Quantity conversion never changes currency or exchange-rate calculations.

Identity conversion uses the same source/base unit, a null conversion ID, and factor `1.00000000`. Missing, inactive, cross-organization, cross-item, ambiguous, malformed, zero, negative, incompatible, or altered snapshots fail closed. Reversals reproduce the original snapshot byte-for-byte. The complete payload hash provides identical-retry idempotency and rejects altered conversion data.

The Phase 6A UAT conversion is SolaStock conversion `3`, item-scoped, from unit `8` to base unit `7`, factor `10.00000000`.

## Reviewed worker drop-in (not installed)

Repository source:
`ops/systemd/solastock-finance-v2-worker.service.d/phase6a-tenant-991005.conf`

Intended path:
`/etc/systemd/system/solastock-finance-v2-worker.service.d/phase6a-tenant-991005.conf`

Expected ownership/mode: `root:root`, `0644`.

Privileged installation, only after separate operator authorization:

```sh
sudo install -d -o root -g root -m 0755 /etc/systemd/system/solastock-finance-v2-worker.service.d
sudo install -o root -g root -m 0644 ops/systemd/solastock-finance-v2-worker.service.d/phase6a-tenant-991005.conf /etc/systemd/system/solastock-finance-v2-worker.service.d/phase6a-tenant-991005.conf
sudo systemctl daemon-reload
sudo systemctl cat solastock-finance-v2-worker.service
sudo systemctl is-enabled solastock-finance-v2-worker.service
sudo systemctl is-active solastock-finance-v2-worker.service
```

The expected merged output retains the reviewed Phase 4 unit, its marker condition and protections, followed by the two exact environment overrides in the drop-in. Expected state after reload is `disabled` and `inactive`. These commands do not enable, start, or create the condition marker.
