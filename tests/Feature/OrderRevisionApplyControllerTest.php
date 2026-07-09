<?php

namespace Tests\Feature;

use App\Models\OrderRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionApplyControllerTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_apply_route_processes_revision_and_redirects_to_source_order(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft(['with_finance' => false]);
        $this->mutateRevisionQuantity($draft, 150);

        $response = $this->postAs($this->adminUser, $this->revisionApplyRoute($draft));

        $response->assertRedirect(route('admin.orders.show', $sourceOrder));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('order_revisions', [
            'order_id' => $sourceOrder->id,
            'revision_quote_id' => $draft->id,
            'status' => OrderRevision::STATUS_PARTIALLY_APPLIED,
        ]);
    }

    public function test_apply_route_rejects_unauthorized_user(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $this->mutateRevisionQuantity($draft, 111);

        $response = $this->postAs($this->unauthorizedUser, $this->revisionApplyRoute($draft));

        $response->assertForbidden();
        $this->assertDatabaseMissing('order_revisions', [
            'order_id' => $sourceOrder->id,
            'revision_quote_id' => $draft->id,
            'status' => OrderRevision::STATUS_APPLIED,
        ]);
    }
}
