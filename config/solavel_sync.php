<?php

return [
    'secret' => env('SOLAVEL_SYNC_SECRET'),
    // Separate secret for signed SolaPOS → SolaStock consumption events (Phase 6).
    'solapos_secret' => env('SOLAPOS_INTEGRATION_SECRET'),
    'allowed_skew_seconds' => (int) env('SOLAVEL_SYNC_ALLOWED_SKEW_SECONDS', 300),
    'nonce_ttl_seconds' => (int) env('SOLAVEL_SYNC_NONCE_TTL_SECONDS', 600),
    'allowed_client_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SOLAVEL_SYNC_ALLOWED_CLIENT_IDS', ''))
    ), fn ($value) => $value !== '')),
    'use_signed_sync' => (bool) env('SOLAVEL_REFACTOR_USE_SIGNED_SYNC', true),
];
