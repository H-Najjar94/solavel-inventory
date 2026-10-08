<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Explicit reviewed bindings only; never creates catalogs, posts or replaces identities. */
final class MigrationReferenceController extends ApiController
{
    public function link(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('verified_workspace_action') === 'migration-references.link', 403, 'signed_migration_workspace_required');
        $data = $request->validate([
            'organization_mapping_uuid' => 'required|uuid',
            'entity_type' => 'required|in:unit,category,warehouse,customer,supplier',
            'stock_record_id' => 'required|integer|min:1',
            'finance_record_id' => 'required|integer|min:1',
            'reviewed' => 'required|accepted',
            'evidence' => 'required|string|min:8|max:1000',
            'source_hash' => 'required|string|regex:/^[a-f0-9]{64}$/D',
        ]);
        $mapping = IntegrationOrganizationMapping::query()->where('mapping_uuid', $data['organization_mapping_uuid'])
            ->where('solastock_organization_id', app(OrganizationContext::class)->idOrFail())
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
            ->where('status', 'verified')->where('activation_state', 'active')->firstOrFail();
        [$stockTable, $financeTable] = match ($data['entity_type']) {
            'unit' => ['units', 'inventory_units'],
            'category' => ['item_categories', 'inventory_categories'],
            'warehouse' => ['warehouses', 'inventory_locations'],
            'customer' => ['inventory_customers', 'customers'],
            'supplier' => ['inventory_suppliers', 'suppliers'],
        };
        $this->record($stockTable, $data['stock_record_id'], (int) $mapping->solastock_organization_id, false);
        $this->record($financeTable, $data['finance_record_id'], (int) $mapping->finance_organization_id,
            in_array($data['entity_type'], ['unit', 'category'], true));
        $existing = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('entity_type', $data['entity_type'])->where(fn ($q) => $q
                ->where('solastock_record_id', (string) $data['stock_record_id'])
                ->orWhere('solabooks_record_id', (string) $data['finance_record_id']))->lockForUpdate()->get();
        if ($existing->isNotEmpty()) {
            $same = $existing->count() === 1 && $existing[0]->solastock_record_id === (string) $data['stock_record_id']
                && $existing[0]->solabooks_record_id === (string) $data['finance_record_id']
                && $existing[0]->status === 'verified' && ! $existing[0]->conflict_code && ! $existing[0]->error_state
                && ! $existing[0]->solastock_archived && ! $existing[0]->solabooks_archived;
            abort_unless($same, 409, 'migration_reference_identity_conflict');
            return $this->success(['mapping_uuid' => $existing[0]->mapping_uuid, 'replayed' => true]);
        }
        $record = IntegrationMasterDataMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
            'central_client_id' => $mapping->central_client_id, 'central_organization_id' => $mapping->central_organization_id,
            'finance_organization_id' => $mapping->finance_organization_id, 'solastock_organization_id' => $mapping->solastock_organization_id,
            'entity_type' => $data['entity_type'], 'solastock_record_id' => (string) $data['stock_record_id'],
            'solabooks_record_id' => (string) $data['finance_record_id'], 'status' => 'verified',
            'contract_source_version' => 'reviewed-migration-reference.v1', 'discovery_method' => 'signed_explicit_review',
            'last_verified_at' => now(), 'created_by_user_id' => $request->user()->id, 'updated_by_user_id' => $request->user()->id,
        ]);
        // Evidence and payload hash are committed in the dispatcher's immutable command receipt.
        return $this->success(['mapping_uuid' => $record->mapping_uuid, 'replayed' => false]);
    }

    private function record(string $table, int $id, int $organizationId, bool $shared): void
    {
        $query = DB::connection('tenant')->table($table)->where('id', $id);
        if (Schema::connection('tenant')->hasColumn($table, 'organization_id')) {
            $query->where(function ($q) use ($organizationId, $shared): void {
                $q->where('organization_id', $organizationId);
                if ($shared) $q->orWhereNull('organization_id');
            });
        } else {
            abort_unless($shared, 422, 'migration_reference_scope_unavailable');
        }
        if (Schema::connection('tenant')->hasColumn($table, 'deleted_at')) $query->whereNull('deleted_at');
        if (Schema::connection('tenant')->hasColumn($table, 'is_active')) $query->where('is_active', true);
        abort_unless($query->lockForUpdate()->exists(), 422, 'migration_reference_record_unavailable');
    }
}
