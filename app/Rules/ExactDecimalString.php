<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Refuses JSON floating-point values before they can corrupt source precision. */
final class ExactDecimalString implements ValidationRule
{
    public function __construct(private readonly int $scale = 6) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_float($value) || (!is_string($value) && !is_int($value))) {
            $fail("The {$attribute} field must be sent as an exact decimal string.");
            return;
        }
        if (!preg_match('/^-?\d+(?:\.\d{1,'.$this->scale.'})?$/', (string) $value)) {
            $fail("The {$attribute} field may contain at most {$this->scale} decimal places.");
        }
    }
}
