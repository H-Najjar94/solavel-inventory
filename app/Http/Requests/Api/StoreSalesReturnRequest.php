<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'return_number' => ['required','string','max:50'],
            'shipment_id' => ['nullable','integer'],
            'customer_name' => ['nullable','string','max:255'],
            'return_date' => ['nullable','date'],
            'warehouse_id' => ['required','integer'],
            'reason' => ['nullable','string','max:255'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.item_id' => ['required','integer'],
            'lines.*.variant_id' => ['nullable','integer'],
            'lines.*.warehouse_id' => ['nullable','integer'],
            'lines.*.bin_id' => ['nullable','integer'],
            'lines.*.returned_qty' => ['required','numeric','gt:0'],
            'lines.*.unit_cost' => ['nullable','numeric','min:0'],
            'lines.*.condition' => ['nullable','in:resellable,damaged,quarantine,retired'],
            'lines.*.lot_id' => ['nullable','integer'],
            'lines.*.lot_code' => ['nullable','string','max:100'],
            'lines.*.serial_id' => ['nullable','integer'],
            'lines.*.is_manual' => ['nullable','boolean'],
        ];
    }
}
