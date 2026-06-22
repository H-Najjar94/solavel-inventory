<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ValidatesItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreItemRequest extends FormRequest
{
    use ValidatesItem;

    public function authorize(): bool
    {
        return true; // route middleware perm:inventory.manage_items enforces access
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumerics();
        $this->normalizeTracking();
    }

    public function rules(): array
    {
        return $this->baseItemRules(partial: false);
    }

    public function withValidator(Validator $validator): void
    {
        $this->applyItemDomainRules($validator);
    }
}
