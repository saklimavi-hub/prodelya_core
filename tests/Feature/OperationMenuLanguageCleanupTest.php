<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationMenuLanguageCleanupTest extends TestCase
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

    public function test_sidebar_uses_uretim_label_and_hides_baski_fason_label(): void
    {
        $order = $this->createConvertedOrder();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Operasyon');
        $response->assertSee('Grafik');
        $response->assertSee('Tedarik');
        $response->assertSee('Üretim');
        $response->assertSee('Teslimat');
        $response->assertSee('Finans');
        $response->assertDontSee('Baskı / Fason');
    }

    private function createConvertedOrder(): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Operation menu cleanup payload',
                'items' => [
                    [
                        'product_name' => 'Sidebar Test Ürün',
                        'product_code' => 'SIDEBAR-001',
                        'quantity' => '10',
                        'unit' => 'Adet',
                        'list_price' => '8.60',
                        'discount_rate' => '10',
                        'unit_price' => '7.74',
                        'manual_unit_price' => '0',
                        'vat_rate' => '20',
                        'has_print' => '0',
                    ],
                ],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        return Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();
    }
}
