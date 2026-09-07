<?php

use App\Http\Controllers\Api\FinanceWorkspaceController;
use App\Http\Middleware\VerifyFinanceWorkspaceSignature;
use Illuminate\Support\Facades\Route;

Route::post('/api/internal/finance-workspace', FinanceWorkspaceController::class)
    ->middleware(VerifyFinanceWorkspaceSignature::class)->name('internal.finance-workspace');
