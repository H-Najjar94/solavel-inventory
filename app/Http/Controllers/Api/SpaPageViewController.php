<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Client-side SPA navigation tracking → Central event log.
 *
 * SolaStock is a React single-page app, so react-router navigations never reach
 * the server and would be invisible in the central admin event log. The shell's
 * router subscription pings this endpoint once per navigation; we forward a
 * single page_view to the central system-event-log ingest, signed with the
 * shared Solavel sync secret (HMAC over timestamp.workspace.body — the same
 * scheme the other apps use, source_app=inventory).
 *
 * Fire-and-forget on the client; fully defensive here — a misconfigured or
 * unreachable Central must never affect the user.
 */
class SpaPageViewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'path' => ['required', 'string', 'max:300'],
        ]);

        try {
            $this->forward($request, $data['name'] ?? null, '/' . ltrim($data['path'], '/'));
        } catch (Throwable $e) {
            // Never surface a tracking failure to the user.
        }

        return response()->json(null, 204);
    }

    private function forward(Request $request, ?string $name, string $path): void
    {
        if (! (bool) config('observability.enabled', true)) {
            return;
        }

        $url    = (string) config('observability.ingest_url', '');
        $secret = (string) config('observability.secret', '');
        if ($url === '' || $secret === '') {
            return; // not configured → silent no-op
        }

        $source    = (string) config('observability.source_app', 'inventory');
        $requestId = (string) ($request->headers->get('X-Request-Id') ?: Str::uuid());
        $user      = $request->user();
        $eventId   = (string) Str::uuid();
        $centralOrgId = (int) ($request->session()->get('selected_central_org_id')
            ?: $request->session()->get('active_organization_id')
            ?: 0);

        $payload = [
            'source_app'     => $source,
            'workspace_slug' => $source,
            'event_id'       => $eventId,
            'event_uuid'     => $eventId,
            'event_type'     => 'page_view',
            'event_action'   => 'viewed',
            'severity'       => 'info',
            'status'         => 'success',
            'message'        => 'user viewed ' . ($name ?: trim($path, '/')),
            'occurred_at'    => now()->toIso8601String(),
            'request_id'     => $requestId,
            // The central org id is seeded into the session by the inventory
            // handoff (selected_central_org_id) — Central resolves the org name
            // from it (and from metadata.central_org_id), exactly like the other
            // apps. Without it the event log shows "-" for organization.
            'organization_id' => $centralOrgId ?: null,
            'user' => [
                'id'    => $user?->getAuthIdentifier(),
                'email' => $user?->email,
                'name'  => $user?->name,
            ],
            'request' => [
                'route_name'  => $name,
                'url'         => rtrim((string) config('app.url'), '/') . $path,
                'path'        => $path,
                'http_method' => 'GET',
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
            ],
            'metadata' => array_filter([
                'spa'           => true,
                'spa_route'     => $name,
                'central_org_id' => $centralOrgId ?: null,
            ], static fn ($v) => $v !== null),
        ];

        $body      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $source . '.' . $body, $secret);

        Http::timeout((int) config('observability.timeout', 15))
            ->withBody($body, 'application/json')
            ->withHeaders([
                'Accept'               => 'application/json',
                'X-Solavel-Workspace'  => $source,
                'X-Solavel-Timestamp'  => $timestamp,
                'X-Solavel-Signature'  => 'sha256=' . $signature,
                'X-Solavel-Request-Id' => $requestId,
            ])
            ->post($url);
    }
}
