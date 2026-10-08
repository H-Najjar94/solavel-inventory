<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ValidatesWarehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWarehouseRequest extends FormRequest
{
    use ValidatesWarehouse;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // max_capacity_units is a nullable DECIMAL column; a blank string from the
        // form must become null (MySQL rejects '' for a decimal). Robust even if
        // the global ConvertEmptyStringsToNull middleware didn't run.
        if ($this->has('max_capacity_units') && in_array($this->input('max_capacity_units'), ['', null], true)) {
            $this->merge(['max_capacity_units' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(['warehouse', 'retail', 'transit', 'virtual'])],
            'address' => ['nullable', 'array'],
            'max_capacity_units' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->applyWarehouseDomainRules($validator);
    }
}
