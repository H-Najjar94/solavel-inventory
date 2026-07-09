<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\ItemBarcode;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\WarehouseBin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScannerController extends ApiController
{
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return $this->error('scan_code_required', 'Scan code is required.', 422);
        }

        $barcode = ItemBarcode::query()->with('item:id,sku,name,item_type,tracking_type,is_active')
            ->where('barcode', $code)->first();
        if ($barcode) {
            return $this->success([
                'type' => 'item',
                'code' => $code,
                'item' => [
                    'id' => $barcode->item?->id,
                    'sku' => $barcode->item?->sku,
                    'name' => $barcode->item?->name,
                    'tracking_type' => $barcode->item?->tracking_type,
                    'is_active' => $barcode->item?->is_active,
                ],
                'barcode' => [
                    'id' => $barcode->id,
                    'type' => $barcode->type,
                    'variant_id' => $barcode->variant_id,
                ],
            ]);
        }

        $bin = WarehouseBin::query()
            ->with('warehouse:id,code,name')
            ->where(function ($query) use ($code) {
                $query->where('code', $code)
                    ->orWhere('coords->barcode', $code);
            })->first();
        if ($bin) {
            return $this->success([
                'type' => 'bin',
                'code' => $code,
                'bin' => [
                    'id' => $bin->id,
                    'code' => $bin->code,
                    'warehouse_id' => $bin->warehouse_id,
                    'warehouse_code' => $bin->warehouse?->code,
                    'warehouse_name' => $bin->warehouse?->name,
                    'bin_type' => $bin->coords['bin_type'] ?? 'storage',
                    'barcode' => $bin->coords['barcode'] ?? null,
                ],
            ]);
        }

        $shipment = Shipment::query()
            ->where(function ($query) use ($code) {
                $query->where('tracking_number', $code)
                    ->orWhere('shipment_number', $code)
                    ->orWhere('label_number', $code);
            })->first();
        if ($shipment) {
            return $this->success([
                'type' => 'shipment',
                'code' => $code,
                'shipment' => [
                    'id' => $shipment->id,
                    'shipment_number' => $shipment->shipment_number,
                    'carrier' => $shipment->carrier,
                    'carrier_service' => $shipment->carrier_service,
                    'tracking_number' => $shipment->tracking_number,
                    'tracking_status' => $shipment->tracking_status,
                    'status' => $shipment->status,
                ],
            ]);
        }

        return $this->error('scan_code_not_found', 'No item, bin or shipment matches that scan.', 404);
    }
}
