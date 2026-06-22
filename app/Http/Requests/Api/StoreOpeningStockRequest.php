<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Document number is SERVER-GENERATED when blank — users never type it.
        // Also null a blank date so 'nullable|date' passes and the service can
        // default it (robust even without ConvertEmptyStringsToNull middleware).
        $patch = [];
        if (in_array($this->input('entry_number'), ['', null], true)) {
            $patch['entry_number'] = null;
        }
        if ($this->input('opening_date') === '') {
            $patch['opening_date'] = null;
        }
        if ($patch !== []) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        return [
            // Optional: generated server-side if not supplied.
            'entry_number' => ['nullable', 'string', 'max:50'],
            'opening_date' => ['nullable', 'date'],
            'warehouse_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.lot_id' => ['nullable', 'integer'],
            'lines.*.lot_code' => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.serial_id' => ['nullable', 'integer'],
            'lines.*.serials' => ['nullable', 'array'],
            'lines.*.serials.*' => ['string', 'max:100'],
            'lines.*.bin_id' => ['nullable', 'integer'],
            // Quantity is optional when serials are captured (count = qty).
            'lines.*.quantity' => ['required_without:lines.*.serials', 'nullable', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }
}
