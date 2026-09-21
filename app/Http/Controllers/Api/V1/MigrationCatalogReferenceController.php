<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\Unit;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Resolves one Finance-owned reference for the signed catalog-item saga only. */
final class MigrationCatalogReferenceController extends ApiController
{
    public function ensure(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('verified_workspace_action') === 'catalog-references.ensure', 403, 'signed_migration_workspace_required');
        $scope = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', app(OrganizationContext::class)->idOrFail())
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
            ->where('status', 'verified')->where('activation_state', 'active')->firstOrFail();
        $data = $request->validate([
            'type' => 'required|in:category,unit',
            'finance_id' => 'required|integer|min:1',
            'name' => 'required|string|max:100',
            'symbol' => 'nullable|string|max:10',
            'parent_finance_id' => 'nullable|integer|min:1',
        ]);
        $type = $data['type'];
        $financeId = (string) $data['finance_id'];
        $mapped = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $scope->mapping_uuid)
            ->where('entity_type', $type)->where('solabooks_record_id', $financeId)->get();
        abort_if($mapped->count() > 1, 409, 'catalog_reference_mapping_conflict');
        if ($mapped->isNotEmpty()) {
            $record = $mapped->first();
            abort_unless($record->status === 'verified' && ! $record->conflict_code && ! $record->error_state
                && ! $record->solastock_archived && ! $record->solabooks_archived, 409, 'catalog_reference_mapping_requires_review');
            $owner = $type === 'category' ? ItemCategory::query()->find($record->solastock_record_id) : Unit::query()->find($record->solastock_record_id);
            abort_unless($owner && $owner->is_active, 409, 'catalog_reference_mapping_requires_review');
            return $this->success(['type' => $type, 'finance_id' => (int) $financeId,
                'stock_id' => (int) $owner->id, 'mapping_uuid' => $record->mapping_uuid, 'reused' => true]);
        }

        $name = trim($data['name']);
        abort_if($name === '', 422, 'catalog_reference_name_required');
        $parentId = null;
        if ($type === 'category' && ! empty($data['parent_finance_id'])) {
            $parent = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $scope->mapping_uuid)
                ->where('entity_type', 'category')->where('solabooks_record_id', (string) $data['parent_finance_id'])
                ->where('status', 'verified')->whereNull('conflict_code')->whereNull('error_state')->first();
            abort_unless($parent && ItemCategory::query()->whereKey($parent->solastock_record_id)->where('is_active', true)->exists(), 422, 'catalog_parent_mapping_required');
            $parentId = (int) $parent->solastock_record_id;
        }
        if ($type === 'category') {
            $candidates = ItemCategory::query()->where('name', $name)->where('parent_id', $parentId)->get();
        } else {
            $symbol = trim((string) ($data['symbol'] ?? ''));
            abort_if($symbol === '', 422, 'catalog_unit_symbol_required');
            $code = 'FNC-'.$financeId;
            $candidates = Unit::query()->where(fn ($q) => $q->where('name', $name)->orWhere('symbol', $symbol)->orWhere('code', $code))->get();
        }
        abort_if($candidates->count() > 1, 409, 'catalog_reference_ambiguous_match');
        $owner = $candidates->first();
        if ($owner) {
            abort_unless($owner->is_active, 409, 'catalog_reference_inactive_match');
            if ($type === 'unit') {
                abort_unless($owner->name === $name && $owner->symbol === $symbol, 409, 'catalog_reference_ambiguous_match');
            }
            abort_if(IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $scope->mapping_uuid)
                ->where('entity_type', $type)->where('solastock_record_id', (string) $owner->id)->exists(), 409, 'catalog_reference_existing_binding');
        } elseif ($type === 'category') {
            $owner = ItemCategory::query()->create(['name' => $name, 'parent_id' => $parentId,
                'level' => $parentId ? ((int) ItemCategory::query()->findOrFail($parentId)->level + 1) : 0, 'is_active' => true]);
        } else {
            $owner = Unit::query()->create(['name' => $name, 'symbol' => $symbol, 'code' => $code,
                'kind' => 'count', 'is_active' => true]);
        }
        $record = IntegrationMasterDataMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $scope->mapping_uuid,
            'central_client_id' => $scope->central_client_id, 'central_organization_id' => $scope->central_organization_id,
            'finance_organization_id' => $scope->finance_organization_id, 'solastock_organization_id' => $scope->solastock_organization_id,
            'entity_type' => $type, 'solastock_record_id' => (string) $owner->id, 'solabooks_record_id' => $financeId,
            'status' => 'verified', 'contract_source_version' => 'migration-catalog.v1',
            'discovery_method' => 'signed_finance_catalog_reference', 'last_verified_at' => now(),
            'created_by_user_id' => $request->user()->id, 'updated_by_user_id' => $request->user()->id,
        ]);
        return $this->success(['type' => $type, 'finance_id' => (int) $financeId,
            'stock_id' => (int) $owner->id, 'mapping_uuid' => $record->mapping_uuid, 'reused' => false], 201);
    }
}
