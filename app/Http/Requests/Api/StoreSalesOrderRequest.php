<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'order_number' => ['required','string','max:50'],
            'customer_name' => ['nullable','string','max:255'],
            'customer_external_id' => ['nullable','string','max:100'],
            'order_date' => ['nullable','date'],
            'requested_ship_date' => ['nullable','date'],
            'warehouse_id' => ['required','integer'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.item_id' => ['required','integer'],
            'lines.*.variant_id' => ['nullable','integer'],
            'lines.*.warehouse_id' => ['nullable','integer'],
            'lines.*.bin_id' => ['nullable','integer'],
            'lines.*.ordered_qty' => ['required','numeric','gt:0'],
            'lines.*.unit_price' => ['nullable','numeric','min:0'],
        ];
    }
}
