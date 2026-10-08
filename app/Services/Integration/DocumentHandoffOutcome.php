<?php
namespace App\Services\Integration;

/** HTTP acceptance does not prove that the destination commercial draft exists. */
final class DocumentHandoffOutcome
{
    public static function message(?string $reason): string
    {
        $key=match($reason) {
            'commercial_mapping_required','destination_document_missing'=>'mapping_required',
            'party_identity_review_required','party_source_unavailable','party_approval_required','party_retry_exhausted'=>'connection_review_required',
            default=>'delivery_pending',
        };
        return __('inventory.purchasing.'.$key);
    }

    public static function classify(array $response,string $event): array
    {
        $data=(array)($response['data']??[]);
        $linked=match($event) {
            'purchasing.receipt.confirmed'=>(int)($data['bill_id']??0)>0,
            'sales.return.confirmed'=>(int)($data['credit_note_id']??0)>0,
            'sales.shipment.confirmed'=>(int)($data['invoice_id']??0)>0,
            default=>true,
        };
        $accepted=($response['successful']??false)===true;
        $intervention=$accepted && !$linked && in_array($data['state']??null,['intervention','needs_information','source_review'],true);
        return ['successful'=>$accepted && $linked,'intervention'=>$intervention,
            'data'=>$data,'reason'=>$intervention?'commercial_mapping_required':(!$accepted?'delivery_pending':(!$linked?'destination_document_missing':null))];
    }
}
