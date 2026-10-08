<?php

return [
    'version' => 'solabooks-solastock.connection-wizard.v1',

    // Global service availability. Per-organization delivery still requires
    // exact entitlement, immutable identity, reviews, mapping, credentials,
    // active state and no organization/replay hold.
    'activation_enabled' => (bool) env('SOLASTOCK_WIZARD_ACTIVATION_ENABLED', false),
    'automatic_preparation_enabled' => (bool) env('SOLASTOCK_CONNECTION_AUTOMATIC_PREPARATION_ENABLED', true),
    // Production additionally requires the qualified general-availability gate.
    'production_phase6b_enabled' => (bool) env('SOLASTOCK_WIZARD_PHASE6B_ENABLED', false),
    'receiver_confirmed_enabled' => (bool) env('SOLABOOKS_V2_RECEIVER_CONFIRMED_ENABLED', false),
    'confirmation_phrase' => 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY',
    'allowed_workflows' => [
        'opening_stock.posted', 'opening_stock.reversed',
        'adjustment.posted', 'adjustment.reversed',
        'grn.posted', 'grn.reversed',
        'shipment.posted', 'sales_return.posted',
        'stock_count.posted',
    ],
];
