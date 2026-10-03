<?php

use Illuminate\Support\Facades\Route;

// Stateless, read-only, service-authenticated; deliberately outside browser middleware.
Route::post('/api/portal/organization-summary', \App\Http\Controllers\Api\PortalOrganizationSummaryController::class)
    ->middleware(\App\Http\Middleware\VerifyPortalSummarySignature::class)
    ->name('api.portal.organization-summary');

Route::get('/internal/health', \App\Http\Controllers\Internal\HealthController::class)
    ->middleware('internal.health')
    ->name('internal.health');
