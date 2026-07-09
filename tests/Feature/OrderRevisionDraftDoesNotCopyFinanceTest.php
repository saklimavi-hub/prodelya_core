<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class OrderRevisionDraftDoesNotCopyFinanceTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_revision_draft_does_not_copy_finance_records(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRevisionDraft($sourceOrder);

        $this->assertGreaterThan(0, $sourceOrder->payments()->count());
        $this->assertSame(0, $draft->payments()->count());
        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $sourceOrder->tenant_account_id,
            'source_id' => $sourceOrder->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
        ]);
        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $draft->tenant_account_id,
            'source_id' => $draft->id,
        ]);
    }
}
