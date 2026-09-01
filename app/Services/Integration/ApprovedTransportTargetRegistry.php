<?php

namespace App\Services\Integration;

use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/** Server-owned worker allowlist derived from Central's verified organization state. */
class ApprovedTransportTargetRegistry
{
    public function __construct(private readonly TenantManager $tenants) {}

    /** @return list<array{client_id:int,organization_id:int,database:string,plan:string}> */
    public function targets(): array
    {
        return $this->targetsFromRows(
            DB::connection('mysql')->table('entitlement_state_snapshots')->orderBy('organization_id')->get()
        );
    }

    /** @param iterable<object> $rows
     *  @return list<array{client_id:int,organization_id:int,database:string,plan:string}>
     */
    public function targetsFromRows(iterable $rows): array
    {
        $targets = [];
        foreach ($rows as $row) {
            $state = json_decode((string) ($row->state_payload ?? ''), true);
            if (! is_array($state)) {
                continue;
            }
            $clientId = (int) ($state['client_id'] ?? 0);
            $organizationId = (int) ($state['organization_id'] ?? 0);
            $plan = strtolower(trim((string) ($state['plan_code'] ?? '')));
            $apps = array_map('strtolower', (array) ($state['accessible_apps'] ?? []));
            $deliveryApproved = data_get($state, 'integration_capabilities.connection_activation_delivery_entitled') === true;

            if ($clientId < 1 || $organizationId < 1
                || (int) ($row->organization_id ?? 0) !== $organizationId
                || ! in_array($plan, ['advanced', 'enterprise'], true)
                || (string) ($state['effective_access_state'] ?? '') !== 'paid_active'
                || ! $deliveryApproved
                || ! in_array('finance', $apps, true)
                || ! in_array('inventory', $apps, true)) {
                continue;
            }

            $database = $this->tenants->resolveDatabaseName($clientId);
            if (preg_match('/^tenant_[0-9]{6}$/D', $database) !== 1) {
                continue;
            }
            $targets[$database.':'.$organizationId] = compact('clientId', 'organizationId', 'database', 'plan');
        }

        return array_values(array_map(static fn (array $target): array => [
            'client_id' => $target['clientId'],
            'organization_id' => $target['organizationId'],
            'database' => $target['database'],
            'plan' => $target['plan'],
        ], $targets));
    }
}
