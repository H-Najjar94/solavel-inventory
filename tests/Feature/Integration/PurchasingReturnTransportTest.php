<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\PurchasingDocumentOutbox;
use App\Services\Integration\ExternalRequestSignature;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContract;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CommittedTenantFixture;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Exercise the actual signed transport; native receivers separately prove business idempotency. */
#[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
final class PurchasingReturnTransportTest extends TestCase
{
    use TenantAware { tearDown as private finishTenantTestLifecycle; }

    private ?CommittedTenantFixture $fixture = null;
    private const SECRET = 'isolated-return-transport-signing-secret-32';
    private const URL = 'https://finance.example.invalid/api/v1/purchasing/receipts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        $this->fixture = new CommittedTenantFixture($this->tenantTestManager);
        config(['integration_safety.solabooks_delivery_enabled' => true,
            'services.solabooks.journal_entries_url' => 'https://finance.example.invalid/api/v1/journal-entries']);
        IntegrationSetting::create(['integration'=>'solabooks','mode'=>'active','solabooks_organization_id'=>14,
            'meta'=>['client_id'=>7,'signing_key_id'=>'test-key','signing_protocol_version'=>'v1',
                'api_key_encrypted'=>Crypt::encryptString('isolated-api-key'),
                'signing_secret_encrypted'=>Crypt::encryptString(self::SECRET)]]);
        $this->fixture->commit();
    }

    protected function tearDown(): void
    {
        try { $this->fixture?->restore(); }
        finally { $this->finishTenantTestLifecycle(); }
    }

    private function document(string $event): PurchasingDocumentOutbox
    {
        $payload=['source_app'=>'solastock','schema_version'=>'purchasing.v1',
            'contract_version'=>SolaStockJournalContract::VERSION,'event_type'=>$event,
            'event_uuid'=>(string)Str::uuid(),'external_source_key'=>'return:'.Str::uuid(),
            'identity'=>['central_client_id'=>7,'central_organization_id'=>TenantTestManager::ORG_A,
                'integration_mapping_id'=>1,'organization_mapping_uuid'=>(string)Str::uuid()]];
        return new PurchasingDocumentOutbox(['organization_id'=>TenantTestManager::ORG_A,
            'event_uuid'=>$payload['event_uuid'],'source_key'=>$payload['external_source_key'],
            'status'=>'processing','lease_token'=>(string)Str::uuid(),'lease_expires_at'=>now()->addMinute(),
            'payload'=>$payload,'payload_hash'=>hash('sha256',SolaStockJournalContract::canonicalJson($payload))]);
    }

    public function test_return_and_receipt_events_use_existing_endpoint_and_signed_immutable_replay(): void
    {
        Http::fake(fn () => Http::response(['data'=>['source_id'=>123,'state'=>'matched','debit_note_id'=>456]],200));
        $service=app(SolaBooksOutboxDeliveryService::class);
        // Confirmed receipts have native dependency coverage in PurchasingHandoffTest.
        foreach (['purchasing.return.confirmed','purchasing.return.reversed','purchasing.receipt.reversed'] as $event) {
            $document=$this->document($event);
            $first=$service->sendPurchasingDocument($document);
            $second=$service->sendPurchasingDocument($document);
            $this->assertSame($first,$second);
            $this->assertTrue($first['successful']);
        }
        Http::assertSentCount(6);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(self::URL,$request->url());
            $body=json_decode($request->body(),true,512,JSON_THROW_ON_ERROR);
            $h=fn(string $name):string=>$request->header($name)[0];
            $this->assertSame($body['external_source_key'],$h('Idempotency-Key'));
            $this->assertSame($body['event_uuid'],$h('X-SolaStock-Event-UUID'));
            $this->assertSame($body['event_type'],$h('X-Solavel-Event-Type'));
            $this->assertSame(hash('sha256',$request->body()),$h('X-Solavel-Content-SHA256'));
            $canonical=ExternalRequestSignature::canonicalString('POST','/api/v1/purchasing/receipts','',
                'application/json',$h('X-Solavel-Timestamp'),$h('X-Solavel-Nonce'),$h('X-Solavel-Content-SHA256'),
                (string)TenantTestManager::ORG_A,'14',$body['external_source_key'],$body['event_type'],'v1',
                SolaStockJournalContract::VERSION,'7',(string)TenantTestManager::ORG_A,'1');
            $this->assertSame(ExternalRequestSignature::sign($canonical,self::SECRET),$h('X-Solavel-Signature'));
        }
        $this->assertSame(0,DB::connection('tenant')->table('purchasing_document_outbox')->count());
        $this->assertRemoteConflict();
        $this->assertUnsafeInputs();
    }

    private function assertRemoteConflict(): void
    {
        Http::fake(fn () => Http::response(['error'=>['code'=>'return_source_conflict']],409));
        $document=$this->document('purchasing.return.confirmed');
        $result=app(SolaBooksOutboxDeliveryService::class)->sendPurchasingDocument($document);
        $this->assertFalse($result['successful']);
        $this->assertSame(409,$result['status']);
        $this->assertSame('processing',$document->status);
        Http::assertSentCount(1);
    }

    private function assertUnsafeInputs(): void
    {
        Http::fake();
        foreach (['unknown','tampered','expired'] as $case) {
            $document=$this->document($case==='unknown'?'purchasing.return.arbitrary':'purchasing.return.confirmed');
            if ($case==='tampered') $document->payload_hash=str_repeat('0',64);
            if ($case==='expired') $document->lease_expires_at=now()->subMinute();
            try { app(SolaBooksOutboxDeliveryService::class)->sendPurchasingDocument($document);$this->fail('Unsafe transport accepted'); }
            catch (\RuntimeException) { Http::assertNothingSent(); }
        }
    }
}
