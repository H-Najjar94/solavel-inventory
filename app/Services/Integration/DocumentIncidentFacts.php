<?php
namespace App\Services\Integration;

/** Safe persisted incident projection. Retry attempts never create a new alert identity. */
final class DocumentIncidentFacts
{
    public static function fromRows(string $kind,object $outbox,object $native,string $mappingUuid): array
    {
        if (!in_array($kind,['receipt','shipment'],true)) throw new \InvalidArgumentException('document_kind_invalid');
        $answer=json_decode($kind==='receipt'?($outbox->receiver_response??'{}'):($outbox->response??'{}'),true)?:[];
        $payload=json_decode($outbox->payload??'{}',true)?:[];
        $partyName=preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)($payload[$kind==='receipt'?'receipt':'shipment'][$kind==='receipt'?'supplier_name':'customer_name']??''));
        $cancelled=!empty($native->reversed_at)||!empty($native->reversal_id)||!empty($native->reversal_sales_return_id)||!empty($native->deleted_at);
        $state=$cancelled?'cancelled':match($outbox->status){'sent'=>'resolved','intervention'=>'intervention',default=>'retrying'};
        $reason=$answer['reason']??$answer['delivery_reason']??($outbox->attempts>=40?'retry_exhausted':'delivery_pending');
        $allowed=['party_identity_review_required','party_source_unavailable','party_approval_required','party_retry_exhausted','party_connection_pending','commercial_mapping_required','destination_document_missing','retry_exhausted','delivery_pending'];
        if (!in_array($reason,$allowed,true)) $reason='delivery_pending';
        $missing=array_values(array_filter((array)($answer['missing_information']??[]),static fn($v)=>is_string($v)&&preg_match('/^(supplier_mapping_required|customer_mapping_required|item_mapping_required|unit_mapping_required|item_or_unit_mapping_required|selling_price_required|dated_exchange_rate_required|supplier_invoice_number_required|supplier_invoice_date_required)$/D',$v)));
        sort($missing);
        return ['version'=>'stock-document-incident.v1','organization_mapping_uuid'=>$mappingUuid,'document_kind'=>$kind,
            'finance_organization_id'=>(int)($payload['finance_organization_id']??0),'outbox_id'=>(int)$outbox->id,'event_uuid'=>(string)$outbox->event_uuid,'source_document_id'=>(int)$native->id,
            'source_document_number'=>mb_substr((string)($kind==='receipt'?$native->grn_number:$native->shipment_number),0,80),
            'warehouse_id'=>(int)$native->warehouse_id,'party_name'=>mb_substr($partyName,0,80),'state'=>$state,'reason'=>in_array($state,['resolved','cancelled'],true)?null:$reason,
            'missing_information'=>in_array($state,['resolved','cancelled'],true)?[]:$missing,
            'destination_document_id'=>(int)($answer[$kind==='receipt'?'bill_id':'invoice_id']??0)];
    }
    public static function fingerprint(array $facts): string
    {
        return hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR));
    }
}
