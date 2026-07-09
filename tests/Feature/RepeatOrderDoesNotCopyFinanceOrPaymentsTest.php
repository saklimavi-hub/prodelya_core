<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RepeatOrderDoesNotCopyFinanceOrPaymentsTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_repeat_order_does_not_copy_finance_or_payment_records(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRepeatDraft($sourceOrder);

        $this->assertSame(0, $draft->payments()->count());
        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $draft->tenant_account_id,
            'source_id' => $draft->id,
        ]);
        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $sourceOrder->tenant_account_id,
            'source_id' => $sourceOrder->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
        ]);
    }
}
