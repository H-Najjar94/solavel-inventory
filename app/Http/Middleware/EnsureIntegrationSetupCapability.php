<?php

namespace App\Http\Middleware;

use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fail-closed commercial capability gate for non-operational wizard drafts. */
class EnsureIntegrationSetupCapability
{
    public function __construct(
        private OrganizationContext $context,
        private InventoryCommercialEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $decision = $this->entitlements->checkConnectionSetupReadiness($this->context->idOrFail());
        if (! $decision['allowed']) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'connection_setup_readiness_required',
                    'message' => __('inventory.integration.wizard.setupUnavailable'),
                    'reason_code' => $decision['reason_code'],
                ],
            ], 403);
        }

        return $next($request);
    }
}
