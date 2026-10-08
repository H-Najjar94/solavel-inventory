<?php
namespace App\Services\Integration;

use App\Models\Tenant\{GoodsReceipt,SalesOrder,Shipment,IntegrationOrganizationMapping,IntegrationMasterDataMapping,IntegrationSetting};
use App\Services\Access\InventoryPermissionService;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{DB,Log,Schema};
use Illuminate\Validation\ValidationException;

/** Resolve native party dependencies before operational posting acquires economic locks. */
final class OperationalPartyReadiness
{
    public function ensure(Model $document): void
    {
        [$type,$permission] = self::operation($document::class);
        $db = DB::connection('tenant');
        if (! Schema::connection('tenant')->hasTable('integration_organization_mappings')) return;
        $org = app(OrganizationContext::class)->idOrFail();
        $mapping = IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)
            ->where('tenant_database_identity',$db->getDatabaseName())
            ->whereIn('status',['verified','verified_hold'])->whereIn('activation_state',['active','maintenance_hold'])->get();
        if ($mapping->isEmpty()) return;
        if ($mapping->count()!==1) throw ValidationException::withMessages(['workflow'=>__('inventory.integration.party_posting_connection_review')]);
        $mapping=$mapping->sole();
        if (! $mapping) return; // Existing native validation decides standalone/connection ownership.
        // Only committed source identities participate. Unsaved caller edits cannot sync another party.
        $native = $document::query()->where('organization_id',$org)->whereKey($document->getKey())->firstOrFail();
        if ($native->status !== 'draft') return;
        $partyId = $type==='supplier' ? (int)$native->supplier_id : ($native instanceof Shipment
            ? (int) SalesOrder::query()->where('organization_id',$org)->whereKey($native->sales_order_id)->value('customer_id')
            : (int)$native->customer_id);
        if ($partyId < 1 || $this->mapped($mapping,$type,$partyId)) return;
        $actor = request()->user();
        abort_unless($actor && (int)$actor->getAuthIdentifier()>0
            && app(InventoryPermissionService::class)->can($actor,$permission),403);
        $ledger = app(PartySyncLedger::class);
        $fields = $ledger->fields($mapping,'stock',$type,$partyId);
        $setting = IntegrationSetting::query()->where('organization_id',$org)->where('integration','solabooks')->first();
        // Never perform callback HTTP under a caller's transaction. Paused connections
        // retain ownership and their native validation, rather than becoming standalone.
        if (! self::mayDeliver($db->transactionLevel(),$setting?->mode,$mapping->status,$mapping->activation_state)
            || ! $fields || !($fields['active']??false)) {
            $this->pending($type,(string)($fields['name']??''));
        }
        app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
        $state = $ledger->record($mapping,'stock',$type,$partyId);
        if (! $state || $state->source_app!=='stock' || (int)$state->source_id!==$partyId) {
            $this->pending($type,(string)$fields['name']);
        }
        try {
            app(SolaBooksOutboxDeliveryService::class)->sendPartyChange($mapping,$state);
        } catch (\Throwable $failure) {
            // Timeout may have followed successful commit. Reconcile the authoritative
            // unique mapping rather than resetting intent or creating another counterpart.
            if (! $this->mapped($mapping,$type,$partyId)) {
                Log::warning('operational.party_dependency_pending',['organization_id'=>$org,
                    'organization_mapping_uuid'=>$mapping->mapping_uuid,'entity_type'=>$type,'source_id'=>$partyId,
                    'source_document_type'=>$document::class,'source_document_id'=>(int)$native->id,
                    'finance_organization_id'=>(int)$mapping->finance_organization_id,
                    'central_organization_id'=>(int)$mapping->central_organization_id,
                    'source_revision'=>$state->source_revision,
                    'source_key'=>'party:'.hash('sha256',$mapping->mapping_uuid.'|'.$type.'|stock|'.$partyId.'|'.$state->source_revision),
                    'exception_class'=>$failure::class,
                    'http_status'=>$failure instanceof PartyDeliveryFailure ? $failure->httpStatus : null,
                    'error_code'=>$failure instanceof PartyDeliveryFailure ? $failure->errorCode : 'party_connection_pending']);
                $this->pending($type,(string)$fields['name']);
            }
        }
        if (! $this->mapped($mapping,$type,$partyId)) $this->pending($type,(string)$fields['name']);
        // Existing native locked posting validation still rechecks all mappings,
        // conversions, currencies, accounts, approvals, periods and quantities.
    }

    public static function operation(string $class): array
    {
        return match($class) {
            GoodsReceipt::class=>['supplier','inventory.receive_goods'],
            SalesOrder::class=>['customer','inventory.manage_sales_orders'],
            Shipment::class=>['customer','inventory.manage_shipments'],
            default=>throw new \LogicException('Unsupported native operational party dependency'),
        };
    }

    public static function mayDeliver(int $transactionLevel,?string $mode,string $status,string $activation): bool
    {
        return $transactionLevel===0 && $mode==='active' && $status==='verified' && $activation==='active';
    }

    private function mapped(object $mapping,string $type,int $partyId): bool
    {
        $rows=IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)
            ->where('central_client_id',$mapping->central_client_id)->where('central_organization_id',$mapping->central_organization_id)
            ->where('finance_organization_id',$mapping->finance_organization_id)->where('solastock_organization_id',$mapping->solastock_organization_id)
            ->where('entity_type',$type)->where('solastock_record_id',(string)$partyId)->where('status','verified')
            ->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived',false)->where('solabooks_archived',false)->get();
        return $rows->count()===1 && (int)$rows->sole()->solabooks_record_id>0;
    }

    private function pending(string $type,string $name): never
    {
        throw ValidationException::withMessages([$type.'_id'=>__('inventory.integration.party_posting_pending',['party'=>$name])]);
    }
}
