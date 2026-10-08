<?php
namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;

/** Ordered native party delivery; never rewrites immutable document snapshots. */
final class PartyDocumentDependency
{
    public function ensure(array $payload, int $stockOrganizationId, string $mappingUuid): void
    {
        $db=DB::connection('tenant');
        abort_unless($db->transactionLevel()===0,409);
        $event=$payload['event_type']??null;
        if (!in_array($event,['purchasing.receipt.confirmed','sales.shipment.confirmed'],true)) return;
        $mapping=\App\Models\Tenant\IntegrationOrganizationMapping::query()->where('mapping_uuid',$mappingUuid)
            ->where('solastock_organization_id',$stockOrganizationId)->where('tenant_database_identity',$db->getDatabaseName())
            ->where('status','verified')->where('activation_state','active')->first();
        abort_unless($mapping && ($payload['identity']['organization_mapping_uuid']??null)===$mappingUuid,403);
        app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
        $sales=$event==='sales.shipment.confirmed';
        $source=(array)($payload[$sales?'shipment':'receipt']??[]);
        $native=$db->table($sales?'shipments':'goods_receipts')->where('organization_id',$stockOrganizationId)
            ->where('id',(int)($source['id']??0))->whereNull('deleted_at')->whereNull('reversed_at')->whereNotNull('posted_at')->first();
        abort_unless($native && empty($native->reversal_id),409);
        if ($sales) {
            $order=$db->table('sales_orders')->where('organization_id',$stockOrganizationId)->where('id',$native->sales_order_id)->whereNull('deleted_at')->first();
            abort_unless($order && (int)$order->id===(int)($source['sales_order_id']??0),409);
            $partyId=(int)$order->customer_id;
        } else $partyId=(int)$native->supplier_id;
        $type=$sales?'customer':'supplier';
        abort_unless($partyId>0 && $partyId===(int)($source[$sales?'customer_id':'supplier_id']??0),409);
        if ($this->mapped($mapping,$type,$partyId)) return;
        $state=app(PartySyncLedger::class)->record($mapping,'stock',$type,$partyId);
        if (!$state || $state->source_app!=='stock' || (int)$state->source_id!==$partyId) {
            throw new PartyDependencyPending(['state'=>'intervention','reason'=>'party_identity_review_required','entity_type'=>$type,'source_id'=>$partyId]);
        }
        // Existing canonical signed receiver enforces identity, ambiguity and local overrides.
        $result=app(SolaBooksOutboxDeliveryService::class)->sendPartyChange($mapping,$state);
        if (($result['status']??null)!=='synced' || !$this->mapped($mapping,$type,$partyId)) {
            throw new PartyDependencyPending(['state'=>($result['status']??'pending')==='intervention'?'intervention':'pending','reason'=>$result['reason']??'party_connection_pending','entity_type'=>$type,'source_id'=>$partyId]);
        }
    }

    public function resumePending(object $mapping,string $type,int $partyId): void
    {
        $db=DB::connection('tenant');
        abort_unless($db->transactionLevel()===0,409);
        if (!$this->mapped($mapping,$type,$partyId)) return;
        $sales=$type==='customer';
        $table=$sales?'sales_document_outbox':'purchasing_document_outbox';
        if (!\Illuminate\Support\Facades\Schema::connection('tenant')->hasTable($table)) return;
        $rows=$db->table($table)->where('organization_id',$mapping->solastock_organization_id)->where('status','intervention')
            ->where('payload->identity->organization_mapping_uuid',$mapping->mapping_uuid)
            ->where($sales?'payload->shipment->customer_id':'payload->receipt->supplier_id',$partyId)
            ->orderBy('id')->limit(20)->get();
        foreach ($rows as $row) {
            $answer=json_decode($sales?($row->response??'{}'):($row->receiver_response??'{}'),true);
            if (($answer['entity_type']??null)!==$type || (int)($answer['source_id']??0)!==$partyId
                || !in_array($answer['reason']??null,['party_identity_review_required','party_source_unavailable','party_approval_required','party_retry_exhausted'],true)) continue;
            $payload=json_decode($row->payload,true);
            if (($payload['event_type']??null)!==($sales?'sales.shipment.confirmed':'purchasing.receipt.confirmed')) continue;
            $source=$payload[$sales?'shipment':'receipt'];
            $native=$db->table($sales?'shipments':'goods_receipts')->where('organization_id',$mapping->solastock_organization_id)
                ->where('id',$source['id'])->whereNull('deleted_at')->whereNull('reversed_at')->whereNotNull('posted_at')->first();
            if (!$native || !empty($native->reversal_id)) continue;
            if ($sales) {
                $owner=$db->table('sales_orders')->where('organization_id',$mapping->solastock_organization_id)->where('id',$native->sales_order_id)->whereNull('deleted_at')->first();
                if (!$owner || (int)$owner->customer_id!==$partyId) continue;
            } elseif ((int)$native->supplier_id!==$partyId) continue;
            $db->table($table)->where('id',$row->id)->where('organization_id',$mapping->solastock_organization_id)
                ->where('status','intervention')->where('updated_at',$row->updated_at)
                ->update(['status'=>'retry','next_attempt_at'=>now(),'updated_at'=>now()]);
        }
    }

    private function mapped(object $mapping,string $type,int $partyId): bool
    {
        $pairs=DB::connection('tenant')->table('integration_master_data_mappings')
            ->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('finance_organization_id',$mapping->finance_organization_id)
            ->where('solastock_organization_id',$mapping->solastock_organization_id)->where('central_client_id',$mapping->central_client_id)
            ->where('central_organization_id',$mapping->central_organization_id)->where('entity_type',$type)
            ->where('solastock_record_id',(string)$partyId)->where('status','verified')->whereNull('conflict_code')->whereNull('error_state')
            ->where('solastock_archived',false)->where('solabooks_archived',false)->get();
        return $pairs->count()===1 && (int)$pairs->sole()->solabooks_record_id>0;
    }
}
