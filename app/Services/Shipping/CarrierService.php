<?php

namespace App\Services\Shipping;

use App\Models\Tenant\Shipment;
use Illuminate\Support\Str;

class CarrierService
{
    private const SERVICES = [
        'standard' => ['label' => 'Standard ground', 'base' => '4.50', 'per_kg' => '0.80', 'days' => '3-5'],
        'express' => ['label' => 'Express', 'base' => '9.00', 'per_kg' => '1.45', 'days' => '1-2'],
        'freight' => ['label' => 'Freight', 'base' => '35.00', 'per_kg' => '0.55', 'days' => '5-8'],
    ];

    /** @return array<int, array<string, mixed>> */
    public function rates(Shipment $shipment): array
    {
        $weight = max(0.1, (float) ($shipment->package_weight ?? $this->estimatedWeight($shipment)));

        return collect(self::SERVICES)->map(function (array $service, string $code) use ($shipment, $weight) {
            $amount = (float) $service['base'] + ($weight * (float) $service['per_kg']);

            return [
                'carrier' => $shipment->carrier ?: 'SolaShip',
                'service_code' => $code,
                'service_name' => $service['label'],
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => 'USD',
                'estimated_days' => $service['days'],
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function generateLabel(Shipment $shipment, ?string $serviceCode = null): array
    {
        $serviceCode = $serviceCode ?: ($shipment->carrier_service ?: 'standard');
        $rate = collect($this->rates($shipment))->firstWhere('service_code', $serviceCode)
            ?? $this->rates($shipment)[0];

        $tracking = $shipment->tracking_number ?: $this->trackingNumber($shipment, $serviceCode);
        $labelNumber = 'LBL-'.strtoupper(substr(hash('sha256', $shipment->id.'|'.$tracking), 0, 12));
        $payload = [
            'label_number' => $labelNumber,
            'carrier' => $rate['carrier'],
            'service_code' => $rate['service_code'],
            'service_name' => $rate['service_name'],
            'tracking_number' => $tracking,
            'ship_to' => $shipment->ship_to ?: [],
            'rate_amount' => $rate['amount'],
            'rate_currency' => $rate['currency'],
            'qr_svg' => $this->qrSvg($tracking),
            'generated_at' => now()->toIso8601String(),
        ];

        $shipment->forceFill([
            'carrier' => $rate['carrier'],
            'carrier_service' => $rate['service_code'],
            'tracking_number' => $tracking,
            'rate_amount' => $rate['amount'],
            'rate_currency' => $rate['currency'],
            'label_status' => 'generated',
            'label_number' => $labelNumber,
            'label_payload' => $payload,
            'label_generated_at' => now(),
            'tracking_status' => $shipment->status === 'posted' ? 'in_transit' : 'label_created',
            'tracking_events' => $this->trackingEvents($shipment, $tracking),
        ])->save();

        return $payload;
    }

    /** @return array<string, mixed> */
    public function tracking(Shipment $shipment): array
    {
        $tracking = $shipment->tracking_number ?: $this->trackingNumber($shipment, $shipment->carrier_service ?: 'standard');
        $events = $shipment->tracking_events ?: $this->trackingEvents($shipment, $tracking);
        $status = $shipment->tracking_status ?: ($shipment->status === 'posted' ? 'in_transit' : 'label_created');

        return [
            'carrier' => $shipment->carrier ?: 'SolaShip',
            'tracking_number' => $tracking,
            'status' => $status,
            'events' => $events,
        ];
    }

    private function estimatedWeight(Shipment $shipment): float
    {
        $shipment->loadMissing('lines');

        return max(0.1, $shipment->lines->sum(fn ($line) => (float) $line->quantity));
    }

    private function trackingNumber(Shipment $shipment, string $serviceCode): string
    {
        return strtoupper('STK'.Str::padLeft((string) $shipment->id, 8, '0').substr(hash('crc32b', $serviceCode.$shipment->shipment_number), 0, 4));
    }

    /** @return array<int, array<string, string|null>> */
    private function trackingEvents(Shipment $shipment, string $tracking): array
    {
        $created = $shipment->label_generated_at?->toDateTimeString() ?? now()->toDateTimeString();
        $events = [[
            'status' => 'label_created',
            'message' => "Label {$tracking} created",
            'occurred_at' => $created,
        ]];

        if ($shipment->status === 'posted') {
            $events[] = [
                'status' => 'in_transit',
                'message' => 'Shipment handed to carrier',
                'occurred_at' => $shipment->posted_at?->toDateTimeString() ?? now()->toDateTimeString(),
            ];
        }

        return $events;
    }

    private function qrSvg(string $value): string
    {
        $hash = hash('sha256', $value);
        $cells = 21;
        $rects = [];
        for ($y = 0; $y < $cells; $y++) {
            for ($x = 0; $x < $cells; $x++) {
                $i = ($x + $y * $cells) % strlen($hash);
                $dark = hexdec($hash[$i]) % 2 === 0;
                if (($x < 7 && $y < 7) || ($x > 13 && $y < 7) || ($x < 7 && $y > 13)) {
                    $dark = $x === 0 || $y === 0 || $x === 6 || $y === 6 || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                }
                if ($dark) {
                    $rects[] = '<rect x="'.$x.'" y="'.$y.'" width="1" height="1"/>';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 21 21" shape-rendering="crispEdges">'.implode('', $rects).'</svg>';
    }
}
