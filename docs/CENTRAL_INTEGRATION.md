# SolaStock ↔ Central Solavel integration

SolaStock now follows the same SSO + co-tenant pattern as Finance / Projects / HR.
It is **self-contained** — it consumes the central handoff token and shares the
per-client tenant DB. The two changes below live in the **central app**
(`/var/www/html/solavel`) and are required to make SolaStock launch like the
other apps. They are **documented here, NOT applied** (the task forbids modifying
other apps without explicit approval).

## 1. App launcher entry (config/apps.php)

The `inventory` entry already exists but is `coming_soon` with `url => '#'`.
Make it live:

```php
'inventory' => [
    'name' => 'SolaStock',
    'tagline' => 'Inventory, warehouses, items & stock movements',
    'url' => env('APP_URL_INVENTORY', '/inventory/dashboard'),
    'monogram' => 'ST',
    'color' => '#F59E0B',
    'icon' => 'fas fa-boxes-stacked',
    'status' => 'live',
],
```

## 2. SSO bounce endpoint (routes/web.php + a controller)

Add an `/sso/inventory` route mirroring `/sso/finance` (FinanceSsoController).
It mints a handoff token with `context = 'inventory'` and redirects back to the
SolaStock URL with `?handoff=…`. The token format is already what SolaStock
verifies (AES-256-CBC, key = sha256(`services.workspace.handoff_secret`), payload
`{user_id, client_id, organization_id, context, exp, nonce}`).

```php
// routes/web.php
Route::get('/sso/inventory', \App\Http\Controllers\Auth\InventorySsoController::class)
    ->middleware('web')->name('sso.inventory');
```

`InventorySsoController` = a copy of `FinanceSsoController` with:
- `normalizeTo()` validating `/inventory/...` URLs (same-origin guard),
- `buildHandoffToken($request, 'inventory')`.

## 3. Shared secret + env (both sides)

The handoff secret must match on both apps:

- Central: `services.workspace.handoff_secret` (or `APP_KEY` fallback).
- SolaStock `.env`: `WORKSPACE_HANDOFF_SECRET=<same value>` and
  `PARENT_BASE_URL=https://solavel.com`, then `INVENTORY_SSO_ENABLED=true`.

## Tenant model (no central change)

SolaStock resolves the **shared** `tenant_{clientId}` database (same as Finance/
Projects/HR) and migrates **only its own** `stock_*` / inventory tables there
under marker `migrated_at_inv`. It never reads or writes Finance/Projects tables.
First-run provisioning is handled inside SolaStock
(`POST /inventory/api/v1/tenant/provision`, admin-only) or via
`scripts/setup-demo-tenant.sh` for the demo tenant.
