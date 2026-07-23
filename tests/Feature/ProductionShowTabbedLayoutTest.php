<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionShowTabbedLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;
    private const CENTRAL_HOST = 'prodelya_core.test';
    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_production_show_page_loads_with_tabbed_layout(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}");

        $response->assertStatus(200);
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('Fotoğraflar');
        $response->assertSee('Geçmiş');
        $response->assertSee('Sıradaki İşlem');
        $response->assertDontSee('?tab=ic-uretim', false);
        $response->assertDontSee('?tab=dis-uretim', false);
        $response->assertDontSee('?tab=islemler', false);
    }

    public function test_production_show_default_tab_is_internal_tab_for_internal_records(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}");

        $response->assertStatus(200);
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('pd-production-detail__collapse', false);
        $response->assertSee('Süreç durumu');
        $response->assertSee('Sıradaki İşlem');
    }

    public function test_production_show_no_duplicate_headers(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}");

        $response->assertStatus(200);
        $content = $response->getContent();
        $headerCount = substr_count($content, '<h2 class="prd-section-title">Genel Özet</h2>');
        $this->assertLessThanOrEqual(1, $headerCount, 'Üretim Detayı içinde Genel Özet başlığı yalnızca bir kez olmalı.');
    }

    public function test_production_show_tabs_are_clickable(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}");

        $response->assertStatus(200);
        $response->assertSee('Teknik / Kayıt Detayları');
        $response->assertSee('Fotoğraflar');
        $response->assertSee('Geçmiş');
        $response->assertDontSee('?tab=ic-uretim', false);
        $response->assertDontSee('?tab=dis-uretim', false);
        $response->assertDontSee('?tab=islemler', false);
    }

    private function createProductionRecord(string $productionType = OrderItemPrintProduction::TYPE_INTERNAL): OrderItemPrintProduction
    {
        $order = $this->createOrder('SP-UI-' . random_int(1000, 9999));
        $item = $this->createOrderItem($order);
        $print = $this->createPrint($order, $item, [
            'production_type' => $productionType,
        ]);
        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();

        $production = $print->fresh('production')->production;
        $this->assertNotNull($production, 'Production should be created for test print.');

        return $production->fresh();
    }

    private function createOrder(string $documentNumber): Order
    {
        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function createOrderItem(Order $order): OrderItem
    {
        return OrderItem::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Üretim Test Ürünü',
            'product_code' => 'PRD-' . random_int(1000, 9999),
            'quantity' => 80,
            'unit' => 'Adet',
            'description' => 'Production show test item',
            'price_snapshot' => ['unit_price' => 99.9],
            'has_print' => true,
            'print_total' => 80,
            'status' => 'pending',
        ]);
    }

    private function createPrint(Order $order, OrderItem $item, array $overrides = []): OrderItemPrint
    {
        return OrderItemPrint::query()->create(array_merge([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'subcontractor_company_id' => null,
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'cliche_status' => null,
            'print_quantity' => $item->quantity,
            'print_unit_price' => 5,
            'print_total' => 400,
            'note' => 'Test baskı',
            'production_note' => null,
            'status' => 'draft',
        ], $overrides));
    }
}
