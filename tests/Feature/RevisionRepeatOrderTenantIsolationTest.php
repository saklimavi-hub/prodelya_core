<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RevisionRepeatOrderTenantIsolationTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_other_tenant_order_cannot_be_revised_or_repeated(): void
    {
        $foreignOrder = $this->createForeignTenantOrder();

        $this->postAs($this->adminUser, route('admin.orders.revision-draft.store', $foreignOrder))
            ->assertForbidden();

        $this->postAs($this->adminUser, route('admin.orders.repeat-order-draft.store', $foreignOrder))
            ->assertForbidden();
    }
}
