<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationSetting;

/**
 * Read-only: can this organization reverse a posted customer return through SolaCount?
 * It never changes the connection; a missing workflow is added only by the connection wizard.
 */
class ReturnReversalReadiness
{
    /** @return array{state:string,mode:?string,transport_enabled:bool,scope:string} */
    public function forOrganization(int $organizationId): array
    {
        $setting = IntegrationSetting::query()->where('organization_id', $organizationId)
            ->where('integration', IntegrationEvents::INTEGRATION)->first();
        if (! $setting) {
            return ['state' => 'not_connected', 'mode' => null, 'transport_enabled' => false, 'scope' => 'n/a'];
        }
        $stored = data_get($setting->meta, 'transport_enabled_workflows');
        $has = is_array($stored) && in_array('sales_return.reversed', $stored, true);
        $scope = is_array($stored) ? ($has ? 'present' : 'missing') : 'default';

        return [
            'state' => match (true) {
                $setting->mode !== 'active' => 'connection_inactive',
                $scope === 'missing' => 'needs_wizard',
                default => 'ready',
            },
            'mode' => $setting->mode,
            'transport_enabled' => data_get($setting->meta, 'transport_enabled') === true,
            'scope' => $scope,
        ];
    }
}
