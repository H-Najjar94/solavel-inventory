<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        // grn_number is SERVER-GENERATED when blank (users don't invent it).
        // Blank date → null so 'nullable|date' passes and receipt_date can default.
        $patch = [];
        if (in_array($this->input('grn_number'), ['', null], true)) {
            $patch['grn_number'] = null;
        }
        if ($this->input('receipt_date') === '') {
            $patch['receipt_date'] = null;
        }
        if ($patch !== []) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        return [
            // Optional: generated server-side if not supplied.
            'grn_number' => ['nullable','string','max:50'],
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
            'lines.*.entered_qty' => ['nullable','numeric','gt:0'],
            'lines.*.entered_unit_id' => ['nullable','integer'],
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
