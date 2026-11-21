<?php

namespace App\Http\Requests\Api\Concerns;

use Illuminate\Validation\Validator;

/** Latitude/longitude validated as a pair: both or neither; 0 is a valid value. */
trait ValidatesCoordinates
{
    /** @return array<string, array<int, string>> */
    protected function coordinateRules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    protected function blankCoordinatesToNull(): void
    {
        foreach (['latitude', 'longitude'] as $key) {
            if ($this->has($key) && is_string($this->input($key)) && trim($this->input($key)) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    protected function applyCoordinatePairRule(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->hasAny(['latitude', 'longitude'])) {
                return;
            }
            // Partial updates: only enforce when either key is sent.
            if (! $this->has('latitude') && ! $this->has('longitude')) {
                return;
            }
            $lat = $this->input('latitude');
            $lng = $this->input('longitude');
            if (($lat === null) !== ($lng === null)) {
                $v->errors()->add($lat === null ? 'latitude' : 'longitude', __('inventory.validation.coordinates_pair'));
            }
        });
    }
}
