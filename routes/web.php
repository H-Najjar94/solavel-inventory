<?php

use Illuminate\Support\Facades\Route;

// Routes are written prefix-less. Apache aliases /inventory/* to this app's
// public/, stripping the /inventory prefix, so Laravel sees /dashboard etc.
// The live URLs are https://solavel.com/inventory/<path>.

// Root → dashboard (mirrors solavel-projects: redirect '/' to the dashboard).
Route::get('/', fn () => redirect()->route('inventory.dashboard'));

// Main app page: https://solavel.com/inventory/dashboard
// The Vite React SPA. Catch-all sub-paths so React Router handles client routes
// (dashboard, items, warehouses, …) on deep links / refresh.
Route::view('/dashboard', 'solastock-app')->name('inventory.dashboard');
Route::view('/dashboard/{any}', 'solastock-app')->where('any', '.*');
Route::view('/items/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/warehouses/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/suppliers/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/balances', 'solastock-app');
Route::view('/opening-stock/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/adjustments/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/transfers/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/counts/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/purchase-orders/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/goods-receipts/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/sales-orders/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/pick-lists/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/packs/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/shipments/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/sales-returns/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/traceability/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/recalls/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/reports', 'solastock-app');
Route::view('/settings/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/integrations/{any?}', 'solastock-app')->where('any', '.*');
Route::view('/ledger', 'solastock-app');

// NOTE: Onboarding/activation is owned entirely by the CENTRAL app
// (https://solavel.com/inventory/onboarding — a full multi-step wizard that
// mirrors SolaProjects). Apache's `AliasMatch ^/inventory/onboarding` sends
// that path to central before the `/inventory/` alias reaches this app, so we
// deliberately do NOT define an /onboarding route here — it would only ever be
// a never-reached duplicate. The "Set up SolaStock" hero links straight to the
// central wizard.

// Back-compat: the original CDN/Babel demo (untouched) stays available.
Route::view('/solastock', 'solastock')->name('solastock.demo');
