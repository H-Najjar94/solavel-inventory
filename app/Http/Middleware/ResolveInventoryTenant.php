<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\LiveTenantResolver;
use App\Services\Tenancy\TenantManager;
use App\Services\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the SolaStock tenant the Solavel way and switches the tenant DB.
 *
 * Precedence: LIVE central org (SSO-seeded session) → operator demo → none.
 * For a live org it shares the per-client tenant DB (tenant_{clientId}). Customer
 * access follows central enablement and permissions; admin diagnostics never act
 * as a second launch gate. No-org / no-access return their own clean codes.
 */
class ResolveInventoryTenant
{
    public function __construct(
        private LiveTenantResolver $live,
        private TenantResolver $demo,
        private TenantManager $tenants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $s = $this->live->state($request);
        $request->attributes->set('tenant_state', $s);
        $request->attributes->set('tenant_mode', $s['mode']);

        switch ($s['state']) {
            case 'live_ready':
                // DB is keyed by client_id (tenant_{clientId}); the org context
                // (row scope) is the actual organization_id, which may differ.
                $this->tenants->useTenant((int) $s['organization_id'], $s['database']);
                break;

            case 'demo_preview':
                $this->demo->activate(['mode' => 'demo', 'org_id' => (int) $s['organization_id'], 'database' => $s['database']]);
                break;

            case 'no_organization':
                return $this->stop('no_organization', __('inventory.tenancy.no_organization'), 409, $s);

            case 'no_access':
                return $this->stop('no_access', __('inventory.tenancy.no_access'), 403, $s);

            case 'needs_activation':
                return $this->stop('needs_activation', __('inventory.tenancy.needs_activation'), 409, $s);

            case 'schema_failed':
                return $this->stop('schema_failed', __('inventory.tenancy.schema_failed'), 409, $s);

            case 'tenant_missing':
            case 'tenant_unmigrated':
            case 'tenant_unreachable':
            case 'demo_setup_required':
                return $this->stop('setup_required', __('inventory.tenancy.setup_required'), 409, $s);

            case 'sample_preview':
            default:
                return $this->stop('no_tenant', __('inventory.tenancy.no_tenant'), 409, $s);
        }

        return $next($request);
    }

    private function stop(string $code, string $message, int $status, array $s): Response
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'state' => $s['state'],
                'reason' => $s['reason'] ?? null,
                'database' => $s['database'] ?? null,
                'can_provision' => $s['can_provision'] ?? false,
                'demo_available' => $this->demo->demoEnabled(),
            ],
        ], $status);
    }
}
