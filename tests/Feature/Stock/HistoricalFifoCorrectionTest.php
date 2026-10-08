<?php
namespace Tests\Feature\Stock;

use App\Models\Tenant\{HistoricalFifoPlan, CostLayerConsumption, StockBalance, StockLedger};
use App\Services\Integration\{IntegrationOutboxService, SolaStockJournalContract};
use App\Services\Stock\Historical\HistoricalFifoReviewService;
use App\Services\Stock\{StockLedgerService, StockMovement};
use Illuminate\Support\Str;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class HistoricalFifoCorrectionTest extends TestCase
{
    use TenantAware;
    private function fixture(): array
    {
        $this->useTenantA();
        // Cost/projection cases use a fixture ownership port; the explicit
        // unproven-parent case restores the real final production verifier.
        $this->app->instance(\App\Services\Stock\Historical\HistoricalFifoSourceOwnership::class, new class {
            public function assert(array $event, StockLedger $row, int $org, string $segmentQuantity): void {}
        });
        $item = F::fifoItem(); $wh = F::warehouse();
        $svc = app(StockLedgerService::class);
        $old = $svc->post([new StockMovement('in', $item->id, $wh->id, '5', 'test', 1, unitCost: '2', movedAt: '2024-01-02 00:00:00')], 'fifo-fixture:old')[0];
        $new = $svc->post([new StockMovement('in', $item->id, $wh->id, '5', 'test', 2, unitCost: '4', movedAt: '2024-01-04 00:00:00')], 'fifo-fixture:new')[0];
        $later = $svc->post([new StockMovement('out', $item->id, $wh->id, '3', 'test', 3, movedAt: '2024-01-05 00:00:00')], 'fifo-fixture:later')[0];
        $base = ['stock_item_id' => $item->id, 'warehouse_id' => $wh->id, 'sequence' => 1];
        $event = fn ($id, $date, $kind, $q, $extra = []) => array_merge($base, ['source_id' => $id, 'date' => $date, 'kind' => $kind, 'quantity' => $q,
            'finance_document_id' => 1, 'finance_document_type' => $kind === 'receipt' ? 'bill' : 'invoice', 'finance_line_ids' => [1], 'finance_source_id' => 'invoice:'.$id, 'source_row' => $id, 'finance_line_id' => 1, 'correction_uuid' => (string) Str::uuid(), 'unit_conversion' => ['test_snapshot' => true]], $extra);
        $segment = fn ($row) => [['ledger_id' => $row->id, 'quantity' => (string) $row->quantity, 'cost' => (string) $row->total_cost]];
        $events = [$event('old', '2024-01-02', 'receipt', '5', ['acquisition_unit_cost' => '2', 'existing_stock_segments' => $segment($old), 'previous_posted_cost' => '10']),
            $event('new', '2024-01-04', 'receipt', '5', ['acquisition_unit_cost' => '4', 'existing_stock_segments' => $segment($new), 'previous_posted_cost' => '20']),
            $event('paid-bonus-free', '2024-01-03', 'out', '4'),
            $event('later', '2024-01-05', 'out', '3', ['existing_stock_segments' => $segment($later), 'previous_posted_cost' => '6'])];
        $openings = [$item->id.':'.$wh->id => ['business_date' => '2024-01-01', 'evidence_reference' => 'explicit-synthetic-empty-opening', 'hash' => str_repeat('a',64), 'reviewed_by' => 337, 'layers' => []]];
        return [$item, $wh, $events, $openings, $later];
    }

    public function test_real_correction_outbox_builds_scoped_cost_difference_with_genuine_conversion(): void
    {
        [$item, $wh, $events, $openings] = $this->fixture();
        $db = \Illuminate\Support\Facades\DB::connection('tenant');
        $db->table('organizations')->insert(['id' => 14, 'central_org_id' => $item->organization_id,
            'name' => 'Isolated correction Finance', 'setup_status' => 'complete']);
        $mapping = \App\Models\Tenant\IntegrationOrganizationMapping::create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7,
            'central_organization_id' => $item->organization_id, 'tenant_database_identity' => $db->getDatabaseName(),
            'finance_organization_id' => 14, 'solastock_organization_id' => $item->organization_id,
            'contract_version' => 'solastock-journal.v2', 'status' => 'verified_hold',
            'activation_state' => 'maintenance_hold', 'base_currency_code' => 'JOD', 'verified_at' => now(),
        ]);
        \App\Models\Tenant\IntegrationSetting::create(['integration' => 'solabooks', 'mode' => 'paused',
            'solabooks_organization_id' => 14, 'meta' => ['client_id' => 7,
                'central_organization_id' => $item->organization_id, 'signing_key_id' => 'isolated-correction-key',
                'transport_enabled_workflows' => ['stock.historical_fifo_cost_corrected.v1'],
                'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD'],
                    'currency_precisions' => ['JOD' => 2], 'money_scale' => 2, 'rate_scale' => 8,
                    'inventory_valuation_basis' => \App\Services\Integration\FinanceBaseValuation::BASIS]]]);
        $unit = \App\Models\Tenant\Unit::create(['code' => 'CORRECTION-EA', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item->update(['base_unit_id' => $unit->id]);
        $identities = [['item', (string) $item->id], ['unit', (string) $unit->id], ['warehouse', (string) $wh->id]];
        foreach (['inventory_asset' => 100, 'cogs' => 300] as $role => $accountId) {
            $db->table('accounts')->insert(['id' => $accountId, 'organization_id' => 14, 'code' => (string) $accountId,
                'name' => $role, 'type' => $role === 'cogs' ? 'expense' : 'asset', 'is_active' => true, 'is_postable' => true]);
            $account = \App\Models\Tenant\IntegrationAccountMapping::create(['mapping_type' => $role,
                'integration' => 'solabooks', 'solabooks_account_id' => $accountId, 'status' => 'verified']);
            $identities[] = ['account_role', (string) $account->id];
        }
        foreach ($identities as $index => [$type, $id]) {
            \App\Models\Tenant\IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(),
                'organization_mapping_uuid' => $mapping->mapping_uuid, 'central_client_id' => 7,
                'central_organization_id' => $item->organization_id, 'finance_organization_id' => 14,
                'solastock_organization_id' => $item->organization_id, 'entity_type' => $type,
                'solastock_record_id' => $id, 'solabooks_record_id' => (string) (700 + $index), 'status' => 'verified']);
        }
        foreach ($events as &$event) {
            $normalized = app(\App\Services\Catalog\UnitConversionResolver::class)->normalizeLine([
                'item_id' => $item->id, 'quantity' => $event['quantity'], 'entered_unit_id' => $unit->id], 'quantity');
            $event['unit_conversion'] = ['item_id' => $item->id, 'source_quantity' => $normalized['entered_qty'],
                'source_unit_id' => $normalized['entered_unit_id'], 'base_quantity' => $normalized['quantity'],
                'base_unit_id' => $normalized['base_unit_id'], 'conversion_id' => $normalized['unit_conversion_id'],
                'factor' => $normalized['unit_conversion_factor'], 'version' => $normalized['unit_conversion_version'],
                'hash' => $normalized['unit_conversion_hash'], 'precision' => $normalized['unit_conversion_precision'],
                'rounding_mode' => $normalized['unit_conversion_rounding_mode']];
        }
        unset($event);
        $review = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, $openings, 337);
        $result = app(StockLedgerService::class)->applyHistoricalFifo($review);
        $outbox = \App\Models\Tenant\IntegrationOutboxEvent::query()->where('event_type', 'stock.historical_fifo_cost_corrected.v1')->orderBy('id')->get();
        $this->assertCount(2, $outbox);
        foreach ($outbox as $event) {
            $contract = app(\App\Services\Integration\SolaStockJournalContractBuilder::class)->build($event);
            $this->assertSame('JOD', $contract['currency']['transaction_code']);
            $this->assertCount(2, $contract['lines']);
            $this->assertSame([300, 100], array_column($contract['lines'], 'account_id'));
            $causal = $event->payload['historical_fifo_correction'];
            $this->assertSame($causal, $contract['source']['historical_fifo_correction']);
            $this->assertTrue($causal['original_quantity_is_reference']);
            $this->assertSame($causal['cogs_delta'], $contract['lines'][0]['debit']);
            $this->assertSame($causal['cogs_delta'], $contract['lines'][1]['credit']);
            $this->assertSame($causal['business_date'], $contract['source']['transaction_date']);
            $this->assertSame($causal['quantity'], $contract['inventory_quantities'][0]['base_quantity']);
            $this->assertSame($unit->id, $event->payload['lines'][0]['unit_conversion']['base_unit_id']);
        }
        $this->assertSame($result, app(StockLedgerService::class)->applyHistoricalFifo($review));
        $this->assertSame(2, \App\Models\Tenant\IntegrationOutboxEvent::query()->count());
    }

    public function test_append_only_quantity_and_later_cost_revision_are_atomic_and_idempotent(): void
    {
        [$item, $wh, $events, $openings, $later] = $this->fixture();
        $original = StockLedger::query()->orderBy('id')->get()->map->getAttributes()->all();
        $oldConsumptions = CostLayerConsumption::query()->get()->map->getAttributes()->all();
        $this->mock(IntegrationOutboxService::class, fn ($mock) => $mock->shouldReceive('record')->twice()->withArgs(fn ($type, $doc) => $type === 'stock.historical_fifo_cost_corrected.v1' && $doc->causal_payload['quantity'] > 0)->andReturn(new \App\Models\Tenant\IntegrationOutboxEvent));
        $review = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, $openings, 337);
        $result = app(StockLedgerService::class)->applyHistoricalFifo($review);
        $this->assertCount(2, $result['correction_ids']);
        $this->assertSame($original, StockLedger::query()->whereIn('id', array_column($original, 'id'))->orderBy('id')->get()->map->getAttributes()->all());
        $balance = StockBalance::query()->where('item_id', $item->id)->first();
        $this->assertSame('3.0000', $balance->on_hand_qty); $this->assertSame('12.00', $balance->total_value);
        $this->assertSame(2, StockLedger::query()->where('source_type', \App\Models\Tenant\HistoricalFifoCorrection::class)->count());
        $this->assertSame(1, StockLedger::query()->where('quantity', 0)->count());
        $this->assertSame(count($oldConsumptions), CostLayerConsumption::withoutGlobalScope('active_fifo_projection')->whereNotNull('superseded_fifo_correction_id')->count());
        $before = SolaStockJournalContract::payloadHash(app(HistoricalFifoReviewService::class)->projectionSnapshot($item->organization_id, $review->plan));
        $this->assertSame($result, app(StockLedgerService::class)->applyHistoricalFifo($review));
        $this->assertSame($before, SolaStockJournalContract::payloadHash(app(HistoricalFifoReviewService::class)->projectionSnapshot($item->organization_id, $review->plan)));
        $this->expectException(\RuntimeException::class);
        app(StockLedgerService::class)->reverse('fifo-fixture:later', 'fifo-fixture:stale-reverse');
    }

    public function test_proven_restock_return_uses_original_consumed_cost_not_later_purchase_price(): void
    {
        [$item, $wh, $events, $openings] = $this->fixture();
        $events[] = ['source_id' => 'return', 'finance_document_id' => 2, 'finance_document_type' => 'credit_note', 'finance_line_ids' => [5], 'finance_source_id' => 'return:1', 'source_row' => 'return', 'finance_line_id' => 5,
            'stock_item_id' => $item->id, 'warehouse_id' => $wh->id, 'sequence' => 1, 'date' => '2024-01-06', 'kind' => 'return', 'quantity' => '1',
            'original_sale_source_id' => 'paid-bonus-free', 'return_disposition' => 'restock', 'return_disposition_evidence' => 'approved synthetic inspection',
            'correction_uuid' => (string) Str::uuid(), 'unit_conversion' => ['test_snapshot' => true]];
        $this->mock(IntegrationOutboxService::class, fn ($mock) => $mock->shouldReceive('record')->times(3)->andReturn(new \App\Models\Tenant\IntegrationOutboxEvent));
        $review = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, $openings, 337);
        app(StockLedgerService::class)->applyHistoricalFifo($review);
        $balance = StockBalance::query()->where('item_id', $item->id)->first();
        $this->assertSame('4.0000', $balance->on_hand_qty); $this->assertSame('14.00', $balance->total_value);
        $return = \App\Models\Tenant\HistoricalFifoCorrection::query()->where('source_id', 'return:1')->firstOrFail();
        $this->assertSame('2.00', $return->causal_payload['reconstructed_cost']);
        $this->assertSame('-2.00', $return->causal_payload['cogs_delta']);
        $this->assertSame('2.0000', \App\Models\Tenant\CostLayer::query()->whereIn('source_ledger_id', $return->ledger_ids)->firstOrFail()->unit_cost);
        // Retained superseded layers are audit evidence, never active valuation.
        \App\Models\Tenant\CostLayer::query()->create(['organization_id' => $item->organization_id, 'item_id' => $item->id, 'warehouse_id' => $wh->id,
            'received_at' => '2024-01-06 00:00:00', 'unit_cost' => '99', 'original_qty' => '1', 'remaining_qty' => '1',
            'source_ledger_id' => $return->ledger_ids[0], 'superseded_fifo_correction_id' => $review->id]);
        $this->assertTrue(app(\App\Services\Stock\IntegrityChecker::class)->check('tenant', $item->organization_id)['ok']);
    }

    public function test_unmigrated_tenant_schema_keeps_ordinary_fifo_and_reversal_compatible(): void
    {
        [$item, $wh] = $this->fixture();
        // Read scopes conditionally detect the active tenant schema. Simulate the
        // older schema without changing production data or running destructive DDL.
        \Illuminate\Support\Facades\Schema::shouldReceive('connection')->andReturnSelf();
        \Illuminate\Support\Facades\Schema::shouldReceive('hasColumn')->andReturn(false);
        \Illuminate\Support\Facades\Schema::shouldReceive('hasTable')->andReturn(false);
        app(StockLedgerService::class)->reverse('fifo-fixture:later', 'fifo-fixture:old-schema-reversal');
        $this->assertSame('10.0000', StockBalance::query()->where('item_id', $item->id)->first()->on_hand_qty);
        $this->assertSame(4, StockLedger::query()->count());
    }

    public function test_unknown_opening_refuses_application_without_ledger_effects(): void
    {
        [$item, $wh, $events] = $this->fixture();
        $review = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, [], 337);
        $before = StockLedger::query()->count();
        try { app(StockLedgerService::class)->applyHistoricalFifo($review); $this->fail('Unknown opening applied'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('origin evidence', $e->getMessage()); }
        $this->assertSame($before, StockLedger::query()->count());
        $this->assertSame('blocked', $review->fresh()->status);
    }

    public function test_interruption_rolls_back_and_retry_applies_once(): void
    {
        [$item, $wh, $events, $openings] = $this->fixture();
        $review = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, $openings, 337);
        $this->mock(IntegrationOutboxService::class, fn ($mock) => $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('synthetic transport preparation interruption')));
        try { app(StockLedgerService::class)->applyHistoricalFifo($review); $this->fail('Interruption did not refuse'); } catch (\RuntimeException $e) { $this->assertSame('synthetic transport preparation interruption', $e->getMessage()); }
        $this->assertSame(3, StockLedger::query()->count());
        $this->assertSame('reviewed', $review->fresh()->status);
        $this->assertSame(1, CostLayerConsumption::query()->count());
        $this->mock(IntegrationOutboxService::class, fn ($mock) => $mock->shouldReceive('record')->twice()->andReturn(new \App\Models\Tenant\IntegrationOutboxEvent));
        app(StockLedgerService::class)->applyHistoricalFifo($review);
        $this->assertSame(5, StockLedger::query()->count());
    }

    public function test_original_financial_document_binding_cannot_be_assigned_to_an_unproven_parent(): void
    {
        [$item, $wh, $events, $openings] = $this->fixture();
        $this->app->forgetInstance(\App\Services\Stock\Historical\HistoricalFifoSourceOwnership::class);
        $events[0]['finance_document_id'] = 999;
        $events[0]['finance_document_type'] = 'bill';
        $review = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, $openings, 337);
        $before = SolaStockJournalContract::payloadHash(app(HistoricalFifoReviewService::class)->projectionSnapshot($item->organization_id, $review->plan));
        try { app(StockLedgerService::class)->applyHistoricalFifo($review); $this->fail('Unproven source parent accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('document binding unproven', $e->getMessage()); }
        $this->assertSame($before, SolaStockJournalContract::payloadHash(app(HistoricalFifoReviewService::class)->projectionSnapshot($item->organization_id, $review->plan)));
        $this->assertSame('reviewed', $review->fresh()->status);
    }

    public function test_review_keeps_planner_fields_when_nested_validation_excludes_unvalidated_keys(): void
    {
        [$item, $wh, $events] = $this->fixture();
        $request = \Illuminate\Http\Request::create('/historical-fifo/review', 'POST', ['correction_uuid' => (string) Str::uuid(), 'batch_id' => (string) Str::uuid(), 'events' => $events, 'openings' => [$item->id.':'.$wh->id => null], 'reviewed' => true]);
        $request->attributes->set('verified_workspace_action', 'historical-fifo.review');
        $request->setUserResolver(fn () => (object) ['id' => 337]);
        $response = app(\App\Http\Controllers\Api\V1\HistoricalFifoController::class)->review($request, app(HistoricalFifoReviewService::class));
        $this->assertSame(200, $response->getStatusCode());
        $review = HistoricalFifoPlan::query()->latest('id')->firstOrFail();
        $this->assertSame(4, count($review->plan['events']));
        $this->assertSame('old', $review->plan['events'][0]['source_id']);
        $this->assertSame('blocked', $review->status);
    }

    public function test_second_new_plan_covers_prior_quantity_and_zero_quantity_cost_revisions_without_reposting(): void
    {
        [$item, $wh, $events, $openings] = $this->fixture();
        $this->mock(IntegrationOutboxService::class, fn ($mock) => $mock->shouldReceive('record')->twice()->andReturn(new \App\Models\Tenant\IntegrationOutboxEvent));
        $first = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, $openings, 337);
        app(StockLedgerService::class)->applyHistoricalFifo($first);
        foreach (\App\Models\Tenant\HistoricalFifoCorrection::query()->get() as $doc) {
            foreach (StockLedger::query()->whereIn('id', $doc->ledger_ids)->get() as $row) {
                foreach ($events as &$event) if ($event['finance_source_id'] === $doc->source_id) {
                    if ((string) $row->quantity === '0.0000') $event['existing_cost_revision_segments'][] = ['ledger_id' => $row->id, 'cost' => (string) $row->total_cost];
                    else $event['existing_stock_segments'][] = ['ledger_id' => $row->id, 'quantity' => (string) $row->quantity, 'cost' => (string) $row->total_cost];
                    $event['previous_posted_cost'] = $doc->causal_payload['reconstructed_cost'];
                }
                unset($event);
            }
        }
        $later = app(StockLedgerService::class)->post([new StockMovement('in', $item->id, $wh->id, '2', 'test', 9, unitCost: '6', movedAt: '2024-01-07 00:00:00')], 'fifo-fixture:unrelated-later')[0];
        $events[] = ['source_id' => 'later-acquisition', 'finance_source_id' => 'bill:later', 'finance_document_id' => 9, 'finance_document_type' => 'bill', 'finance_line_id' => 9, 'finance_line_ids' => [9],
            'stock_item_id' => $item->id, 'warehouse_id' => $wh->id, 'sequence' => 1, 'date' => '2024-01-07', 'kind' => 'receipt', 'quantity' => '2', 'acquisition_unit_cost' => '6', 'previous_posted_cost' => '12',
            'existing_stock_segments' => [['ledger_id' => $later->id, 'quantity' => '2', 'cost' => '12']]];
        $before = StockLedger::query()->get()->map->getAttributes()->all();
        $second = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), $first->batch_id, $events, $openings, 337);
        $result = app(StockLedgerService::class)->applyHistoricalFifo($second);
        $this->assertSame([], $result['correction_ids']);
        $this->assertSame($before, StockLedger::query()->get()->map->getAttributes()->all());
        $this->assertSame('5.0000', StockBalance::query()->where('item_id', $item->id)->first()->on_hand_qty);
        $this->assertSame('24.00', StockBalance::query()->where('item_id', $item->id)->first()->total_value);
    }

    public function test_prior_correction_remains_bound_to_immutable_original_finance_parent(): void
    {
        [$item, $wh, $events, $openings] = $this->fixture();
        $this->mock(IntegrationOutboxService::class, fn ($mock) => $mock->shouldReceive('record')->twice()->andReturn(new \App\Models\Tenant\IntegrationOutboxEvent));
        $review = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, $openings, 337);
        app(StockLedgerService::class)->applyHistoricalFifo($review);
        $prior = StockLedger::query()->where('source_type', \App\Models\Tenant\HistoricalFifoCorrection::class)->where('quantity', '>', 0)->firstOrFail();
        $this->app->forgetInstance(\App\Services\Stock\Historical\HistoricalFifoSourceOwnership::class);
        $proof = app(\App\Services\Stock\Historical\HistoricalFifoSourceOwnership::class);
        $proof->assert($events[2], $prior, $item->organization_id, '4'); $this->addToAssertionCount(1);
        foreach ([['finance_document_id' => 99], ['finance_document_type' => 'bill']] as $wrong) {
            try { $proof->assert(array_merge($events[2], $wrong), $prior, $item->organization_id, '4'); $this->fail('Prior correction moved to another parent'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('original parent mismatch', $e->getMessage()); }
        }
    }

    public function test_new_native_activity_after_review_refuses_stale_projection(): void
    {
        [$item, $wh, $events, $openings] = $this->fixture();
        $review = app(HistoricalFifoReviewService::class)->review((string) Str::uuid(), (string) Str::uuid(), $events, $openings, 337);
        app(StockLedgerService::class)->post([new StockMovement('in', $item->id, $wh->id, '1', 'test', 4, unitCost: '4')], 'fifo-fixture:new-client-record');
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('changed since review');
        app(StockLedgerService::class)->applyHistoricalFifo($review);
    }
}
