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
    'legacy_journal_contract_enabled' => env('SOLABOOKS_LEGACY_JOURNAL_CONTRACT_ENABLED', false),
    'historical_repair_enabled' => env('SOLABOOKS_HISTORICAL_REPAIR_ENABLED', false),
    'pending_event_replay_enabled' => env('SOLABOOKS_PENDING_EVENT_REPLAY_ENABLED', false),
    // Exact Phase 6A exception. The global delivery/worker holds remain false;
    // only this tenant and SolaStock organization may use the dedicated v2
    // transport during the controlled UAT window.
    'phase6a_uat' => [
        'enabled' => env('SOLASTOCK_PHASE6A_UAT_DELIVERY_ENABLED', false),
        'tenant_database' => env('SOLASTOCK_PHASE6A_UAT_TENANT_DATABASE'),
        'organization_id' => (int) env('SOLASTOCK_PHASE6A_UAT_ORGANIZATION_ID', 0),
    ],
    'reason' => 'currency_contract_maintenance',
];
