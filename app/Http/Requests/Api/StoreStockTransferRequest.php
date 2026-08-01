<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // transfer_number is SERVER-GENERATED when blank (users don't invent it).
        // Blank date → null so 'nullable|date' passes and transfer_date can default.
        $patch = [];
        if (in_array($this->input('transfer_number'), ['', null], true)) {
            $patch['transfer_number'] = null;
        }
        if ($this->input('transfer_date') === '') {
            $patch['transfer_date'] = null;
        }
        if ($patch !== []) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        return [
            // Optional: generated server-side if not supplied.
            'transfer_number' => ['nullable', 'string', 'max:50'],
            'transfer_date' => ['nullable', 'date'],
            'from_warehouse_id' => ['required', 'integer', 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.entered_qty' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.entered_unit_id' => ['nullable', 'integer'],
            'lines.*.lot_id' => ['nullable', 'integer'],
            'lines.*.serial_id' => ['nullable', 'integer'],
            'lines.*.from_bin_id' => ['nullable', 'integer'],
            'lines.*.to_bin_id' => ['nullable', 'integer'],
        ];
    }
}
