<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireInternalHealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('system_health.internal_token', '');
        $provided = (string) $request->header('X-Internal-Health-Token', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return $next($request);
    }
}

