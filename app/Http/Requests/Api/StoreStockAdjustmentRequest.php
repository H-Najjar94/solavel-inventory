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

    public function rules(): array
    {
        return [
            'adjustment_number' => ['required', 'string', 'max:50'],
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
