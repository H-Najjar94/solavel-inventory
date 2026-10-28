<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Api\V1\SalesReturnController;
use App\Models\Tenant\SalesDocumentOutbox;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\IntegrationOrganizationMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** MySQL-backed; not executed in the defects-20261028 worktree. */
class SalesReturnFinanceCreditLinkTest extends TestCase
{
    use TenantAware;

    private function credit(SalesReturn $r): ?array
    {
        $c = app(SalesReturnController::class);
        $m = new \ReflectionMethod($c, 'financeCredit');
        $m->setAccessible(true);

        return $m->invoke($c, $r);
    }

    private function ret(int $org, int $id): SalesReturn
    {
        $r = new SalesReturn(['return_number' => 'RMA-'.$id, 'status' => 'posted', 'return_date' => now()->toDateString()]);
        $r->organization_id = $org;
        $r->id = $id;

        return $r;
    }

    private function outbox(int $org, int $returnId, string $type, array $response, string $status = 'sent'): void
    {
        SalesDocumentOutbox::query()->create(['organization_id' => $org, 'organization_mapping_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'source_key' => 'k-'.uniqid(), 'event_uuid' => (string) \Illuminate\Support\Str::uuid(), 'event_type' => $type,
            'payload' => ['return' => ['id' => $returnId]], 'payload_hash' => str_repeat('a', 64), 'status' => $status, 'response' => $response]);
    }

    #[Test]
    public function linked_return_exposes_created_not_a_stale_finance_state_and_link(): void
    {
        $this->useTenantA();
        config(['supplier_terms.finance_url' => 'https://finance.test']);
        $this->outbox(TenantTestManager::ORG_A, 501, 'sales.return.confirmed', ['credit_note_id' => 77, 'state' => 'draft_review']);
        $r = $this->credit($this->ret(TenantTestManager::ORG_A, 501));
        $this->assertSame('created', $r['state'], 'the stored draft_review response must not be shown as the live credit state');
        $this->assertSame('https://finance.test/ar/credit-notes/77', $r['url']);
    }

    #[Test]
    public function a_reversed_return_still_reports_reversed_and_a_posted_response_reports_created(): void
    {
        $this->useTenantA();
        $this->outbox(TenantTestManager::ORG_A, 505, 'sales.return.confirmed', ['credit_note_id' => 5, 'state' => 'credit_posted']);
        $this->assertSame('created', $this->credit($this->ret(TenantTestManager::ORG_A, 505))['state']);
        $this->outbox(TenantTestManager::ORG_A, 505, 'sales.return.reversed', []);
        $this->assertSame('reversed', $this->credit($this->ret(TenantTestManager::ORG_A, 505))['state']);
    }

    #[Test]
    public function legacy_return_without_handoff_shows_nothing_and_missing_credit_id_has_no_link(): void
    {
        $this->useTenantA();
        $this->assertNull($this->credit($this->ret(TenantTestManager::ORG_A, 502)));
        $this->outbox(TenantTestManager::ORG_A, 503, 'sales.return.confirmed', [], 'retry');
        $this->assertNull($this->credit($this->ret(TenantTestManager::ORG_A, 503))['url']);
    }

    #[Test]
    public function another_organizations_handoff_is_never_exposed(): void
    {
        $this->useTenantB();
        $this->outbox(TenantTestManager::ORG_B, 504, 'sales.return.confirmed', ['credit_note_id' => 9, 'state' => 'credit_posted']);
        $this->useTenantA();
        $this->assertNull($this->credit($this->ret(TenantTestManager::ORG_A, 504)));
    }

    private const FIN_ORG = 9101;

    private function creditTable(): void
    {
        $schema = Schema::connection('tenant');
        $schema->dropIfExists('credit_notes');
        $schema->create('credit_notes', function ($t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->string('status', 30)->default('draft');
            $t->string('posting_status', 30)->default('draft');
        });
    }

