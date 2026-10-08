<?php

namespace App\Services\FinancialOrigins;

use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\Validator;

final readonly class OriginRequestPayload
{
    private function __construct(public FinancialOrigin $origin, public array $payload) {}

    public static function fromArray(array $data): self
    {
        $origin = FinancialOrigin::fromPayload($data);
        Validator::make($data, [
            'source_document_number'=>'required|string|max:191', 'request_uuid'=>'required|uuid', 'source_revision'=>'required|string|size:64',
            'source_status'=>'required|in:posted', 'currency_code'=>'required|string|size:3',
            'base_currency_code'=>'required|string|size:3', 'document_date'=>'required|date_format:Y-m-d',
            'pricing_mode'=>'required|in:exclusive', 'lines'=>'required|array|min:1',
            'lines.*.source_document_line_id'=>'required|integer|min:1',
            'lines.*.item_external_id'=>'required|integer|min:1', 'lines.*.unit_external_id'=>'required|integer|min:1',
            'lines.*.quantity'=>'required|numeric|gt:0', 'lines.*.unit_price'=>'required|numeric|min:0',
            'lines.*.discount_rate'=>'required|numeric|in:0',
            'customer_external_id'=>'nullable|integer|min:1', 'supplier_external_id'=>'nullable|integer|min:1',
        ])->validate();
        if ($origin->type === 'expense') abort_unless((int) ($data['supplier_external_id'] ?? 0)>0 && !isset($data['customer_external_id']),422);
        if ($origin->type === 'invoice') abort_unless((int) ($data['customer_external_id'] ?? 0)>0,422);
        if ($origin->domain() === 'sales') abort_unless(!isset($data['supplier_external_id']),422);
        $seen=[];
        foreach ($data['lines'] as $line) {
            $id=(int)$line['source_document_line_id']; abort_if(isset($seen[$id]),422); $seen[$id]=true;
            abort_if(array_key_exists('unit_conversion_factor',$line) || array_key_exists('base_quantity',$line),422,'Native Stock resolves the mapped entered unit conversion.');
            // Invoice legacy line aliases are accepted only when identical to the genuine typed source.
            if(array_key_exists('source_line_id',$line)) abort_unless($origin->type==='invoice' && (string)$line['source_line_id']===(string)$id,422);
        }
        return new self($origin,$data);
    }

    public function canonicalJson(): string { return SolaStockJournalContract::canonicalJson($this->payload); }
    public function fingerprint(): string { return hash('sha256',$this->canonicalJson()); }
}
