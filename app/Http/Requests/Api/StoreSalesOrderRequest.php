<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        // order_number is SERVER-GENERATED when blank (users don't invent it).
        // Blank dates → null so 'nullable|date' passes and order_date can default.
        $patch = [];
        if (in_array($this->input('order_number'), ['', null], true)) {
            $patch['order_number'] = null;
        }
        foreach (['order_date', 'requested_ship_date'] as $f) {
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
            'order_number' => ['nullable','string','max:50'],
            'customer_name' => ['nullable','string','max:255'],
            'customer_external_id' => ['nullable','string','max:100'],
            'order_date' => ['nullable','date'],
            'requested_ship_date' => ['nullable','date'],
            'warehouse_id' => ['required','integer'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.item_id' => ['required','integer'],
            'lines.*.variant_id' => ['nullable','integer'],
            'lines.*.warehouse_id' => ['nullable','integer'],
            'lines.*.bin_id' => ['nullable','integer'],
            'lines.*.ordered_qty' => ['required','numeric','gt:0'],
            'lines.*.unit_price' => ['nullable','numeric','min:0'],
        ];
    }
}
