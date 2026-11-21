<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier map pins. A supplier linked to SolaCount (verified master-data mapping)
 * shows SolaCount's supplier_locations, read-only from the shared tenant database;
 * nothing is copied into Stock. Unlinked suppliers use `contact.location`.
 */
final class SupplierLocations
{
    public function __construct(private FinanceLink $link) {}

    /** Mapped Finance supplier id, or null when standalone / not readable / unverified. */
    public function financeId(Supplier $supplier): ?int
    {
        if (! $this->link->readable()) {
            return null;
        }
        $id = $this->link->financeIds('supplier', [(int) $supplier->id])[(int) $supplier->id] ?? null;

        return $id ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    public function for(Supplier $supplier): array
    {
        $financeId = $this->financeId($supplier);
        if ($financeId === null) {
            $own = (array) (($supplier->contact ?? [])['location'] ?? []);

            return [
                'linked' => false,
                'edit_url' => null,
                'locations' => isset($own['lat'], $own['lng']) ? [[
                    'id' => null, 'label' => null,
                    'lat' => (float) $own['lat'], 'lng' => (float) $own['lng'],
                    'address' => $own['address'] ?? null,
                ]] : [],
            ];
        }

        $state = $this->link->state();
        $rows = collect();
        if (Schema::connection('tenant')->hasTable('supplier_locations')) {
            $rows = DB::connection('tenant')->table('supplier_locations')
                ->where('supplier_id', $financeId)
                ->where(fn ($q) => $q->where('organization_id', $state['finance_organization_id'])->orWhereNull('organization_id'))
                ->orderBy('id')->get(['id', 'label', 'lat', 'lng', 'address']);
        }

        return [
            'linked' => true,
            'edit_url' => rtrim((string) config('supplier_terms.finance_url'), '/').'/suppliers/'.$financeId.'/edit',
            'locations' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'label' => $r->label,
                'lat' => (float) $r->lat, 'lng' => (float) $r->lng, 'address' => $r->address,
            ])->values()->all(),
        ];
    }
}
