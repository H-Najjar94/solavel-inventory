<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
class StoreRecallRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'recall_number' => ['required','string','max:50'],
            'item_id' => ['required','integer'],
            'scope' => ['nullable','in:lot,serial'],
            'reason' => ['nullable','string','max:255'],
            'notes' => ['nullable','string'],
            'lines' => ['required','array','min:1'],
            'lines.*.item_id' => ['nullable','integer'],
            'lines.*.lot_id' => ['nullable','integer'],
            'lines.*.serial_id' => ['nullable','integer'],
            'lines.*.disposition' => ['nullable','in:quarantine,return,destroy,none'],
        ];
    }
}
