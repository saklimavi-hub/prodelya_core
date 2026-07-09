<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderRevisionRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionRecordServiceRejectsNonRevisionQuoteTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_service_rejects_repeat_order_or_normal_quote(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $repeatDraft = $this->createRepeatDraft($sourceOrder);

        $normalQuote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-NORMAL-' . strtoupper(substr(uniqid(), -4)),
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $service = app(OrderRevisionRecordService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->createOrUpdateFromComparison($sourceOrder, $repeatDraft, [], $this->adminUser);
    }

    public function test_service_rejects_normal_quote(): void
    {
        $sourceOrder = $this->createSourceOrder();

        $normalQuote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-NORMAL-' . strtoupper(substr(uniqid(), -4)),
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $service = app(OrderRevisionRecordService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->createOrUpdateFromComparison($sourceOrder, $normalQuote, [], $this->adminUser);
    }
}
