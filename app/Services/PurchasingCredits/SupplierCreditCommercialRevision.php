<?php
namespace App\Services\PurchasingCredits;
use App\Services\Integration\SolaStockJournalContract;
/** Mirrored closed commercial field contract from Finance734b4bc5; lifecycle/audit fields never enter this revision. */
final class SupplierCreditCommercialRevision
{
    private const NOTE_FIELDS=['id','organization_id','debit_no','side','note_kind','supplier_id','party_type','party_id','bill_id','linked_document_type','linked_document_id','currency_code','tax_inclusive','discount_total','calculation_version','project_id'];
    private const LINE_FIELDS=['id','organization_id','debit_note_id','line_no','inventory_item_id','qty','unit','unit_price','line_discount','line_discount_type','line_discount_value','line_discount_amount','document_discount_allocated','tax_id','tax_code_id','tax_rate_id','tax_scope','tax_included','expense_account_id','line_subtotal','line_tax','line_total','net_amount','tax_amount','gross_amount','project_id','project_division_id','project_division_revision'];
    public static function forRows(object $note,object $line):string
    {
        $header=$detail=[];
        foreach(self::NOTE_FIELDS as$field)$header[$field]=$note->$field??null;
        $header['currency_code']=strtoupper((string)$header['currency_code']);
        $date=$note->date??$note->issue_date??null;
        abort_unless(is_string($date) && $date!=='',409);
        $header['effective_date']=\Carbon\Carbon::parse($date)->toDateString();
        foreach(self::LINE_FIELDS as$field)$detail[$field]=$line->$field??null;
        return SolaStockJournalContract::payloadHash(['note'=>$header,'line'=>$detail]);
    }
}