    private function mapOrg(int $org, string $status = 'verified'): void
    {
        DB::connection('tenant')->table('integration_organization_mappings')->delete();
        IntegrationOrganizationMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7,
            'central_organization_id' => $org, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => self::FIN_ORG, 'solastock_organization_id' => $org, 'contract_version' => 'solastock-journal.v2',
            'status' => $status, 'activation_state' => 'active', 'base_currency_code' => 'JOD', 'verified_at' => now()]);
    }

    private function live(int $id, string $status, string $posting, int $finOrg = self::FIN_ORG, int $returnId = 600): ?array
    {
        DB::connection('tenant')->table('credit_notes')->insert(['id' => $id, 'organization_id' => $finOrg, 'status' => $status, 'posting_status' => $posting]);
        $this->outbox(TenantTestManager::ORG_A, $returnId, 'sales.return.confirmed', ['credit_note_id' => $id, 'state' => 'draft_review']);

        return $this->credit($this->ret(TenantTestManager::ORG_A, $returnId));
    }

    #[Test]
    public function live_solacount_status_is_shown_for_draft_posted_applied_refunded_and_void(): void
    {
        $this->useTenantA();
        $this->creditTable();
        $this->mapOrg(TenantTestManager::ORG_A);
        try {
            $this->assertSame('draft_review', $this->live(11, 'draft', 'draft', self::FIN_ORG, 611)['state']);
            $this->assertSame('credit_posted', $this->live(12, 'unapplied', 'posted', self::FIN_ORG, 612)['state']);
            $this->assertSame('credit_partially_applied', $this->live(13, 'partially_applied', 'posted', self::FIN_ORG, 613)['state']);
            $this->assertSame('credit_applied', $this->live(14, 'fully_applied', 'posted', self::FIN_ORG, 614)['state']);
            $this->assertSame('credit_refunded', $this->live(15, 'refunded', 'posted', self::FIN_ORG, 615)['state']);
            $this->assertSame('credit_voided', $this->live(16, 'void', 'void', self::FIN_ORG, 616)['state']);
            $before = DB::connection('tenant')->table('credit_notes')->count();
            $this->assertSame(6, $before);
        } finally {
            Schema::connection('tenant')->dropIfExists('credit_notes');
            DB::connection('tenant')->table('integration_organization_mappings')->delete();
        }
    }

    #[Test]
    public function missing_row_missing_table_no_mapping_and_unverified_mapping_degrade_to_created(): void
    {
        $this->useTenantA();
        $this->outbox(TenantTestManager::ORG_A, 620, 'sales.return.confirmed', ['credit_note_id' => 21]);
        Schema::connection('tenant')->dropIfExists('credit_notes');
        $this->mapOrg(TenantTestManager::ORG_A);
        $this->assertSame('created', $this->credit($this->ret(TenantTestManager::ORG_A, 620))['state'], 'missing table');
        $this->creditTable();
        try {
            $this->assertSame('created', $this->credit($this->ret(TenantTestManager::ORG_A, 620))['state'], 'missing row');
            DB::connection('tenant')->table('integration_organization_mappings')->delete();
            $this->assertSame('created', $this->live(22, 'unapplied', 'posted', self::FIN_ORG, 621)['state'], 'no mapping');
            $this->mapOrg(TenantTestManager::ORG_A, 'pending');
            $this->assertSame('created', $this->live(23, 'unapplied', 'posted', self::FIN_ORG, 622)['state'], 'unverified mapping');
        } finally {
            Schema::connection('tenant')->dropIfExists('credit_notes');
            DB::connection('tenant')->table('integration_organization_mappings')->delete();
        }
    }

    #[Test]
    public function another_organizations_credit_with_the_same_id_is_never_exposed(): void
    {
        $this->useTenantA();
        $this->creditTable();
        $this->mapOrg(TenantTestManager::ORG_A);
        try {
            $r = $this->live(31, 'void', 'void', self::FIN_ORG + 1, 630);
            $this->assertSame('created', $r['state'], 'a credit owned by a different finance org must not be read');
        } finally {
            Schema::connection('tenant')->dropIfExists('credit_notes');
            DB::connection('tenant')->table('integration_organization_mappings')->delete();
        }
    }

    #[Test]
    public function the_return_detail_route_requires_the_sales_view_permission(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v1.sales-returns.show');
        $this->assertContains('perm:inventory.view_sales', $route->gatherMiddleware());
    }
}
