<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class OrderFinanceCounterpartySummarySmokeTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;
    private const CENTRAL_HOST = 'prodelya_core.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_order_finance_screen_keeps_customer_receivable_and_shows_safe_counterparty_note(): void
    {
        $order = $this->createOrder('SP-FIN-COUNTER-001');
        $order->forceFill([
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
        ])->save();

        app(OrderCurrentAccountDebitSyncService::class)->syncOrder($order->fresh(['customer.companyRoles', 'payments']), $this->financeUser);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order))
            ->assertOk()
            ->assertSee('Sipariş Müşteri Borcu')
            ->assertSee('Oluşturuldu')
            ->assertSee('Karşı Borçlar')
            ->assertSee('Tedarikçi, fason ve kargo karşı borçları mevcut cari ekstrelerinde izlenir');

        $this->actingAs($this->limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order))
            ->assertForbidden();

        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => OrderCurrentAccountDebitSyncService::SOURCE_TYPE,
            'source_id' => $order->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
        ]);
    }
}
