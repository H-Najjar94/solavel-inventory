# Automatic connection of a new default-chart workspace

Finance completes currency, tax setup and the chart before calling the signed Stock workspace initialization action. Stock asks Finance to qualify its canonical chart and organization defaults, prepares a scoped binding/key through the existing signed Finance endpoint, then atomically saves validated account-role identities and activates future delivery. No journal, opening inventory, stock movement, document or historical-event replay is created.

The default path requires active Finance/Stock access, an authorized owner/connection manager, completed Finance setup, a canonical unchanged zero-balance chart, supported base precision, and no existing financial or inventory activity. Custom connections, segregation-of-duties requirements and ambiguous defaults retain the manual review path. Existing credentials, tenant boundaries, commercial checks and delivery rollout gates remain enforced.

Dry-run inventory (JSON report):

```sh
php artisan inventory:reconcile-default-connections
```

After reviewing that report, apply only explicit eligible Central organization IDs:

```sh
php artisan inventory:reconcile-default-connections --organization=123 --apply
```

Run the same command again to verify idempotency. The `default_connection` metadata records the version, source roles, actor, time and no-history/no-replay policy; permanent account-role mappings retain scoped audit identities. Any unproven/custom mapping is left for review. Do not use the manual migration wizard to fabricate owner or accountant approval for automatic setup.

Regression coverage: `DefaultStockConnectionTest` verifies fresh readiness, all configured roles, duplicate execution, failed preparation retry, entitlement/actor/org isolation, inactive accounts, custom connection preservation, zero financial events and the real setup route. Finance's `DefaultStockConnectionPlanTest` verifies the canonical chart/defaults and rejects changed defaults, subtypes and custom charts.
