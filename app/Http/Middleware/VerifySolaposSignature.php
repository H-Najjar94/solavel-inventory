<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Signed inbound requests from SolaPOS. Same scheme as VerifySolavelSyncSignature
 * (HMAC-SHA256 over "timestamp.body", ±300 s, nonce/replay via cache) but with a
 * SEPARATE secret so SolaPOS cannot forge Central sync events and vice versa.
 */
class VerifySolaposSignature
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('solavel_sync.solapos_secret', '');
        if (strlen($secret) < 32) {
            return $this->deny('SolaPOS integration secret is not configured.', 'solapos_secret_missing', 503);
        }
        $signature = (string) $request->header('X-Solavel-Signature', '');
        $timestamp = (string) $request->header('X-Solavel-Timestamp', '');
        $nonce = (string) $request->header('X-Solavel-Nonce', '');
        if ($signature === '' || $timestamp === '' || ! ctype_digit($timestamp) || $nonce === '') {
            return $this->deny('A valid signed request is required.', 'solapos_signature_invalid', 403);
        }
        if (abs(time() - (int) $timestamp) > (int) config('solavel_sync.allowed_skew_seconds', 300)) {
            return $this->deny('Signature timestamp is stale.', 'solapos_timestamp_stale', 403);
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            Log::warning('SolaPOS integration signature rejected.', ['ip' => $request->ip()]);

            return $this->deny('A valid signed request is required.', 'solapos_signature_invalid', 403);
        }
        if (! Cache::add('inventory:solapos:nonce:'.hash('sha256', $nonce), 1, (int) config('solavel_sync.nonce_ttl_seconds', 600))) {
            return $this->deny('Duplicate signed request.', 'solapos_signature_replayed', 409);
        }
        $clientId = (string) $request->header('X-Solavel-Central-Client-Id', '');
        $allowed = (array) config('solavel_sync.allowed_client_ids', []);
        if ($allowed !== [] && ! in_array($clientId, $allowed, true)) {
            return $this->deny('client_id not authorized for this instance.', 'solapos_client_not_allowed', 403);
        }

        return $next($request);
    }

    private function deny(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
