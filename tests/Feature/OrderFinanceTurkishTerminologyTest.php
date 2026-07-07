<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderFinanceTurkishTerminologyTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_finance_screen_keeps_turkish_terminology_clean(): void
    {
        $customer = $this->createCustomerCompany('Terminoloji Müşteri');
        $order = $this->createOrder($customer, 'SP-OFS-TERM-001', 18000);
        $this->syncOrderDebit($order);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id));

        $response->assertOk()
            ->assertDontSee('Musteri')
            ->assertDontSee('Siparis')
            ->assertDontSee('Tedarikci')
            ->assertDontSee('Uretim')
            ->assertSee('Müşteri')
            ->assertSee('Sipariş')
            ->assertSee('Borç')
            ->assertSee('Ödeme')
            ->assertSee('İşlem')
            ->assertSee('Tahsilat')
            ->assertSee('Cari Ekstre');
    }
}
