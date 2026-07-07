<?php

namespace Tests\Feature;

use App\Services\OrderFinanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderFinanceSummaryCustomerReceivableTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_customer_receivable_amounts_and_statuses_are_computed_correctly(): void
    {
        $customer = $this->createCustomerCompany('Sipariş Finans Müşteri');
        $order = $this->createOrder($customer, 'SP-OFS-REC-001', 18000);

        $this->syncOrderDebit($order);

        $summary = app(OrderFinanceSummaryService::class)->summarize($order->fresh(['customer.companyRoles', 'payments']));
        $receivable = $summary['customer_receivable'];

        $this->assertSame('Tahsilat bekliyor', $receivable['status_label']);
        $this->assertSame(18000.0, $receivable['debit_amount']);
        $this->assertSame(0.0, $receivable['collected_amount']);
        $this->assertSame(18000.0, $receivable['remaining_amount']);

        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 5000);

        $summary = app(OrderFinanceSummaryService::class)->summarize($order->fresh(['customer.companyRoles', 'payments']));
        $receivable = $summary['customer_receivable'];

        $this->assertSame('Kısmi tahsil edildi', $receivable['status_label']);
        $this->assertSame(5000.0, $receivable['collected_amount']);
        $this->assertSame(13000.0, $receivable['remaining_amount']);

        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 13000);

        $summary = app(OrderFinanceSummaryService::class)->summarize($order->fresh(['customer.companyRoles', 'payments']));
        $receivable = $summary['customer_receivable'];

        $this->assertSame('Tahsil edildi', $receivable['status_label']);
        $this->assertSame(18000.0, $receivable['collected_amount']);
        $this->assertSame(0.0, $receivable['remaining_amount']);
    }
}
