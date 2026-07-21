<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\ExternalRequestSignature;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class SolaBooksDeliveryTest extends TestCase
{
    use TenantAware;

    private function bootActiveIntegration(): void
    {
        $this->useTenantA();

        IntegrationSetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks'],
            [
                'mode' => 'active',
                'solabooks_organization_id' => 14,
                'meta' => [
                    'signing_key_id' => 'test-signing-key',
                    'signing_secret_encrypted' => Crypt::encryptString('test-signing-secret'),
                    'signing_protocol_version' => ExternalRequestSignature::VERSION,
                ],
            ]
        );

        foreach ([
            'inventory_asset' => 101,
            'grni' => 202,
            'cogs' => 303,
            'adjustment_gain' => 404,
            'adjustment_loss' => 405,
            'opening_offset' => 505,
            'accounts_receivable' => 606,
            'sales_revenue' => 707,
        ] as $type => $accountId) {
            IntegrationAccountMapping::query()->updateOrCreate(
                [
                    'organization_id' => TenantTestManager::ORG_A,
                    'integration' => 'solabooks',
                    'mapping_type' => $type,
                ],
                ['solabooks_account_id' => (string) $accountId, 'status' => 'mapped']
            );
        }
    }

    private function event(array $overrides = []): IntegrationOutboxEvent
    {
        $aggregateId = $overrides['aggregate_id'] ?? random_int(100000, 999999);
        $aggregateNumber = $overrides['aggregate_number'] ?? 'ADJ-'.$aggregateId;

        return IntegrationOutboxEvent::query()->create(array_merge([
            'organization_id' => TenantTestManager::ORG_A,
            'event_uuid' => 'evt-'.uniqid(),
            'integration' => 'solabooks',
            'event_type' => 'adjustment.posted',
            'aggregate_type' => 'StockAdjustment',
            'aggregate_id' => $aggregateId,
            'aggregate_number' => $aggregateNumber,
            'occurred_at' => now(),
            'payload' => [
                'document_date' => now()->toDateString(),
                'document_number' => $aggregateNumber,
                'total_inventory_value_change' => '25.00',
                'suggested_debit_account_mapping' => 'inventory_asset',
                'suggested_credit_account_mapping' => 'grni',
                'lines' => [],
            ],
            'status' => 'pending',
            'mapping_status' => 'complete',
            'attempts' => 0,
            'idempotency_key' => 'solabooks:adjustment.posted:StockAdjustment:'.$aggregateId,
        ], $overrides));
    }

    private function configureHttp(): void
    {
        Config::set('services.solabooks.journal_entries_url', 'https://books.test/api/v1/journal-entries');
        Config::set('services.solabooks.api_key', 'key');
        Config::set('services.solabooks.client_id', '7');
        Config::set('services.solabooks.organization_id', '14');
        Config::set('services.solabooks.api_base_url', 'https://books.test/api/v1');
    }

    #[Test]
    public function it_posts_a_balanced_idempotent_journal_to_solabooks_and_reconciles_the_response(): void
    {
        $this->bootActiveIntegration();
        $this->configureHttp();
        Http::fake([
            'books.test/*' => Http::response(['success' => true, 'data' => ['id' => 987, 'reference' => 'ok']], 201),
        ]);

        $input = $this->event();
        $event = app(SolaBooksOutboxDeliveryService::class)->deliver($input);

        $this->assertSame('sent', $event->status);
        $this->assertSame('987', $event->external_document_id);
        $this->assertNotNull($event->sent_at);

        Http::assertSent(function ($request) use ($input) {
            $body = $request->data();
            $raw = $request->body();
            $canonical = ExternalRequestSignature::canonicalString(
                'POST',
                '/api/v1/journal-entries',
                '',
                'application/json',
                $request->header('X-Solavel-Timestamp')[0],
                $request->header('X-Solavel-Nonce')[0],
                $request->header('X-Solavel-Content-SHA256')[0],
                (string) TenantTestManager::ORG_A,
                '14',
                $input->idempotency_key,
                'adjustment.posted',
                ExternalRequestSignature::VERSION,
            );

            return $request->hasHeader('X-API-Key', 'key')
                && $request->hasHeader('X-Client-Id', '7')
                && $request->hasHeader('X-Organization-Id', '14')
                && $request->hasHeader('Idempotency-Key', $input->idempotency_key)
                && hash('sha256', $raw) === $request->header('X-Solavel-Content-SHA256')[0]
                && ExternalRequestSignature::sign($canonical, 'test-signing-secret') === $request->header('X-Solavel-Signature')[0]
                && $body['lines'][0]['account_id'] === 101
                && $body['lines'][0]['debit'] === '25.00'
                && $body['lines'][1]['account_id'] === 404
                && $body['lines'][1]['credit'] === '25.00';
        });
    }

    #[Test]
    public function it_does_not_deliver_an_already_sent_event_again(): void
    {
        $this->bootActiveIntegration();
        $this->configureHttp();
        Http::fake();

        $event = $this->event(['status' => 'sent', 'sent_at' => now(), 'external_document_id' => '987']);
        $result = app(SolaBooksOutboxDeliveryService::class)->deliver($event);

        $this->assertSame('sent', $result->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_refreshes_a_historical_incomplete_mapping_snapshot_before_delivery(): void
    {
        $this->bootActiveIntegration();
        $this->configureHttp();
        Http::fake([
            'books.test/*' => Http::response(['success' => true, 'data' => ['id' => 988]], 201),
        ]);

        $event = $this->event(['mapping_status' => 'incomplete']);
        $result = app(SolaBooksOutboxDeliveryService::class)->deliver($event, true);

        $this->assertSame('complete', $result->mapping_status);
        $this->assertSame('sent', $result->status);
    }

    #[Test]
    public function it_marks_failure_with_bounded_backoff_when_credentials_are_missing(): void
    {
        $this->bootActiveIntegration();
        Config::set('services.solabooks.api_key', null);
        Config::set('services.solabooks.client_id', null);
        Config::set('services.solabooks.organization_id', null);
        IntegrationSetting::query()->where('integration', 'solabooks')->update(['solabooks_organization_id' => null, 'meta' => null]);

        $event = $this->event();

        try {
            app(SolaBooksOutboxDeliveryService::class)->deliver($event);
            $this->fail('delivery should fail without credentials');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('signing', $e->getMessage());
        }

        $event = $event->fresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->next_attempt_at);
        $this->assertStringContainsString('signing', $event->last_error);
    }

    #[Test]
    public function signing_key_rotation_stores_the_one_time_secret_encrypted_and_revocation_clears_local_use(): void
    {
        $this->bootActiveIntegration();
        $this->configureHttp();
        Http::fake([
            '*/external-signing-keys/rotate' => Http::response([
                'data' => ['key_id' => 'rotated-key', 'secret' => 'one-time-remote-secret', 'protocol_version' => 'v1'],
            ], 201),
            '*/external-signing-keys/rotated-key/revoke' => Http::response([
                'data' => ['key_id' => 'rotated-key', 'status' => 'revoked'],
            ]),
        ]);

        $service = app(SolaBooksOutboxDeliveryService::class);
        $setting = $service->rotateSigningKey();
        $this->assertSame('rotated-key', $setting->meta['signing_key_id']);
        $this->assertSame('one-time-remote-secret', $setting->signingSecret());
        $this->assertNotSame('one-time-remote-secret', $setting->meta['signing_secret_encrypted']);

        $service->revokeSigningKey('rotated-key');
        $setting = $setting->fresh();
        $this->assertNull($setting->signingSecret());
        $this->assertArrayNotHasKey('signing_key_id', $setting->meta);
    }
}
