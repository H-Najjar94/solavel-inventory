# SolaStock — Tenant Connection Model & Stage-2 Alignment

**Status:** documented divergence (no code/behaviour change in Stage 2).
**Scope rule honoured:** no production MySQL users, grants, `.env`, or data were changed.

## How Stock connects today (verified)

- Access control is **fail-closed at the access layer**: `LiveTenantResolver`
  resolves the client/org from the session (and validates org→client ownership
  against Central `mysql`), checks an inventory permission + app-enablement, and
  `ResolveInventoryTenant` returns **HTTP 409** (`no_access` / `tenant_missing` /
  `setup_required`) *before* any tenant connection is opened.
- The tenant **DB connection identity**, however, is **not** per-tenant:
  `TenantManager::useTenant()` sets only the *database name* on the `tenant`
  connection (`app/Services/Tenancy/TenantManager.php:42`); the username/password
  remain the connection default, i.e. the shared `DB_USERNAME` (`mysql`, a
  high-privilege account). Stock has **no `TenantCredentialDeriver`** wired into
  the runtime path and **no per-tenant `t_XXXXXX` MySQL user** (unlike
  Books/Projects/HR).

## Why Stage 2's "remove silent mysql fallback" does not apply verbatim

There is no *derived per-tenant credential* for Stock to "miss" — Stock connects
as the shared user **by design today**. Adding a fail-closed-on-credential-miss
here would either be a no-op or would break Stock entirely, because the
per-tenant users it would fail closed *to* do not exist. Creating them is a
production grant/user change, explicitly out of scope for this audit/stage.

So Stock is **explicitly documented as divergent** (permitted by the Stage 2
instruction) rather than altered.

## Residual risk (tracked, not fixed here)

A SQLi/RCE in Stock runs as the shared `mysql` superuser. Stock's per-org row
scoping and 409 access gate contain *normal* requests, but the DB identity is
not least-privilege. This is the same class as `pic_issues #1`.

## Safe path to align Stock (Phase B — gated, NOT executed)

1. Provision a per-tenant runtime user for each Stock tenant DB
   (`t_XXXXXX` scoped to `tenant_XXXXXX.*`, DML-only once Phase B lands), via the
   shared provisioner/bootstrap users — **a grant/user change requiring an
   approved window.**
2. Add a `TenantCredentialDeriver` + set username/password (not just database)
   in `TenantManager::useTenant()`, mirroring Books/Projects/HR.
3. Then enable the same fail-closed-on-credential-miss guard (503 + `[SECURITY]`
   `tenant_credential_resolution_failure` alert) used by the other apps.
4. Provisioning/DDL continues to route through the elevated
   provisioner/`tenant_admin` seam, never the runtime user.

Until step 1 is approved and executed, Stock intentionally stays on the shared
connection and is tracked as a known residual.
