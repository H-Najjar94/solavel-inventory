<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'transfer_number' => ['required','string','max:50'],
            'transfer_date' => ['nullable','date'],
            'from_warehouse_id' => ['required','integer','different:to_warehouse_id'],
            'to_warehouse_id' => ['required','integer'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.item_id' => ['required','integer'],
            'lines.*.variant_id' => ['nullable','integer'],
            'lines.*.quantity' => ['required','numeric','gt:0'],
            'lines.*.lot_id' => ['nullable','integer'],
            'lines.*.serial_id' => ['nullable','integer'],
            'lines.*.from_bin_id' => ['nullable','integer'],
            'lines.*.to_bin_id' => ['nullable','integer'],
        ];
    }
}
