<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\MpesaTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Helpers\TenantTestHelper;

/**
 * Unit tests for MpesaTransaction model.
 *
 * Covers:
 *  - markAsMatched()
 *  - markAsCompleted()
 *  - markAsFailed()
 *  - canRetry()
 *  - scopeUnmatched()
 *  - scopeByShortcode()
 *  - scopeRecent()
 */
class MpesaTransactionModelTest extends TestCase
{
    use DatabaseTransactions, TenantTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTestTenant(); // ensures tenant schema + mpesa_transactions table exist
    }

    protected function tearDown(): void
    {
        $this->tearDownTenantContext();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeTransaction(array $overrides = []): MpesaTransaction
    {
        return MpesaTransaction::create(array_merge([
            'transaction_id'     => 'TXN' . Str::upper(Str::random(8)),
            'transaction_type'   => 'Pay Bill',
            'amount'             => 500.00,
            'msisdn'             => '254712345678',
            'bill_ref_number'    => 'TST-P00001',
            'business_shortcode' => '123456',
            'transaction_time'   => now(),
            'is_matched'         => false,
            'status'             => 'pending',
            'retry_count'        => 0,
            'raw_payload'        => ['raw' => 'data'],
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // markAsMatched()
    // -------------------------------------------------------------------------

    public function test_mark_as_matched_updates_fields_correctly(): void
    {
        $txn    = $this->makeTransaction();
        $userId = Str::uuid()->toString();

        $txn->markAsMatched($userId, 'account_number');
        $txn->refresh();

        $this->assertTrue($txn->is_matched);
        $this->assertEquals($userId, $txn->pppoe_user_id);
        $this->assertEquals('account_number', $txn->match_method);
        $this->assertEquals('processing', $txn->status);
        $this->assertNotNull($txn->matched_at);
    }

    // -------------------------------------------------------------------------
    // markAsCompleted()
    // -------------------------------------------------------------------------

    public function test_mark_as_completed_sets_payment_id_and_status(): void
    {
        $txn       = $this->makeTransaction(['status' => 'processing']);
        $paymentId = Str::uuid()->toString();

        $txn->markAsCompleted($paymentId);
        $txn->refresh();

        $this->assertEquals('completed', $txn->status);
        $this->assertEquals($paymentId, $txn->pppoe_payment_id);
    }

    // -------------------------------------------------------------------------
    // markAsFailed()
    // -------------------------------------------------------------------------

    public function test_mark_as_failed_sets_status_and_increments_retry(): void
    {
        $txn = $this->makeTransaction(['retry_count' => 0]);

        $txn->markAsFailed('Account not found');
        $txn->refresh();

        $this->assertEquals('failed', $txn->status);
        $this->assertEquals('Account not found', $txn->failure_reason);
        $this->assertEquals(1, $txn->retry_count);
        $this->assertNotNull($txn->last_retry_at);
    }

    public function test_mark_as_failed_accumulates_retry_count(): void
    {
        $txn = $this->makeTransaction(['retry_count' => 2]);

        $txn->markAsFailed('Retry again');
        $txn->refresh();

        $this->assertEquals(3, $txn->retry_count);
    }

    // -------------------------------------------------------------------------
    // canRetry()
    // -------------------------------------------------------------------------

    public function test_can_retry_returns_true_when_failed_and_retries_below_3(): void
    {
        $txn = $this->makeTransaction(['status' => 'failed', 'retry_count' => 2]);
        $this->assertTrue($txn->canRetry());
    }

    public function test_can_retry_returns_false_when_retry_count_equals_3(): void
    {
        $txn = $this->makeTransaction(['status' => 'failed', 'retry_count' => 3]);
        $this->assertFalse($txn->canRetry());
    }

    public function test_can_retry_returns_false_when_status_is_not_failed(): void
    {
        $txn = $this->makeTransaction(['status' => 'completed', 'retry_count' => 0]);
        $this->assertFalse($txn->canRetry());
    }

    // -------------------------------------------------------------------------
    // scopeUnmatched()
    // -------------------------------------------------------------------------

    public function test_scope_unmatched_excludes_matched_transactions(): void
    {
        $unmatched = $this->makeTransaction(['is_matched' => false, 'status' => 'pending']);
        $matched   = $this->makeTransaction([
            'is_matched'     => true,
            'status'         => 'completed',
            'transaction_id' => 'TXN' . Str::upper(Str::random(8)),
        ]);

        $ids = MpesaTransaction::unmatched()->pluck('id');

        $this->assertContains($unmatched->id, $ids);
        $this->assertNotContains($matched->id, $ids);
    }

    public function test_scope_unmatched_includes_failed_retryable_transactions(): void
    {
        $failed = $this->makeTransaction([
            'is_matched'     => false,
            'status'         => 'failed',
            'transaction_id' => 'TXN' . Str::upper(Str::random(8)),
        ]);

        $ids = MpesaTransaction::unmatched()->pluck('id');
        $this->assertContains($failed->id, $ids);
    }

    // -------------------------------------------------------------------------
    // scopeByShortcode()
    // -------------------------------------------------------------------------

    public function test_scope_by_shortcode_filters_correctly(): void
    {
        $matching = $this->makeTransaction(['business_shortcode' => '111111']);
        $other    = $this->makeTransaction([
            'business_shortcode' => '999999',
            'transaction_id'     => 'TXN' . Str::upper(Str::random(8)),
        ]);

        $ids = MpesaTransaction::byShortcode('111111')->pluck('id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    // -------------------------------------------------------------------------
    // scopeRecent()
    // -------------------------------------------------------------------------

    public function test_scope_recent_excludes_old_transactions(): void
    {
        $recent = $this->makeTransaction(['transaction_time' => now()->subHours(1)]);
        $old    = $this->makeTransaction([
            'transaction_time' => now()->subHours(48),
            'transaction_id'   => 'TXN' . Str::upper(Str::random(8)),
        ]);

        $ids = MpesaTransaction::recent(24)->pluck('id');

        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }
}
