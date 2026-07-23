<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\User;
use App\Models\TenantAccount;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionShowTabQueryParamTest extends TestCase
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

    public function test_production_show_with_ic_uretim_tab(): void
    {
        $production = $this->createProductionRecord(OrderItemPrintProduction::TYPE_INTERNAL);

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}?tab=ic-uretim");

        $response->assertRedirect(route('admin.productions.operator', $production));
    }

    public function test_production_show_with_dis_uretim_tab(): void
    {
        $production = $this->createProductionRecord(OrderItemPrintProduction::TYPE_OUTSOURCED);

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}?tab=dis-uretim");

        $response->assertRedirect(route('admin.productions.subcontract-assignment', $production));
    }

    public function test_production_show_with_islemler_tab(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}?tab=islemler");

        $response->assertRedirect(route('admin.productions.operator', $production));
    }

    public function test_production_show_with_fotograflar_tab(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}?tab=fotograflar");

        $response->assertStatus(200);
        $response->assertSee('Fotoğraflar');
        $response->assertSee('pd-production-detail__collapse', false);
    }

    public function test_production_show_with_gecmis_tab(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}?tab=gecmis");

        $response->assertStatus(200);
        $response->assertSee('Geçmiş');
        $response->assertSee('pd-production-detail__collapse', false);
    }

    public function test_production_show_with_invalid_tab_falls_to_genel(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}?tab=invalid-tab");

        $response->assertStatus(200);
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('pd-production-detail__collapse', false);
    }

    public function test_production_show_with_empty_tab_falls_to_genel(): void
    {
        $production = $this->createProductionRecord();

        $response = $this->actingAs($this->adminUser)->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get("/admin/productions/{$production->id}?tab=");

        $response->assertStatus(200);
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('pd-production-detail__collapse', false);
    }

    public function test_production_show_without_query_param_uses_type_based_default_tab(): void
    {
        $internalProduction = $this->createProductionRecord(OrderItemPrintProduction::TYPE_INTERNAL);
        $externalProduction = $this->createProductionRecord(OrderItemPrintProduction::TYPE_OUTSOURCED);

        $internalResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/productions/{$internalProduction->id}");

        $internalResponse->assertStatus(200);
        $internalResponse->assertSee('Üretim Detayı · Exact Baskı');
        $internalResponse->assertSee('Sıradaki İşlem');

        $externalResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/productions/{$externalProduction->id}");

        $externalResponse->assertStatus(200);
        $externalResponse->assertSee('Üretim Detayı · Exact Baskı');
        $externalResponse->assertSee('Dış Üretim / Fason');
        $externalResponse->assertSee('Sıradaki İşlem');
    }

    private function createProductionRecord(string $productionType = OrderItemPrintProduction::TYPE_INTERNAL): OrderItemPrintProduction
    {
        $order = $this->createOrder('SP-UIQ-' . random_int(1000, 9999));
        $item = $this->createOrderItem($order);
        $print = $this->createPrint($order, $item, [
            'production_type' => $productionType,
        ]);
        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();

        $production = $print->fresh(['production'])->production;
        $this->assertNotNull($production);

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
