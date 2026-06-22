<?php

/*
|--------------------------------------------------------------------------
| SolaBooks (Finance) integration — PLACEHOLDER CONFIG
|--------------------------------------------------------------------------
| No integration is implemented yet. This defines the contract surface so the
| UI can show connection/mapping/sync status and so the future outbox worker has
| a stable config. Stock stays system-of-record in SolaStock; accounting events
| will be emitted to SolaBooks later (events listed below).
*/

return [
    'enabled' => env('SOLABOOKS_ENABLED', false),
    'base_url' => env('SOLABOOKS_BASE_URL', env('CENTRAL_APP_URL', 'https://solavel.com').'/finance'),

    // Account *refs* (opaque Finance account ids/codes). Mirrors Finance's
    // OrgAccountDefaults; resolved per org/category/item later.
    'account_mappings' => [
        'inventory_asset' => env('SOLABOOKS_INVENTORY_ASSET', null),
        'cogs' => env('SOLABOOKS_COGS', null),
        'adjustment_gain' => env('SOLABOOKS_ADJ_GAIN', null),
        'adjustment_loss' => env('SOLABOOKS_ADJ_LOSS', null),
        'grni_accrual' => env('SOLABOOKS_GRNI', null), // goods-received-not-invoiced
        'opening_equity' => env('SOLABOOKS_OPENING_EQUITY', null),
    ],

    // Events the outbox will emit to SolaBooks (none wired yet).
    'events' => [
        'opening_stock.posted',
        'grn.posted',
        'adjustment.posted',
        'transfer.posted',
        'shipment.posted',
        'return.posted',
        'landed_cost.posted',
        'count_variance.posted',
    ],
];
