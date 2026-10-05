<?php

namespace App\Services\Stock\Historical;

use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal as D;
use RuntimeException;

/** Pure, reviewed chronology replay. It never reads or writes tenant state. */
final class HistoricalFifoPlanner
{
    public const VERSION = 'historical-fifo.v1';

    public function plan(array $events, array $openings): array
    {
        usort($events, fn (array $a, array $b) => [$a['date'], $a['sequence'], $a['source_id']] <=> [$b['date'], $b['sequence'], $b['source_id']]);
        $layers = $results = $blocked = $seen = $sales = $returned = [];
        foreach ($events as $event) {
            foreach (['source_id', 'date', 'sequence', 'stock_item_id', 'warehouse_id', 'quantity', 'kind'] as $field) {
                if (! isset($event[$field])) throw new RuntimeException('Historical FIFO missing '.$field);
            }
            $key = $event['stock_item_id'].':'.$event['warehouse_id'];
            $id = (string) $event['source_id'];
            if (isset($seen[$id])) throw new RuntimeException('Historical FIFO duplicate source identity');
            $seen[$id] = true;
            $qty = $this->decimal($event['quantity'], 4);
            if (D::cmp($qty, '0') <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $event['date'])) {
                throw new RuntimeException('Historical FIFO invalid date or quantity');
            }
            if (! array_key_exists($key, $layers)) {
                $layers[$key] = [];
                $opening = $openings[$key] ?? null;
                if ($opening === null) $blocked[$key] = 'opening_evidence_missing';
                else {
                    foreach (['business_date', 'evidence_reference', 'hash', 'reviewed_by', 'layers'] as $field) {
                        if (! isset($opening[$field])) throw new RuntimeException('Historical FIFO opening proof incomplete');
                    }
                    if (! preg_match('/^[a-f0-9]{64}$/D', $opening['hash']) || ! $opening['reviewed_by'] || ! $opening['evidence_reference'] || $opening['business_date'] > $event['date']) {
                        throw new RuntimeException('Historical FIFO opening proof invalid');
                    }
                    foreach ($opening['layers'] as $index => $layer) {
                        $q = $this->decimal($layer['quantity'] ?? '', 4);
                        $cost = $this->decimal($layer['unit_cost'] ?? '', 4);
                        if (D::cmp($q, '0') <= 0 || D::cmp($cost, '0') < 0 || (D::isZero($cost) && empty($layer['zero_cost_evidence_reference']))) throw new RuntimeException('Historical FIFO opening layer invalid');
                        $layers[$key][] = ['origin' => 'opening:'.$index, 'date' => $opening['business_date'], 'remaining' => $q, 'unit_cost' => $cost];
                    }
                    // An explicit reviewed empty layers array proves zero. A null opening never does.
                }
            }
            $result = array_merge($event, ['allocations' => []]);
            if (isset($blocked[$key])) {
                $results[] = array_merge($result, ['status' => 'blocked', 'reason' => $blocked[$key]]);
                continue;
            }
            if ($event['kind'] === 'receipt') {
                $cost = $this->decimal($event['acquisition_unit_cost'] ?? '', 4);
                if (D::cmp($cost, '0') < 0 || (D::isZero($cost) && empty($event['zero_cost_evidence_reference']))) throw new RuntimeException('Historical FIFO acquisition cost invalid');
                $layers[$key][] = ['origin' => $id, 'date' => $event['date'], 'remaining' => $qty, 'unit_cost' => $cost];
                $amount = D::money(D::mul($qty, $cost));
            } elseif ($event['kind'] === 'out') {
                $remaining = $qty;
                $amount = '0';
                foreach ($layers[$key] as &$layer) {
                    if (D::isZero($remaining)) break;
                    if (D::isZero($layer['remaining'])) continue;
                    $take = D::lt($remaining, $layer['remaining']) ? $remaining : $layer['remaining'];
                    $result['allocations'][] = ['origin' => $layer['origin'], 'date' => $layer['date'], 'quantity' => $take, 'unit_cost' => $layer['unit_cost']];
                    $layer['remaining'] = D::qty(D::sub($layer['remaining'], $take));
                    $remaining = D::qty(D::sub($remaining, $take));
                    $amount = D::add($amount, D::mul($take, $layer['unit_cost']));
                }
                unset($layer);
                if (! D::isZero($remaining)) {
                    $blocked[$key] = 'historical_origin_quantity_insufficient';
                    $results[] = array_merge($event, ['status' => 'blocked', 'reason' => $blocked[$key], 'allocations' => []]);
                    continue;
                }
                $amount = D::money($amount);
                $sales[$id] = ['key' => $key, 'quantity' => $qty, 'allocations' => $result['allocations']];
            } elseif ($event['kind'] === 'return') {
                $original = (string) ($event['original_sale_source_id'] ?? '');
                if (! isset($sales[$original]) || $sales[$original]['key'] !== $key || ($event['return_disposition'] ?? null) !== 'restock' || empty($event['return_disposition_evidence'])) {
                    $blocked[$key] = 'return_origin_or_disposition_unproven';
                    $results[] = array_merge($event, ['status' => 'blocked', 'reason' => $blocked[$key], 'allocations' => []]);
                    continue;
                }
                $already = $returned[$original] ?? '0';
                if (D::gt(D::add($already, $qty), $sales[$original]['quantity'])) throw new RuntimeException('Historical FIFO return exceeds original sale');
                $remaining = $qty;
                $skip = $already;
                $amount = '0';
                foreach ($sales[$original]['allocations'] as $allocation) {
                    $available = $allocation['quantity'];
                    if (D::gte($skip, $available)) { $skip = D::sub($skip, $available); continue; }
                    $available = D::sub($available, $skip); $skip = '0';
                    $take = D::lt($remaining, $available) ? $remaining : $available;
                    if (D::isZero($take)) break;
                    $layers[$key][] = ['origin' => $id.':'.$allocation['origin'], 'date' => $event['date'], 'remaining' => D::qty($take), 'unit_cost' => $allocation['unit_cost']];
                    $result['allocations'][] = array_merge($allocation, ['quantity' => D::qty($take), 'returned_quantity' => D::qty($take)]);
                    $amount = D::add($amount, D::mul($take, $allocation['unit_cost']));
                    $remaining = D::sub($remaining, $take);
                }
                $returned[$original] = D::add($already, $qty);
                $amount = D::money($amount);
            } else throw new RuntimeException('Historical FIFO unsupported movement');
            $previous = $this->decimal($event['previous_posted_cost'] ?? '0', 2);
            $delta = D::money(D::sub($amount, $previous));
            $results[] = array_merge($result, ['status' => 'ready', 'reconstructed_cost' => $amount, 'previous_posted_cost' => $previous,
                'cogs_delta' => $event['kind'] === 'receipt' ? '0.00' : ($event['kind'] === 'return' ? D::money(D::sub('0', $delta)) : $delta)]);
        }
        $plan = ['version' => self::VERSION, 'events' => $results, 'layers' => $layers, 'blocked_items' => $blocked, 'opening_evidence' => $openings];
        $plan['plan_sha256'] = SolaStockJournalContract::payloadHash($plan);
        return $plan;
    }

    private function decimal(mixed $value, int $scale): string
    {
        if (! is_string($value) && ! is_int($value)) throw new RuntimeException('Historical FIFO decimal must be textual');
        $value = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,'.$scale.'})?$/D', $value)) throw new RuntimeException('Historical FIFO unsupported decimal precision');
        return D::round($value, $scale);
    }
}
