<?php

return [
    'version' => 'solabooks-solastock.connection-wizard.v1',

    // The wizard is always available for read-only discovery and review.
    // Activation is a separate, staging-only control and cannot be enabled in
    // production by configuration alone.
    'activation_enabled' => (bool) env('SOLASTOCK_WIZARD_ACTIVATION_ENABLED', false),
    // Production additionally requires the separately deployed Phase 6B gate.
    // The approval id is operational metadata, not a secret, and must be exact.
    'production_phase6b_enabled' => (bool) env('SOLASTOCK_WIZARD_PHASE6B_ENABLED', false),
    'activation_approval_id' => (string) env('SOLASTOCK_WIZARD_ACTIVATION_APPROVAL_ID', ''),
    'receiver_confirmed_enabled' => (bool) env('SOLABOOKS_V2_RECEIVER_CONFIRMED_ENABLED', false),
    'activation_organization_allowlist' => array_values(array_filter(array_map(
        static fn (string $value): ?int => ctype_digit(trim($value)) ? (int) trim($value) : null,
        explode(',', (string) env('SOLASTOCK_WIZARD_ACTIVATION_ORGS', ''))
    ))),
    'confirmation_phrase' => 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY',
    'allowed_workflows' => [
        'opening_stock.posted', 'opening_stock.reversed',
        'adjustment.posted', 'adjustment.reversed',
        'grn.posted', 'grn.reversed',
        'shipment.posted', 'sales_return.posted',
        'stock_count.posted',
    ],
];
