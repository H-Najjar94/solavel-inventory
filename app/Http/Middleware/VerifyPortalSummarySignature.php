<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Strict, app-bound service authentication. Never accepts cookies or bearer fallbacks. */
final class VerifyPortalSummarySignature
{
    public const APP_KEY = 'inventory';
    public const PATH = '/api/portal/organization-summary';

    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('solavel_sync.secret');
        if (strlen($secret) < 32) return response()->json(['code'=>'SUMMARY_UNAVAILABLE'], 503);
        $timestamp = (string) $request->header('X-Portal-Timestamp', '');
        $nonce = (string) $request->header('X-Portal-Nonce', '');
        $signature = (string) $request->header('X-Portal-Signature', '');
        if ($request->method() !== 'POST' || ! preg_match('/\A[0-9]{10}\z/', $timestamp)
            || abs(time() - (int) $timestamp) > 60
            || ! preg_match('/\A[a-f0-9]{32}\z/', $nonce)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return response()->json(['code'=>'SUMMARY_UNAUTHORIZED'], 401);
        }
        $canonical = implode("\n", ['POST', self::PATH, self::APP_KEY, $timestamp, $nonce, hash('sha256', $request->getContent())]);
        if (! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return response()->json(['code'=>'SUMMARY_UNAUTHORIZED'], 401);
        }
        $allowed = (array) config('solavel_sync.allowed_client_ids', []);
        if ($allowed !== [] && ! in_array((int) $request->input('client_id'), array_map('intval', $allowed), true)) {
            return response()->json(['code'=>'SUMMARY_FORBIDDEN'], 403);
        }
        if (! Cache::add('portal-summary:'.self::APP_KEY.':'.hash('sha256', $nonce), true, 125)) {
            return response()->json(['code'=>'SUMMARY_REPLAY'], 409);
        }
        return $next($request);
    }
}
