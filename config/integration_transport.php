<?php

return [
    'worker_enabled' => env('SOLASTOCK_TRANSPORT_WORKER_ENABLED', false),
    'reconciliation_schedule_enabled' => env('SOLASTOCK_RECONCILIATION_SCHEDULE_ENABLED', false),
    'contract_version' => 'solastock-journal.v2',
    'lease_seconds' => (int) env('SOLASTOCK_TRANSPORT_LEASE_SECONDS', 90),
    'clock_tolerance_seconds' => (int) env('SOLASTOCK_TRANSPORT_CLOCK_TOLERANCE_SECONDS', 10),
    'max_attempts' => (int) env('SOLASTOCK_TRANSPORT_MAX_ATTEMPTS', 8),
    'base_backoff_seconds' => (int) env('SOLASTOCK_TRANSPORT_BASE_BACKOFF_SECONDS', 30),
    'max_backoff_seconds' => (int) env('SOLASTOCK_TRANSPORT_MAX_BACKOFF_SECONDS', 3600),
    'jitter_percent' => (int) env('SOLASTOCK_TRANSPORT_JITTER_PERCENT', 20),
    'worker' => [
        'queue' => 'solastock-finance-v2',
        'concurrency' => (int) env('SOLASTOCK_TRANSPORT_CONCURRENCY', 2),
        'timeout_seconds' => (int) env('SOLASTOCK_TRANSPORT_TIMEOUT_SECONDS', 60),
        'memory_mb' => (int) env('SOLASTOCK_TRANSPORT_MEMORY_MB', 192),
        'max_jobs' => (int) env('SOLASTOCK_TRANSPORT_MAX_JOBS', 250),
        'graceful_shutdown_seconds' => 30,
    ],
    'supervisor' => [
        'heartbeat_path' => env(
            'SOLASTOCK_TRANSPORT_HEARTBEAT_PATH',
            '/var/lib/solavel/solastock-finance-v2/heartbeat.json'
        ),
    ],
];
