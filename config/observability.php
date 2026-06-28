<?php

/*
|--------------------------------------------------------------------------
| Central observability (system event log) forwarding
|--------------------------------------------------------------------------
|
| SolaStock forwards user-facing events (e.g. SPA navigation page_views) to
| the central system-event-log ingest endpoint, signed with the shared
| Solavel sync secret — the same HMAC scheme the other apps use
| (timestamp.workspace.body). All values are optional; when the URL or secret
| is empty, forwarding is a silent no-op.
|
*/

return [
    'enabled'     => (bool) env('WORKSPACE_EVENT_LOGGING_ENABLED', true),
    'source_app'  => env('WORKSPACE_SOURCE_APP', 'inventory'),
    'ingest_url'  => env('CENTRAL_EVENT_LOG_INGEST_URL'),
    'secret'      => env('CENTRAL_EVENT_LOG_SECRET', env('SOLAVEL_SYNC_SECRET')),
    'timeout'     => (int) env('PARENT_HTTP_TIMEOUT', 15),
];
