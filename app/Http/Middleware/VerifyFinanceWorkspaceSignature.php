<?php

namespace App\Http\Middleware;

use App\Services\InventoryWorkspace\WorkspaceSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class VerifyFinanceWorkspaceSignature
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('finance_workspace.secret', '');
        abort_unless(strlen($secret) >= 32, 503, 'workspace_connection_unconfigured');
        abort_unless($request->isMethod('POST') && $request->getQueryString() === null
            && strlen($request->getContent()) <= 262144, 400, 'workspace_request_invalid');
        $nonce = (string) $request->header('X-Workspace-Nonce', '');
        abort_unless(WorkspaceSignature::verifies($request->getContent(),
            (string) $request->header('X-Workspace-Timestamp', ''), $nonce,
            (string) $request->header('X-Workspace-Signature', ''), $secret, time()), 403, 'workspace_signature_invalid');
        abort_unless(Cache::add('finance-workspace:nonce:'.hash('sha256', $nonce), 1, 610), 409, 'workspace_replay_detected');

        return $next($request);
    }
}
