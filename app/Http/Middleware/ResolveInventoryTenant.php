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
 * For a live org it shares the per-client tenant DB (tenant_{clientId}) and
 * activates ONLY when SolaStock's own tables are migrated there. A live org
 * whose SolaStock tables are missing/unmigrated returns a precise setup state
 * (HTTP 409, code 'setup_required') — it NEVER silently falls back to sample
 * data. No-org / no-access return their own clean codes.
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
                return $this->stop('no_organization', 'You are signed in but no Solavel organization is active.', 409, $s);

            case 'no_access':
                return $this->stop('no_access', 'You do not have access to SolaStock for this organization.', 403, $s);

            case 'needs_activation':
                return $this->stop('needs_activation', 'SolaStock is not enabled for this organization yet.', 409, $s);

            case 'tenant_missing':
            case 'tenant_unmigrated':
            case 'tenant_unreachable':
            case 'demo_setup_required':
                return $this->stop('setup_required', 'SolaStock is not set up for this organization yet.', 409, $s);

            case 'sample_preview':
            default:
                return $this->stop('no_tenant', 'No active organization. Sign in or select the demo tenant to load data.', 409, $s);
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
