<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionPoolRouteMethodGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_pool_uses_production_type_before_print_method_name_for_route_tabs(): void
    {
        $internalPad = $this->createProductionJob('SP-M13-INTERNAL-PAD', 'Tampon Baskı', OrderItemPrintProduction::TYPE_INTERNAL, [
            'product_code' => 'M13-PAD-IN',
        ]);
        $outsourcedUv = $this->createProductionJob('SP-M13-OUT-UV', 'UV Baskı', OrderItemPrintProduction::TYPE_OUTSOURCED, [
            'product_code' => 'M13-UV-OUT',
        ]);

        $internalResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['route' => 'internal']));

        $internalResponse->assertOk();
        $internalResponse->assertSee($internalPad->order->document_number);
        $internalResponse->assertSee('Tampon Baskı');
        $internalResponse->assertDontSee($outsourcedUv->order->document_number);

        $outsourcedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['route' => 'outsourced']));

        $outsourcedResponse->assertOk();
        $outsourcedResponse->assertSee($outsourcedUv->order->document_number);
        $outsourcedResponse->assertSee('UV Baskı');
        $outsourcedResponse->assertDontSee($internalPad->order->document_number);
    }

    public function test_completed_archive_excludes_active_and_uses_open_record_action(): void
    {
        $active = $this->createProductionJob('SP-M13-ACTIVE', 'Lazer Baskı', OrderItemPrintProduction::TYPE_INTERNAL);
        $completed = $this->createProductionJob('SP-M13-COMPLETED', 'Lazer Baskı', OrderItemPrintProduction::TYPE_INTERNAL, [
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'planned_quantity' => 25,
            'completed_quantity' => 25,
            'remaining_quantity' => 0,
            'completed_at' => now(),
        ]);

        $activeResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['route' => 'internal']));

        $activeResponse->assertOk();
        $activeResponse->assertSee($active->order->document_number);
        $activeResponse->assertDontSee($completed->order->document_number);

        $completedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['route' => 'completed']));

        $completedResponse->assertOk();
        $completedResponse->assertSee($completed->order->document_number);
        $completedResponse->assertSee('Kaydı Aç');
        $completedResponse->assertDontSee($active->order->document_number);
    }

    public function test_supplier_printed_tab_is_empty_safe_and_pagination_is_turkish(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->createProductionJob('SP-M13-PAGE-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'UV Baskı', OrderItemPrintProduction::TYPE_INTERNAL, [
                'product_code' => 'M13-PAGE-' . $i,
            ]);
        }

        $paginatedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['route' => 'internal', 'per_page' => 10]));

        $paginatedResponse->assertOk();
        $paginatedResponse->assertSee('Geri');
        $paginatedResponse->assertSee('İleri');
        $this->assertSame(10, substr_count($paginatedResponse->getContent(), 'class="pd-production-job-row"'));

        $supplierPrintedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['route' => 'supplier_printed']));

        $supplierPrintedResponse->assertOk();
        $supplierPrintedResponse->assertSee('Henüz bu üretim yolu için ayrı bir canonical sınıflandırma bulunmuyor.');
        $supplierPrintedResponse->assertDontSee('SP-M13-PAGE-01');
    }

    public function test_pool_keeps_one_primary_row_cta_and_does_not_leak_finance_or_paths(): void
    {
        $production = $this->createProductionJob('SP-M13-SECURE', 'Serigrafi', OrderItemPrintProduction::TYPE_OUTSOURCED, [
            'subcontractor_cost' => 875,
            'subcontractor_cost_currency' => 'TRY',
            'production_snapshot' => [
                'file_path' => 'storage/app/private/secret-production-file.png',
                'physical_path' => 'C:\\secret\\production.png',
            ],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['route' => 'outsourced']));

        $response->assertOk();
        $response->assertSee($production->order->document_number);
        $this->assertSame(1, substr_count($response->getContent(), 'pd-production-job-next'));
        $this->assertSame(2, substr_count($response->getContent(), 'pd-production-btn--primary'));
        $response->assertDontSee('subcontractor_cost', false);
        $response->assertDontSee('875');
        $response->assertDontSee('storage/app/private', false);
        $response->assertDontSee('physical_path', false);
    }

    public function test_ui_v1_styles_are_scoped_to_production_pool_wrapper(): void
    {
        $css = file_get_contents(public_path('css/prodelya-admin.css'));
        $this->assertStringContainsString('.pd-ui-v1-production .pd-production-job-row', $css);
        $this->assertStringNotContainsString(':root .pd-production', $css);
        $this->assertStringNotContainsString('body.pd-ui-v1-production', $css);
    }

    private function createProductionJob(string $documentNumber, string $printType, string $productionType, array $overrides = []): OrderItemPrintProduction
    {
        $order = Order::query()->create([
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

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_name' => data_get($overrides, 'product_name', 'M13 Üretim Ürünü'),
            'product_code' => data_get($overrides, 'product_code', 'M13-PRODUCT'),
            'quantity' => 100,
            'unit' => 'Adet',
            'has_print' => true,
            'status' => 'pending',
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => $printType,
            'print_option' => data_get($overrides, 'print_option', 'Tek taraf'),
            'print_quantity' => data_get($overrides, 'planned_quantity', 100),
            'production_type' => $productionType,
            'status' => 'pending',
        ]);

        return OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'production_type' => $productionType,
            'production_status' => data_get($overrides, 'production_status', OrderItemPrintProduction::STATUS_PENDING),
            'planned_quantity' => data_get($overrides, 'planned_quantity', 100),
            'completed_quantity' => data_get($overrides, 'completed_quantity', 0),
            'remaining_quantity' => data_get($overrides, 'remaining_quantity', data_get($overrides, 'planned_quantity', 100)),
            'qc_status' => OrderItemPrintProduction::QC_WAITING,
            'subcontractor_cost' => data_get($overrides, 'subcontractor_cost'),
            'subcontractor_cost_currency' => data_get($overrides, 'subcontractor_cost_currency'),
            'production_snapshot' => data_get($overrides, 'production_snapshot', []),
            'completed_at' => data_get($overrides, 'completed_at'),
            'created_by' => $this->adminUser->id,
        ])->fresh(['order.customer', 'orderItem', 'orderItemPrint']);
    }
}
