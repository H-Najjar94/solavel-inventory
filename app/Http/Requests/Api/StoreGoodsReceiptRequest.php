<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'grn_number' => ['required','string','max:50'],
            'purchase_order_id' => ['nullable','integer'],
            'supplier_id' => ['nullable','integer'],
            'warehouse_id' => ['required','integer'],
            'receipt_date' => ['nullable','date'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.purchase_order_line_id' => ['nullable','integer'],
            'lines.*.item_id' => ['required','integer'],
            'lines.*.variant_id' => ['nullable','integer'],
            'lines.*.received_qty' => ['required','numeric','gt:0'],
            'lines.*.accepted_qty' => ['nullable','numeric','min:0'],
            'lines.*.rejected_qty' => ['nullable','numeric','min:0'],
            'lines.*.unit_cost' => ['nullable','numeric','min:0'],
            'lines.*.lot_id' => ['nullable','integer'],
            'lines.*.lot_code' => ['nullable','string','max:100'],
            'lines.*.serial_id' => ['nullable','integer'],
            'lines.*.serials' => ['nullable','array'],
            'lines.*.serials.*' => ['string','max:100'],
            'lines.*.bin_id' => ['nullable','integer'],
            'lines.*.expiry_date' => ['nullable','date'],
        ];
    }
}
