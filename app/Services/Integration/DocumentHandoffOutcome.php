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
            'purchasing.return.confirmed'=>match($data['state']??null) {
                'draft_review','linked_existing_review','credit_posted'=>(int)($data['source_id']??0)>0 && (int)($data['debit_note_id']??0)>0,
                'unbilled_cleared'=>(int)($data['source_id']??0)>0 && (int)($data['journal_id']??0)>0,
                default=>false,
            },
            'purchasing.return.reversed'=>($data['state']??null)==='reversed' && (int)($data['source_id']??0)>0,
            'sales.return.confirmed'=>(int)($data['credit_note_id']??0)>0,
            'sales.shipment.confirmed'=>(int)($data['invoice_id']??0)>0,
            default=>true,
        };
        $accepted=($response['successful']??false)===true;
        $pending=$event==='purchasing.return.reversed' && ($data['state']??null)==='reversal_settlement_pending'
            && (int)($data['source_id']??0)>0 && (int)($data['reversal_proof_id']??0)>0
            && (int)($data['inverse_import_journal_id']??0)>0 && is_array($data['settlement_uuids']??null) && $data['settlement_uuids']!==[];
        $ordering=$event==='purchasing.return.confirmed' && ($data['state']??null)==='source_review'
            && ($data['missing_information']??[])===['source_journal_delivery_required']
            || $event==='purchasing.return.reversed' && ($data['state']??null)==='reversal_review'
            && ($data['missing_information']??[])===['physical_return_inverse_journal_pending'];
        $intervention=$accepted && !$linked && !$pending && !$ordering && in_array($data['state']??null,['intervention','needs_information','source_review','reversal_review','reversal_settlement_pending','credit_voided_review'],true);
        return ['successful'=>$accepted && $linked,'intervention'=>$intervention,
            'data'=>$data,'reason'=>$intervention?'commercial_mapping_required':(!$accepted || $pending || $ordering?'delivery_pending':(!$linked?'destination_document_missing':null))];
    }
}
