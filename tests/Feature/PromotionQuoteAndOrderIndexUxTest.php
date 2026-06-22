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
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteAndOrderIndexUxTest extends TestCase
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

    public function test_promotion_quotes_index_shows_compact_sales_queue_and_safe_fields(): void
    {
        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'customer_quote_approval',
            'feature_key' => null,
            'is_enabled' => true,
        ]);

        $waitingQuote = $this->createQuote([
            'document_number' => 'TK-UX-1001',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_WAITING,
            'grand_total' => 3200,
        ]);

        $approvedQuote = $this->createQuote([
            'document_number' => 'TK-UX-1002',
            'status' => 'approved',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
            'grand_total' => 6400,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $response->assertOk();
        $response->assertSee('Promosyon Teklifleri');
        $response->assertSee('Hazırlanan Teklifler');
        $response->assertSee('Müşteri Onayı Bekleyen');
        $response->assertSee('Onaylananlar');
        $response->assertSee('Teklif Listesi');
        $response->assertSee($waitingQuote->document_number);
        $response->assertSee($approvedQuote->document_number);
        $response->assertSee('Onay Bekliyor');
        $response->assertSee('Onaylandı');
        $response->assertSee('data-testid="quote-' . $approvedQuote->id . '-action-convert"', false);
        $response->assertSee('3.200,00 TL');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('price_snapshot', false);
    }

    public function test_promotion_quotes_index_hides_totals_for_operations_user(): void
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-UX-1003',
            'grand_total' => 1800,
        ]);

        $graphicUser = $this->createUserWithRole('graphic');

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        if ($response->getStatusCode() === 403) {
            $response->assertForbidden();

            return;
        }

        $response->assertOk();
        $response->assertSee($quote->document_number);
        $response->assertDontSee('1.800,00 TL');
    }

    public function test_orders_index_shows_operation_statuses_and_keeps_create_flow_closed(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-UX-2001',
            'source_quote_number' => 'TK-UX-2001',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['selected_order_id' => $order->id]));

        $response->assertOk();
        $response->assertSee('Siparişler');
        $response->assertSee('Açık Sipariş');
        $response->assertSee('Grafik Bekleyen');
        $response->assertSee('Tedarik Bekleyen');
        $response->assertSee('Üretim Bekleyen / Bloklu');
        $response->assertSee('Teslimat Bekleyen');
        $response->assertSee('Sipariş Listesi');
        $response->assertSee('SP-UX-2001');
        $response->assertSee('Grafik:');
        $response->assertSee('Tedarik:');
        $response->assertSee('Üretim:');
        $response->assertSee('Teslimat:');
        $response->assertSee('Sıradaki İş');
        $response->assertSee('İş Formu');
        $response->assertSee('Grafik');
        $response->assertSee('Tedarik');
        $response->assertSee('Üretim');
        $response->assertSee('Teslimat');
        $response->assertDontSee('Yeni Sipariş');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('price_snapshot', false);
    }

    public function test_orders_index_hides_finance_for_operations_user(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-UX-2002',
            'source_quote_number' => 'TK-UX-2002',
        ], 'partial');

        $graphicUser = $this->createUserWithRole('graphic');

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['selected_order_id' => $order->id]));

        $response->assertOk();
        $response->assertSee($order->document_number);
        $response->assertDontSee(route('admin.finance.show', $order), false);
        $response->assertDontSee('Genel Toplam:');
        $response->assertDontSee('Bakiye:');
    }

    private function createQuote(array $overrides = []): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-'.fake()->unique()->numerify('UX####'),
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
            'product_name' => 'UX Teklif Ürünü',
            'product_code' => 'UX-TK-001',
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

    private function createOrder(array $overrides = [], string $paymentMode = 'pending'): Order
    {
        $order = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-'.fake()->unique()->numerify('UX####'),
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
            'product_name' => 'UX Sipariş Ürünü',
            'product_code' => 'UX-SP-001',
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
            'work_form_number' => 'WF-'.fake()->unique()->numerify('####'),
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

        OrderItemWorkFormDelivery::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
            'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
            'planned_quantity' => 100,
            'ordered_quantity' => 100,
            'remaining_quantity' => 100,
            'financial_warning' => OrderItemWorkFormDelivery::WARNING_NONE,
            'created_by' => $this->adminUser->id,
        ]);

        if ($paymentMode === 'partial') {
            OrderPayment::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'customer_company_id' => $this->customer->id,
                'payment_type' => 'tahsilat',
                'payment_method' => 'havale',
                'currency' => 'TL',
                'amount' => 4560,
                'paid_at' => now(),
                'created_by' => $this->adminUser->id,
            ]);
        }

        return $order;
    }

    private function createUserWithRole(string $roleKey): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user = User::factory()->create([
            'password' => 'password',
        ]);

        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }
}
