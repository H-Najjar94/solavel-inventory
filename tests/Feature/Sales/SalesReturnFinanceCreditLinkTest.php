<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Api\V1\SalesReturnController;
use App\Models\Tenant\SalesDocumentOutbox;
use App\Models\Tenant\SalesReturn;
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

    #[Test]
    public function the_return_detail_route_requires_the_sales_view_permission(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v1.sales-returns.show');
        $this->assertContains('perm:inventory.view_sales', $route->gatherMiddleware());
    }
}
