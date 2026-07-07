<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCustomerSearchDropdownLayoutTest extends TestCase
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

    public function test_create_screen_renders_customer_search_dropdown_layout_and_preserves_quick_add_markup(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('id="quote-customer-search-dropdown"', false);
        $response->assertSee('position: absolute;', false);
        $response->assertSee('top: calc(100% + 4px);', false);
        $response->assertSee('max-height: 220px;', false);
        $response->assertSee('overflow-y: auto;', false);
        $response->assertSee('id="quote-customer-selected-card"', false);
        $response->assertSee('pd-customer-selected-grid', false);
        $response->assertSee('id="quote-customer-search-dropdown"', false);
        $response->assertSee('id="quote-customer-selected-card" class="pd-customer-selected-card hidden"', false);
        $response->assertDontSee('Müşteri seçimi tenant kapsamındadır.');
        $response->assertSee('id="quick-customer-modal"', false);
        $response->assertSee('Müşteri bulunamadı. Hızlı müşteri ekleyebilirsiniz.');
    }
}
