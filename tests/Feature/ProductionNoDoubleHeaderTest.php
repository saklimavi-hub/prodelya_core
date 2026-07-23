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

class ProductionNoDoubleHeaderTest extends TestCase
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

    public function test_page_has_single_main_section_header_per_tab(): void
    {
        $production = $this->createProductionRecord();

        $base = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=genel');
        $base->assertOk();

        $baseContent = $base->getContent();
        $this->assertSame(
            1,
            substr_count($baseContent, 'pd-ui-v1-production-detail'),
            'Kompakt üretim detay yüzeyi tekil görünmeli.'
        );
        $this->assertSame(
            1,
            substr_count($baseContent, 'Üretim Detayı · Exact Baskı'),
            'Üretim detay başlığı tekil görünmeli.'
        );
        $this->assertSame(0, substr_count($baseContent, 'Canonical Akış'));

        $legacyActions = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=islemler');
        $legacyActions->assertRedirect(route('admin.productions.operator', $production));

        $actions = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));
        $actions->assertOk();

        $actionsContent = $actions->getContent();
        $this->assertSame(
            1,
            substr_count($actionsContent, 'pd-ui-v1-internal-operator'),
            'Canonical operatör ekranı tekil görünmeli.'
        );
        $this->assertSame(0, substr_count($baseContent, 'pd-ui-v1-internal-operator'));
    }

    private function createProductionRecord(): OrderItemPrintProduction
    {
        $order = $this->createOrder('SP-NOHEAD-' . random_int(1000, 9999));
        $item = $this->createOrderItem($order);
        $print = $this->createPrint($order, $item);
        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();
        $production = $print->fresh('production')->production;
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
            'product_name' => 'Başlıksız Üretim',
            'product_code' => 'PRD-' . random_int(1000, 9999),
            'quantity' => 30,
            'unit' => 'Adet',
            'description' => 'Başlık kontrolü için',
            'has_print' => true,
            'print_total' => 30,
            'status' => 'pending',
        ]);
    }

    private function createPrint(Order $order, OrderItem $item): OrderItemPrint
    {
        return OrderItemPrint::query()->create([
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
            'print_total' => 150,
            'note' => 'Başlık kontrolü',
            'production_note' => null,
            'status' => 'draft',
        ]);
    }
}
