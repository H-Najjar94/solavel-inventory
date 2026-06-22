<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'shipment_number' => ['required','string','max:50'],
            'sales_order_id' => ['nullable','integer'],
            'pack_id' => ['nullable','integer'],
            'ship_date' => ['nullable','date'],
            'warehouse_id' => ['required','integer'],
            'carrier' => ['nullable','string','max:100'],
            'tracking_number' => ['nullable','string','max:100'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.sales_order_line_id' => ['nullable','integer'],
            'lines.*.item_id' => ['required','integer'],
            'lines.*.variant_id' => ['nullable','integer'],
            'lines.*.warehouse_id' => ['nullable','integer'],
            'lines.*.bin_id' => ['nullable','integer'],
            'lines.*.quantity' => ['required','numeric','gt:0'],
            'lines.*.lot_id' => ['nullable','integer'],
            'lines.*.serial_id' => ['nullable','integer'],
        ];
    }
}
