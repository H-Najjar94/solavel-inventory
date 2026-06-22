<?php

/*
|--------------------------------------------------------------------------
| SolaStock (Inventory) app config
|--------------------------------------------------------------------------
*/

return [
    // Dev fallback role used by InventoryPermissionService until central
    // permission sync lands.
    'dev_default_role' => env('INVENTORY_DEV_DEFAULT_ROLE', 'inventory_admin'),

    /*
    | Safe DEMO tenant for development/QA. NOT production, NOT Finance, NOT the
    | reserved Finance/Projects test DBs. Lets the app run with real API data
    | against an isolated Inventory demo database when no real SSO tenant exists.
    |
    | Disabled by default in production. Enable explicitly with
    | INVENTORY_DEMO_TENANT_ENABLED=true (e.g. on a staging/dev box).
    */
    'demo_tenant' => [
        // The demo tenant is a dev/operator sandbox. It is HARD-DISABLED in
        // production (no env override can turn it on) so an unauthenticated
        // visitor can never flip their session into the demo tenant and read /
        // write demo data on the live host. In non-prod it defaults on.
        'enabled' => env('APP_ENV') !== 'production'
            && (bool) env('INVENTORY_DEMO_TENANT_ENABLED', true),
        // Reserved Inventory demo org id + database (same safe namespace the
        // tenancy suite reserves; never tenant_990001/2 = Finance/Projects).
        'organization_id' => (int) env('INVENTORY_DEMO_ORG_ID', 990010),
        'database' => env('INVENTORY_DEMO_DB', 'tenant_990010'),
        'label' => 'Demo tenant',
    ],

    // Hard deny-list: org databases the demo resolver must NEVER select.
    'forbidden_demo_databases' => [
        'tenant_990001', // Finance test tenant
        'tenant_990002', // Projects test tenant
        'solavel_inventory', 'solavel_finance', 'solavel',
    ],

    /*
    | SSO with the central Solavel app. When enabled, an unauthenticated HTML
    | navigation is bounced to {parent}/sso/inventory, which mints a handoff
    | token (the same format Finance/Projects/HR use) and redirects back. Off by
    | default so demo/sample flows work until the central /sso/inventory endpoint
    | is live. The live path NEVER reads or writes Finance/Projects tables — it
    | only owns SolaStock's tables inside the shared tenant_{clientId} database.
    */
    'sso' => [
        'enabled' => env('INVENTORY_SSO_ENABLED', false),
        'app_key' => 'inventory', // central app-launcher slug (config/apps.php)
    ],
];
