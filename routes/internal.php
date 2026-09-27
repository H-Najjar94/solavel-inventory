<?php

use Illuminate\Support\Facades\Route;

Route::get('/internal/health', \App\Http\Controllers\Internal\HealthController::class)
    ->middleware('internal.health')
    ->name('internal.health');

