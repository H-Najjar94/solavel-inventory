<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // adjustment_number is SERVER-GENERATED when blank (users don't invent it).
        // Blank date → null so 'nullable|date' passes and adjustment_date can default.
        $patch = [];
        if (in_array($this->input('adjustment_number'), ['', null], true)) {
            $patch['adjustment_number'] = null;
        }
        if ($this->input('adjustment_date') === '') {
            $patch['adjustment_date'] = null;
        }
        if ($patch !== []) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        return [
            // Optional: generated server-side if not supplied.
            'adjustment_number' => ['nullable', 'string', 'max:50'],
            'adjustment_date' => ['nullable', 'date'],
            'warehouse_id' => ['required', 'integer'],
            'reason_code' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.direction' => ['required', Rule::in(['increase', 'decrease'])],
            'lines.*.quantity' => ['required_without:lines.*.serials', 'nullable', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lot_id' => ['nullable', 'integer'],
            'lines.*.lot_code' => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.serial_id' => ['nullable', 'integer'],
            'lines.*.serials' => ['nullable', 'array'],
            'lines.*.serials.*' => ['string', 'max:100'],
            'lines.*.bin_id' => ['nullable', 'integer'],
            'lines.*.account_ref' => ['nullable', 'string', 'max:100'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }
}
