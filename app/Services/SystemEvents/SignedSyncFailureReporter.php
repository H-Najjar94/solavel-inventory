<?php
namespace App\Services\SystemEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class SignedSyncFailureReporter
{
    public static function report(Request $request, int $status, ?Throwable $exception = null): void
    {
        $context = VerifiedProvisioningContext::get($request);
        if ($context === []) return;
        // Capture immutable request-local data before deferred delivery.
        $payload = $context + [
            'source_app' => 'inventory', 'workspace_slug' => 'inventory',
            'event_id' => (string) Str::uuid(), 'event_type' => 'sync_failure',
            'event_action' => 'failed', 'severity' => 'error', 'status' => 'failed',
            'message' => 'Signed inventory synchronization failed.',
            'occurred_at' => now()->toIso8601String(),
            'request' => ['path' => '/'.ltrim($request->path(), '/'), 'http_method' => $request->method(), 'http_status' => $status],
        ];
        if ($exception) $payload['metadata']['exception_class'] = $exception::class;
        app()->terminating(static function () use ($payload): void {
            try {
                $url = (string) config('observability.ingest_url', '');
                $secret = (string) config('observability.secret', '');
                if (! config('observability.enabled', true) || $url === '' || $secret === '') return;
                $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $timestamp = (string) time();
                Http::timeout(5)->withBody($body, 'application/json')->withHeaders([
                    'Accept' => 'application/json', 'X-Solavel-Workspace' => 'inventory',
                    'X-Solavel-Timestamp' => $timestamp,
                    'X-Solavel-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.inventory.'.$body, $secret),
                ])->post($url);
            } catch (Throwable) { /* Observability cannot alter provisioning results. */ }
        });
    }
}
