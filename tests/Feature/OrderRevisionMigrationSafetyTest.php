<?php

namespace Tests\Feature;

use App\Models\OrderRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionMigrationSafetyTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_revision_tables_have_expected_columns_and_nullable_user_fields(): void
    {
        $this->assertTrue(Schema::hasTable('order_revisions'));
        $this->assertTrue(Schema::hasTable('order_revision_changes'));
        $this->assertTrue(Schema::hasColumns('order_revisions', [
            'tenant_account_id',
            'order_id',
            'revision_quote_id',
            'requested_by_user_id',
            'applied_by_user_id',
            'rejected_by_user_id',
            'cancelled_by_user_id',
            'summary',
        ]));

        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRevisionDraft($sourceOrder);

        $revision = OrderRevision::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $sourceOrder->id,
            'revision_quote_id' => $draft->id,
            'revision_number' => 1,
            'status' => OrderRevision::STATUS_DRAFT,
            'requested_by_user_id' => null,
            'applied_by_user_id' => null,
            'rejected_by_user_id' => null,
            'cancelled_by_user_id' => null,
            'summary' => ['safe' => true],
        ]);

        $this->assertNotNull($revision->id);
        $this->assertNull($revision->requested_by_user_id);
        $this->assertNull($revision->applied_by_user_id);
    }
}
