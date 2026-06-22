<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ValidatesWarehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWarehouseRequest extends FormRequest
{
    use ValidatesWarehouse;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('max_capacity_units') && in_array($this->input('max_capacity_units'), ['', null], true)) {
            $this->merge(['max_capacity_units' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'type' => ['sometimes', Rule::in(['warehouse', 'retail', 'transit', 'virtual'])],
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
