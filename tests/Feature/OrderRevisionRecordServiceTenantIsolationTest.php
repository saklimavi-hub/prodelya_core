<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionRecordServiceTenantIsolationTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_rejects_cross_tenant_order_quote_pair(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $foreignCustomer = Company::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'legal_name' => 'Yabancı Revizyon Müşteri',
            'status' => 'active',
        ]);

        $foreignQuote = Order::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-FOR-' . strtoupper(substr(uniqid(), -4)),
            'source_order_id' => $sourceOrder->id,
            'copy_type' => Order::COPY_TYPE_REVISION,
            'revision_number' => 1,
            'customer_company_id' => $foreignCustomer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(OrderRevisionRecordService::class)->createOrUpdateFromComparison($sourceOrder, $foreignQuote, [], $this->adminUser);
    }
}
