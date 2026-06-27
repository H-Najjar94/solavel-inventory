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

    // Optional dual-secret rotation support (#35, mirrors SolaBooks #30 /
    // SolaProjects #33 / SolaHR #34) — OFF by default, so behavior is identical to
    // the single-secret setup today. Both are OPTIONAL: TENANT_DERIVE_PREVIOUS_SECRET
    // is a former secret kept temporarily during a controlled migration window
    // (ignored unless >=32 chars and != primary; never required, never breaks the
    // primary path); TENANT_DERIVE_SECRET_VERSION is a NON-SENSITIVE label (default
    // v1) for safe logging/metrics, never the secret value. Setting these does NOT
    // rotate anything — real rotation is a separate, owner-approved, gated execution.
    // The INVENTORY_USE_DERIVED_TENANT_DB_USER flag behavior is unchanged.
    'derive_previous_secret' => env('TENANT_DERIVE_PREVIOUS_SECRET'),
    'derive_secret_version' => env('TENANT_DERIVE_SECRET_VERSION', 'v1'),

    // Connection-layer previous-secret fallback (#36, mirrors SolaBooks/SolaProjects/
    // SolaHR) — OFF by default. ONLY meaningful when the per-tenant derived DB user is
    // actually in use (INVENTORY_USE_DERIVED_TENANT_DB_USER=true); when the derived
    // user is OFF (default) this is a strict no-op because the runtime connection uses
    // the shared DB user and never a derived password. When this flag is TRUE *and* the
    // derived user is in use *and* a valid TENANT_DERIVE_PREVIOUS_SECRET is configured,
    // the runtime connection setup will — ONLY if the primary-derived password is
    // rejected with an AUTH/access-denied error (SQLSTATE 28000 / MySQL 1045) — retry
    // the connection ONCE with the PREVIOUS-derived password, log SAFE metadata (tenant
    // db, derived user, fallback_used=true, secret version label — never the secret or
    // derived password), and keep the working connection. Any other error, or both
    // passwords failing, preserves the existing fail-closed behavior. Transitional aid
    // only; performs NO rotation by itself.
    'derive_previous_secret_fallback' => (bool) env('TENANT_DERIVE_PREVIOUS_SECRET_FALLBACK', false),

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
