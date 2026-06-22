<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        // po_number is SERVER-GENERATED when blank. Blank dates → null so the
        // 'nullable|date' rules pass and order_date can default (robust without
        // the ConvertEmptyStringsToNull middleware). currency_code '' → null so
        // the size:3 rule doesn't reject an empty string.
        $patch = [];
        if (in_array($this->input('po_number'), ['', null], true)) {
            $patch['po_number'] = null;
        }
        foreach (['order_date', 'expected_date', 'currency_code'] as $f) {
            if ($this->input($f) === '') {
                $patch[$f] = null;
            }
        }
        if ($patch !== []) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        return [
            // Optional: generated server-side if not supplied.
            'po_number' => ['nullable','string','max:50'],
            'supplier_id' => ['nullable','integer'],
            'warehouse_id' => ['required','integer'],
            'order_date' => ['nullable','date'],
            'expected_date' => ['nullable','date'],
            'currency_code' => ['nullable','string','size:3'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.item_id' => ['required','integer'],
            'lines.*.variant_id' => ['nullable','integer'],
            'lines.*.ordered_qty' => ['required','numeric','gt:0'],
            'lines.*.unit_price' => ['nullable','numeric','min:0'],
            'lines.*.tax_code' => ['nullable','string','max:50'],
            'lines.*.expected_date' => ['nullable','date'],
        ];
    }
}
