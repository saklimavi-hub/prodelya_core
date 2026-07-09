<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionCompareTenantIsolationTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_foreign_tenant_revision_compare_is_blocked(): void
    {
        $foreignOrder = $this->createForeignTenantOrder();
        $foreignDraft = Order::query()->create([
            'tenant_account_id' => $foreignOrder->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-FOR-' . strtoupper(substr(uniqid(), -5)),
            'source_order_id' => $foreignOrder->id,
            'copy_type' => 'revision',
            'revision_number' => 1,
            'customer_company_id' => $foreignOrder->customer_company_id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $this->getAs($this->adminUser, $this->revisionCompareRoute($foreignDraft))
            ->assertForbidden();
    }
}
