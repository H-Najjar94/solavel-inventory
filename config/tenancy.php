<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SolaStock Tenancy (database-per-tenant) — mirrors solavel-finance
    |--------------------------------------------------------------------------
    | Same keys/shape as Finance's config/tenancy.php so conventions match across
    | the Solavel suite. Inventory uses its OWN databases and credentials.
    */

    // Central ("landlord") connection — the tenant registry lives here.
    // Finance uses the 'mysql' connection as central; we keep that name.
    'central_connection' => env('CENTRAL_CONNECTION', 'mysql'),

    // Connection reconfigured at runtime to point at the active tenant DB.
    'tenant_connection' => env('TENANT_CONNECTION', 'tenant'),

    // Tenant DB host & port (fall back to the primary DB host/port).
    'db_host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
    'db_port' => env('TENANT_DB_PORT', env('DB_PORT', 3306)),

    // Elevated connection for CREATE DATABASE / CREATE USER / GRANT.
    'admin_connection' => env('TENANT_ADMIN_CONNECTION', 'mysql_admin'),

    // Elevated connection used to RUN tenant migrations during provisioning.
    // Kept SEPARATE from `tenant_connection` (the runtime path) so the runtime
    // DB user can later be reduced to DML-only without breaking new-client
    // activation. Defaults to `tenant_admin`, which today uses the same admin
    // credentials already configured — so there is no behaviour change until the
    // privilege-separation runbook is executed. See
    // docs/runbooks/DB_PRIVILEGE_SEPARATION.md.
    'provision_connection' => env('TENANT_PROVISION_CONNECTION', 'tenant_admin'),

    // Bootstrap connection — the ONLY connection allowed to create/drop tenant
    // DB users and grant/revoke their privileges (Model A platform standard).
    // SolaStock does not create per-tenant users, so this is UNUSED here; it
    // exists for env/config parity across all apps. Defaults to `tenant_bootstrap`
    // whose creds fall back to the admin account — no behaviour change.
    'bootstrap_connection' => env('TENANT_BOOTSTRAP_CONNECTION', 'tenant_bootstrap'),

    // Phase flag (Model A). FALSE (Phase A / today) vs TRUE (Phase B / DML-only).
    // Unused on SolaStock (no per-tenant user) but kept for platform parity.
    'tenant_user_dml_only' => (bool) env('TENANT_USER_DML_ONLY', false),

    // Option A alignment — DEFAULT OFF. When FALSE (today/prod), SolaStock's
    // runtime tenant connection keeps the shared DB_USERNAME and TenantManager
    // only switches the database (no behaviour change). When TRUE, TenantManager
    // sets the runtime connection's username/password to the deterministic
    // per-tenant user (t_XXXXXX) derived from the selected tenant DATABASE NAME —
    // the same users Books/Projects/HR already use on the shared tenant_XXXXXX DB.
    // Requires TENANT_DERIVE_SECRET (== the value used by the other apps). If the
    // secret is missing or credentials cannot be derived, the request FAILS CLOSED
    // (503) — it NEVER falls back to the shared/superuser DB user. Enabling this in
    // production needs a separate, approved .env-secret + flag window.
    'use_derived_db_user' => (bool) env('INVENTORY_USE_DERIVED_TENANT_DB_USER', false),

    // Charset/collation for tenant databases.
    'db_charset' => env('TENANT_DB_CHARSET', 'utf8mb4'),
    'db_collation' => env('TENANT_DB_COLLATION', 'utf8mb4_unicode_ci'),

    // Host from which tenant DB users may connect.
    'db_user_host' => env('TENANT_DB_USER_HOST', '%'),

    // Secret used to derive per-tenant DB credentials (tenant_dynamic).
    'derive_secret' => env('TENANT_DERIVE_SECRET'),

    // Production tenant DB name prefix. SolaStock shares the SAME per-client
    // tenant database as Finance/Projects/HR (tenant_{clientId}) and owns ONLY
    // its own stock_*/inventory tables there (marker migrated_at_inv). It NEVER
    // reads or writes Finance/Projects tables. Matches the live co-tenant model.
    'database_prefix' => env('TENANT_DB_PREFIX', 'tenant_'),

    // Zero-pad width for tenant ids inside DB names.
    'database_pad' => (int) env('TENANT_DB_PAD', 6),

    // Tenant (inventory) migrations live here.
    'migrations_path' => env('TENANT_MIGRATIONS_PATH', 'database/migrations/tenant'),

    // Central/landlord migrations live here.
    'central_migrations_path' => env('CENTRAL_MIGRATIONS_PATH', 'database/migrations/landlord'),

    // Only SolaStock's own tables are marked migrated under this key, so its
    // migrations are independent of Finance's (migrated_at_fnc) in the shared DB.
    'migration_marker_key' => env('TENANT_MIGRATION_MARKER_KEY', 'migrated_at_inv'),

    // Shared secret to verify SSO handoff tokens from the central Solavel app
    // (must equal the parent's services.workspace.handoff_secret).
    'workspace_handoff_secret' => env('WORKSPACE_HANDOFF_SECRET', env('APP_KEY')),

    // Parent (central Solavel) base URL + this app's SSO bounce path.
    'parent_base_url' => env('PARENT_BASE_URL', 'https://solavel.com'),
    'sso_path' => env('INVENTORY_SSO_PATH', '/sso/inventory'),
];
