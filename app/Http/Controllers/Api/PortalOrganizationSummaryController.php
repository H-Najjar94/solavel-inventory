<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\VerifyPortalSummarySignature;
use App\Services\Portal\OrganizationSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Read-only operational totals. Signed Central authority is checked before connecting. */
final class PortalOrganizationSummaryController
{
    public function __invoke(Request $request, OrganizationSummary $summary)
    {
        $input = $request->validate([
            'client_id'=>'required|integer|min:1|max:999999',
            'central_organization_id'=>'required|integer|min:1',
            'actor_id'=>'required|integer|min:1',
            'access'=>'required|array', 'access.allowed'=>'required|accepted',
            'access.owner'=>'required|accepted', 'access.app_key'=>'required|in:'.VerifyPortalSummarySignature::APP_KEY,
            'access.grants'=>'present|array',
        ]);
        // Strict booleans, not truthy strings: this is an authority assertion.
        abort_unless($input['access']['allowed'] === true && $input['access']['owner'] === true, 403);
        $previous = config('database.connections.tenant');
        try {
            $this->connect((int) $input['client_id']);
            $orgId = $summary->mappedOrganization((int) $input['client_id'], (int) $input['central_organization_id'], (int) $input['actor_id']);
            return response()->json([
                'app_key'=>VerifyPortalSummarySignature::APP_KEY,
                'client_id'=>(int) $input['client_id'],
                'central_organization_id'=>(int) $input['central_organization_id'],
                'tenant_organization_id'=>$orgId,
                'observed_at'=>now('UTC')->toIso8601String(),
                'metrics'=>$summary->counts($orgId, $input['access']),
            ])->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $exception) {
            return response()->json(['code'=>'SUMMARY_UNAVAILABLE'], $exception->getStatusCode());
        } catch (\Throwable $exception) {
            // No provisioning, migration, billing refresh, or sensitive exception payload.
            report($exception);
            return response()->json(['code'=>'SUMMARY_UNAVAILABLE'], 503);
        } finally {
            DB::purge('tenant');
            config(['database.connections.tenant'=>$previous]);
        }
    }

    protected function connect(int $clientId): void
    {
        $manager = app(\App\Services\Tenancy\TenantManager::class);
        $manager->switchToDatabase($manager->resolveDatabaseName($clientId));
    }
}
