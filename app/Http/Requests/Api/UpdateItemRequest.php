<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ValidatesItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateItemRequest extends FormRequest
{
    use ValidatesItem;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumerics();
        $this->normalizeTracking();
    }

    public function rules(): array
    {
        return $this->baseItemRules(partial: true);
    }

    public function withValidator(Validator $validator): void
    {
        $this->applyItemDomainRules($validator);
    }
}
