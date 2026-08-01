<?php

namespace App\Http\Requests\Api;

use App\Models\Tenant\InventorySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'lines.*.entered_qty' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.entered_unit_id' => ['nullable', 'integer'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $codes = collect(InventorySetting::query()->first()?->adjustment_reason_codes ?? [])
                ->filter(fn ($code) => ($code['active'] ?? true) !== false)
                ->pluck('code')
                ->filter()
                ->values();

            if ($codes->isEmpty()) {
                return;
            }

            $reason = $this->input('reason_code');
            if (! is_string($reason) || $reason === '') {
                $validator->errors()->add('reason_code', __('inventory.validation.adjustment_reason_required'));

                return;
            }

            if (! $codes->contains($reason)) {
                $validator->errors()->add('reason_code', __('inventory.validation.adjustment_reason_unconfigured'));
            }
        });
    }
}
