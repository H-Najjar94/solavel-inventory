<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class StoreStockCountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        // count_number is SERVER-GENERATED when blank (users don't invent it).
        if (in_array($this->input('count_number'), ['', null], true)) {
            $this->merge(['count_number' => null]);
        }
    }

    public function rules(): array
    {
        return [
            // Optional: generated server-side if not supplied.
            'count_number' => ['nullable','string','max:50'],
            'count_type' => ['required', Rule::in(['cycle','full'])],
            'warehouse_id' => ['required','integer'],
            'zone_id' => ['nullable','integer'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.item_id' => ['required','integer'],
            'lines.*.variant_id' => ['nullable','integer'],
            'lines.*.lot_id' => ['nullable','integer'],
            'lines.*.serial_id' => ['nullable','integer'],
            'lines.*.bin_id' => ['nullable','integer'],
            'lines.*.system_qty' => ['nullable','numeric'],
            'lines.*.counted_qty' => ['nullable','numeric'],
        ];
    }
}
