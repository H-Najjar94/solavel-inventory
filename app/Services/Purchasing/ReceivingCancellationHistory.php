<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\Item;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/** Read-only audit projection: a cancelled command is not an accepted receiving request. */
final class ReceivingCancellationHistory
{
    public function rows(): array
    {
        $org = app(OrganizationContext::class)->idOrFail();
        $db = DB::connection('tenant');
        return $db->table('purchasing_receiving_cancellations as c')
            ->where('c.organization_id', $org)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchasing_receiving_requests as r')
                ->whereColumn('r.organization_id', 'c.organization_id')->whereColumn('r.request_uuid', 'c.request_uuid'))
            ->orderByDesc('c.id')->limit(100)->get()->map(function ($row) use ($db, $org) {
                $payload = json_decode($row->authority, true)['receiving_payload'] ?? [];
                $lines = [];
                foreach ((array) ($payload['lines'] ?? []) as $line) {
                    $mapping = $db->table('integration_master_data_mappings')->where('organization_mapping_uuid', $row->organization_mapping_uuid)
                        ->where('central_organization_id', $org)->where('solastock_organization_id', $org)
                        ->where('finance_organization_id', $row->finance_organization_id)->where('entity_type', 'item')
                        ->where('solabooks_record_id', (string) ($line['item_external_id'] ?? ''))->first();
                    $item = $mapping ? Item::query()->where('organization_id', $org)->find((int) $mapping->solastock_record_id) : null;
                    $lines[] = ['id' => (string) ($line['source_line_id'] ?? ''), 'item_name' => $item?->name,
                        'source_item_id' => $line['item_external_id'] ?? null, 'requested_qty' => $line['quantity'] ?? null,
                        'received_qty' => '0', 'remaining_qty' => '0'];
                }
                return ['id' => null, 'number' => null, 'history_uuid' => $row->request_uuid,
                    'request_uuid' => $row->request_uuid, 'kind' => 'cancelled_before_delivery',
                    'source_bill_id' => (int) $row->source_bill_id, 'source_bill_number' => $payload['source_bill_number'] ?? null,
                    'status' => 'cancelled', 'currency_code' => $payload['currency_code'] ?? null,
                    'approved' => false, 'warehouse_id' => null, 'cancelled_at' => $row->created_at,
                    'lines' => $lines, 'receipts' => []];
            })->all();
    }
}
