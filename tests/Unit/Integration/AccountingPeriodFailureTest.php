<?php

namespace Tests\Unit\Integration;

use App\Services\Integration\DurableOutboxTransportService;
use App\Services\Integration\OutboxFailureClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccountingPeriodFailureTest extends TestCase
{
    #[Test]
    public function closed_period_is_permanent_so_it_is_never_auto_retried_but_is_reviewable(): void
    {
        $r = (new OutboxFailureClassifier)->classify(422, 'accounting_period_invalid', 'period closed');
        $this->assertFalse($r['retryable']);
        $this->assertSame('business_permanent', $r['category']);
        $this->assertSame('accounting_period_invalid', $r['code']);
        $this->assertTrue(DurableOutboxTransportService::isReviewableReceiverCode($r['code']));
    }

    #[Test]
    public function other_permanent_codes_stay_blocked(): void
    {
        foreach (['currency_invalid', 'idempotency_content_conflict', 'mapping_missing', null] as $code) {
            $this->assertFalse(DurableOutboxTransportService::isReviewableReceiverCode($code));
        }
    }
}
