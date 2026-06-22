<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteAndOrderIndexHeaderPanelTest extends TestCase
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

    public function test_orders_index_uses_single_header_and_real_selected_order_panel(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-HDR-3001',
            'source_quote_number' => 'TK-HDR-3001',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['selected_order_id' => $order->id]));

        $response->assertOk();
        $response->assertSee('Siparişler');
        $response->assertDontSee('Sipariş Takip Merkezi');
        $response->assertSee('Tüm Siparişler');
        $response->assertSee('Açık Siparişler');
        $response->assertSee('Seçili Sipariş');
        $response->assertSee($order->document_number);
        $response->assertSee($this->customer->legal_name);
        $response->assertSee('Sıradaki İş');
        $response->assertDontSee('Yeni Sipariş');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('price_snapshot', false);
    }

    public function test_promotion_quotes_index_uses_single_header_and_unified_actions(): void
    {
        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'customer_quote_approval',
            'feature_key' => null,
            'is_enabled' => true,
        ]);

        $quote = $this->createQuote([
            'document_number' => 'TK-HDR-1001',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_WAITING,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $response->assertOk();
        $response->assertSee('Promosyon Teklifleri');
        $response->assertDontSee('Satış Teklif Kontrolü');
        $response->assertSee('Yeni Promosyon Teklifi');
        $response->assertSee('Müşteri Onayı Bekleyenler');
        $response->assertSee('Siparişe Çevrilebilir');
        $response->assertSee($quote->document_number);
        $response->assertSee('Onay Bekliyor');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('price_snapshot', false);
    }

    private function createQuote(array $overrides = []): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-'.fake()->unique()->numerify('HDR####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'product_total' => 1000,
            'print_total' => 200,
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $item = OrderItem::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Header Teklif Ürünü',
            'product_code' => 'HDR-TK-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'line_total' => 1000,
            'unit_price' => 10,
            'has_print' => true,
            'print_total' => 200,
            'status' => 'pending',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
            'print_unit_price' => 2,
            'print_total' => 200,
            'status' => 'draft',
        ]);

        return $quote;
    }

    private function createOrder(array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-'.fake()->unique()->numerify('HDR####'),
            'source_quote_number' => 'TK-REF-001',
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'product_total' => 6500,
            'print_total' => 1300,
            'subtotal' => 7800,
            'vat_total' => 1560,
            'grand_total' => 9360,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $item = OrderItem::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Header Sipariş Ürünü',
            'product_code' => 'HDR-SP-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'line_total' => 6500,
            'unit_price' => 65,
            'has_print' => true,
            'print_total' => 1300,
            'status' => 'pending',
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
            'print_unit_price' => 13,
            'print_total' => 1300,
            'status' => 'pending',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source_quote_number' => $order->source_quote_number,
            'work_form_number' => 'WF-HDR-'.fake()->unique()->numerify('###'),
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => fake()->uuid(),
            'graphic_snapshot' => ['status' => 'bekliyor'],
            'production_snapshot' => [],
            'delivery_snapshot' => [],
            'procurement_snapshot' => [],
            'created_by' => $this->adminUser->id,
        ]);

        OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'order_item_print_id' => $print->id,
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'planned_quantity' => 100,
            'remaining_quantity' => 100,
            'qc_status' => OrderItemPrintProduction::QC_WAITING,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItemProcurement::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'work_form_id' => $workForm->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'requires_procurement' => true,
            'fulfillment_source' => OrderItemProcurement::FULFILLMENT_SUPPLIER,
            'requested_quantity' => 100,
            'remaining_quantity' => 100,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItemWorkFormDelivery::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'order_item_work_form_id' => $workForm->id,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
            'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
            'planned_quantity' => 100,
            'ordered_quantity' => 100,
            'remaining_quantity' => 100,
            'financial_warning' => OrderItemWorkFormDelivery::WARNING_NONE,
            'created_by' => $this->adminUser->id,
        ]);

        OrderPayment::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'payment_method' => 'havale',
            'amount' => 0,
        ]);

        return $order;
    }
}
