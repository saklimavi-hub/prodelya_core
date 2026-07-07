<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCustomerSearchResultScrollTest extends TestCase
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

    public function test_customer_search_result_container_is_scrollable_and_quick_add_markup_stays_visible(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('id="quote-customer-search-results"', false);
        $response->assertSee('max-height: 220px;', false);
        $response->assertSee('overflow-y: auto;', false);
        $response->assertSee('Müşteri bulunamadı. Hızlı müşteri ekleyebilirsiniz.');
        $response->assertSee('id="quick-customer-modal"', false);
        $response->assertSee('name="customer_company_id"', false);
    }
}
