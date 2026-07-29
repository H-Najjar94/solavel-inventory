<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SolaBooks delivery safety hold
    |--------------------------------------------------------------------------
    |
    | Phase 0 keeps delivery disabled in production while the currency contract
    | is repaired. Set true only after the documented Phase 1 approval.
    |
    */
    'solabooks_delivery_enabled' => env('SOLABOOKS_DELIVERY_ENABLED', true),
    // Cross-service status mirror. Deployment verification must prove this
    // matches Finance's enforced legacy-inventory guard before setting true.
    'legacy_finance_inventory_writes_blocked' => env('SOLABOOKS_LEGACY_INVENTORY_WRITES_BLOCKED', false),
    'reason' => 'currency_contract_maintenance',
];
