<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\FileStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VerifySolavelSyncSignature
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('local', 'testing') && config('solavel_sync.use_signed_sync', true) === false) {
            return $next($request);
        }

        $secret = (string) config('solavel_sync.secret', '');
        if ($secret === '') {
            return $this->deny(__('inventory.sync.secret_missing'), 'sync_secret_missing', 503);
        }

        $signature = (string) ($request->header('X-Solavel-Signature') ?? '');
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        if ($signature === '') {
            return $this->deny(__('inventory.sync.signature_missing'), 'sync_signature_missing', 403);
        }

        $timestamp = (string) ($request->header('X-Solavel-Timestamp') ?? '');
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return $this->deny(__('inventory.sync.timestamp_invalid'), 'sync_timestamp_invalid', 403);
        }

        if (abs(time() - (int) $timestamp) > (int) config('solavel_sync.allowed_skew_seconds', 300)) {
            return $this->deny(__('inventory.sync.timestamp_stale'), 'sync_timestamp_stale', 403);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->deny(__('inventory.sync.signature_invalid'), 'sync_signature_invalid', 403);
        }

        $nonceKey = 'inventory:sync:nonce:' . hash('sha256', $signature);
        $this->ensureFileCacheDirectory($nonceKey);
        if (! Cache::add($nonceKey, 1, (int) config('solavel_sync.nonce_ttl_seconds', 600))) {
            return response()->json([
                'success' => true,
                'status' => 'duplicate',
                'code' => 'sync_replay_detected',
                'message' => __('inventory.sync.duplicate'),
            ]);
        }

        $allowed = (array) config('solavel_sync.allowed_client_ids', []);
        $clientId = $request->input('client_id', $request->input('tenant_id'));
        if ($allowed !== [] && ($clientId === null || ! in_array((string) $clientId, $allowed, true))) {
            Log::warning('Inventory sync rejected unauthorized client_id.', ['client_id' => $clientId]);
            return $this->deny('client_id not authorized for this instance.', 'sync_client_not_allowed', 403);
        }

        return $next($request);
    }

    private function deny(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }

    private function ensureFileCacheDirectory(string $key): void
    {
        $store = Cache::getStore();
        if (! $store instanceof FileStore) {
            return;
        }

        $directory = dirname($store->path($key));
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
