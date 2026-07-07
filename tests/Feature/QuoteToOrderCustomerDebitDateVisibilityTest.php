<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class QuoteToOrderCustomerDebitDateVisibilityTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_debit_uses_order_created_date_so_new_statement_visibility_is_not_pushed_back_by_old_quote_date(): void
    {
        $customer = $this->createCustomerCompany('Date Visibility Müşteri');
        $order = $this->createOrder($customer, 'SP-DATE-001', 18000, [
            'quote_date' => '2026-06-01',
        ]);

        $order->forceFill([
            'created_at' => now()->setDate(2026, 7, 3)->setTime(14, 30),
        ])->saveQuietly();

        $transaction = $this->syncOrderDebit($order->fresh(['customer.companyRoles', 'payments']));

        $this->assertSame('2026-07-03', $transaction?->transaction_date?->toDateString());
        $this->assertNotSame($order->quote_date?->toDateString(), $transaction?->transaction_date?->toDateString());

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $customer->id . '?tab=ekstre'))
            ->assertOk()
            ->assertSee('Siparişten oluşan müşteri borcu')
            ->assertSee('03.07.2026');
    }
}
