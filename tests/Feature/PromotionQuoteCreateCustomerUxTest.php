<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCreateCustomerUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_create_screen_contains_customer_search_and_quick_create_modal_markup(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('Müşteri Ara');
        $response->assertSee('+ Hızlı Müşteri Ekle');
        $response->assertSee('id="quote-customer-search"', false);
        $response->assertSee('id="quote-customer-selected-card"', false);
        $response->assertSee('data-action="reset-selected-customer"', false);
        $response->assertSee('Cari durumu');
        $response->assertSee('id="quick-customer-modal"', false);
        $response->assertSee('Tekliften çıkmadan cari/firma kaydı oluşturun. Kaydedince müşteri otomatik seçilir.');
        $response->assertSee('customerSearchUrl', false);
        $response->assertSee('quickCustomerStoreUrl', false);
        $response->assertSee('customerLookup', false);
        $response->assertSee('selectedCustomer', false);
        $response->assertSee('pd-customer-select-hidden', false);
    }
}
