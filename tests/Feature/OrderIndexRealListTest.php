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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderIndexRealListTest extends TestCase
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

    public function test_orders_index_lists_real_orders_only_and_hides_demo_placeholder_copy(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-REAL-1001',
            'source_quote_number' => 'TK-REAL-1001',
            'valid_until' => '2026-06-30',
        ], [
            'graphic_status' => 'bekliyor',
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
        ]);

        Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-HIDDEN-9001',
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'product_total' => 1000,
            'print_total' => 200,
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $response->assertOk();
        $response->assertSee('Siparişler');
        $response->assertSee($order->document_number);
        $response->assertSee($this->customer->legal_name);
        $response->assertSee('TK-REAL-1001');
        $response->assertSee('Açık Sipariş');
        $response->assertSee('Grafik Bekliyor');
        $response->assertSee('Tedarik: Talep Hazırlanacak');
        $response->assertSee('Siparişi Aç');
        $response->assertSee('İş Formu');
        $response->assertSee('Grafik');
        $response->assertSee('Tedarik');
        $response->assertSee('Üretim');
        $response->assertSee('Teslimat');
        $response->assertSee('Finans');
        $response->assertSee('Genel Toplam: 7.800,00 TL');
        $response->assertSee('Bakiye: 7.800,00 TL');
        $response->assertSeeInOrder([
            'Sipariş No',
            'Müşteri',
            'Kaynak Teklif',
            'Sipariş Tarihi',
            'Teslim Tarihi',
            'Genel Durum',
            'Operasyon Durumu',
            'Ödeme Durumu',
            'Sıradaki İş',
        ]);
        $response->assertDontSee('TK-HIDDEN-9001');
        $response->assertDontSee('Promosyon ve baskı siparişlerini yönetin.');
        $response->assertDontSee('Yeni Sipariş');
        $response->assertDontSee('Düzenle');
        $response->assertDontSee('SP-2024-0001');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('price_snapshot', false);
    }

    public function test_quote_conversion_result_order_is_listed_but_source_quote_is_not(): void
    {
        $quote = $this->createQuoteViaHttp('TK-CONVERT-2601');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->orders()
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $response->assertOk();
        $response->assertSee($order->document_number);
        $response->assertSee($quote->document_number);
        $response->assertDontSee('document_type=quote', false);
    }

    public function test_status_filters_render_expected_order_sets(): void
    {
        $completedOrder = $this->createOrder([
            'document_number' => 'SP-FLT-2001',
            'source_quote_number' => 'TK-FLT-2001',
        ], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
        ]);

        $openOrder = $this->createOrder([
            'document_number' => 'SP-FLT-2002',
            'source_quote_number' => 'TK-FLT-2002',
            'valid_until' => null,
        ], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
        ]);

        $operationOrder = $this->createOrder([
            'document_number' => 'SP-FLT-2003',
            'source_quote_number' => 'TK-FLT-2003',
        ], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
        ]);

        $paymentPendingOrder = $this->createOrder([
            'document_number' => 'SP-FLT-2004',
            'source_quote_number' => 'TK-FLT-2004',
        ], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
            'payment_mode' => 'pending',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['status' => 'completed']))
            ->assertSee($completedOrder->document_number)
            ->assertSee($paymentPendingOrder->document_number)
            ->assertDontSee($openOrder->document_number);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['status' => 'open']))
            ->assertSee($openOrder->document_number)
            ->assertSee($operationOrder->document_number)
            ->assertDontSee($completedOrder->document_number);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['status' => 'in_operation']))
            ->assertSee($operationOrder->document_number)
            ->assertDontSee($completedOrder->document_number);

        $deliveryPendingResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['status' => 'delivery_pending']));

        $deliveryPendingResponse->assertSee($openOrder->document_number);
        $deliveryPendingResponse->assertDontSee($operationOrder->document_number);
        $deliveryPendingResponse->assertDontSee($completedOrder->document_number);
        $deliveryPendingResponse->assertSee('data-testid="order-' . $openOrder->id . '-delivery-date">-<', false);

        $paymentPendingResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['status' => 'payment_pending']));

        $paymentPendingResponse->assertSee($paymentPendingOrder->document_number);
        $paymentPendingResponse->assertSee('Ödeme Bekleyen');
    }

    public function test_finance_visibility_is_permission_based_on_orders_index(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-FIN-3001',
            'source_quote_number' => 'TK-FIN-3001',
        ], [
            'payment_mode' => 'partial',
        ]);

        $graphicUser = $this->createUserWithRole('graphic');
        $salesUser = $this->createUserWithRole('sales');

        $graphicResponse = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $graphicResponse->assertOk();
        $graphicResponse->assertSee($order->document_number);
        $graphicResponse->assertDontSee('value="payment_pending"', false);
        $graphicResponse->assertDontSee('data-testid="order-' . $order->id . '-payment"', false);
        $graphicResponse->assertDontSee(route('admin.finance.show', $order), false);

        $graphicContent = $graphicResponse->getContent();
        preg_match('/data-testid="order-row-' . $order->id . '".*data-order-panel=\'([^\']+)\'/s', $graphicContent, $graphicPanelMatch);
        $graphicPanel = html_entity_decode($graphicPanelMatch[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringNotContainsString('"finance"', $graphicPanel);
        $this->assertStringNotContainsString('grand_total_label', $graphicPanel);
        $this->assertStringNotContainsString('balance_due_label', $graphicPanel);

        $salesResponse = $this->actingAs($salesUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $salesResponse->assertOk();
        $salesResponse->assertSee('Ödeme Durumu');
        $salesResponse->assertSee('Ödeme Bekleyen');
        $salesResponse->assertSee('Genel Toplam: 7.800,00 TL');
        $salesResponse->assertSee('Bakiye: 4.800,00 TL');
        $salesResponse->assertSee('Kısmi Ödeme');
        $salesResponse->assertSee(route('admin.finance.show', $order), false);
    }

    public function test_orders_index_renders_selected_order_sticky_panel_with_safe_module_links(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-STICKY-4001',
            'source_quote_number' => 'TK-STICKY-4001',
            'valid_until' => '2026-07-03',
        ], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_SUPPLIER_ORDERED,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['selected_order_id' => $order->id]));

        $response->assertOk();
        $response->assertSee('data-testid="orders-sticky-panel"', false);
        $response->assertSee('Seçili Sipariş');
        $response->assertSee($order->document_number);
        $response->assertSee($this->customer->legal_name);
        $response->assertSee('TK-STICKY-4001');
        $response->assertSee('Sıradaki İş');
        $response->assertSee('Siparişi Aç');
        $response->assertSee('İş Formu');
        $response->assertSee('Grafik');
        $response->assertSee('Tedarik');
        $response->assertSee('Üretim');
        $response->assertSee('Teslimat');
        $response->assertSee('Finans');
        $response->assertSee('Hızlı Geçişler');
        $response->assertSee('Süreç Durumu');
        $response->assertSee('Finans Özeti');
        $response->assertDontSee('Kısmi Geldi');
        $response->assertDontSee('Tamamı Geldi');
    }

    public function test_orders_index_sticky_panel_hides_finance_data_for_operations_users(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-STICKY-4002',
            'source_quote_number' => 'TK-STICKY-4002',
        ], [
            'payment_mode' => 'partial',
        ]);

        $graphicUser = $this->createUserWithRole('graphic');

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['selected_order_id' => $order->id]));

        $response->assertOk();
        $response->assertSee('Seçili Sipariş');
        $response->assertDontSee(route('admin.finance.show', $order), false);

        $content = $response->getContent();
        $this->assertMatchesRegularExpression('/data-testid="order-row-' . $order->id . '".*data-order-panel=\'([^\']+)\'/s', $content);
        preg_match('/data-testid="order-row-' . $order->id . '".*data-order-panel=\'([^\']+)\'/s', $content, $rowPanelMatch);
        preg_match('/id="orderStickyPanel"[^>]*data-selected-order=\'([^\']*)\'/s', $content, $selectedPanelMatch);

        $rowPanel = html_entity_decode($rowPanelMatch[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $selectedPanel = html_entity_decode($selectedPanelMatch[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringNotContainsString('grand_total_label', $rowPanel);
        $this->assertStringNotContainsString('balance_due_label', $rowPanel);
        $this->assertStringNotContainsString('"finance"', $rowPanel);
        $this->assertStringNotContainsString('grand_total_label', $selectedPanel);
        $this->assertStringNotContainsString('balance_due_label', $selectedPanel);
        $this->assertStringNotContainsString('"finance"', $selectedPanel);
    }

    private function createQuoteViaHttp(string $documentNumber): Order
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-15',
                'valid_until' => '2026-06-22',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Order index conversion test',
                'document_number' => $documentNumber,
                'items' => [
                    [
                        'product_name' => 'Index Test Ürünü',
                        'product_code' => 'IDX-001',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '60',
                        'discount_rate' => '0',
                        'unit_price' => '60',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                        'has_print' => '1',
                        'prints' => [
                            [
                                'print_type' => 'UV Baskı',
                                'print_option' => 'Tek taraf',
                                'production_type' => 'İç üretim',
                                'print_quantity' => '100',
                                'print_unit_price' => '5',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect();

        return Order::query()->quotes()->latest('id')->firstOrFail();
    }

    private function createOrder(array $overrides = [], array $workflow = []): Order
    {
        $createdAt = Carbon::parse($overrides['created_at'] ?? '2026-06-15 10:00:00');

        $order = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-TEST-0001',
            'source_quote_number' => 'TK-TEST-0001',
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-06-15',
            'valid_until' => '2026-06-25',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'product_total' => 6000,
            'print_total' => 500,
            'subtotal' => 6500,
            'vat_total' => 1300,
            'grand_total' => 7800,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Index Liste Ürünü',
            'product_code' => 'IDX-LIST-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Sipariş index satırı',
            'product_snapshot' => [
                'product_name' => 'Index Liste Ürünü',
                'group_code' => 'HIDDEN-GROUP-CODE',
                'file_path' => '/secret/file-path',
                'physical_path' => '/secret/physical-path',
                'raw_supplier_payload' => ['hidden' => true],
            ],
            'price_snapshot' => [
                'grand_total' => 7800,
                'price_snapshot' => ['raw' => 'hidden'],
            ],
            'stock_snapshot' => ['stock_snapshot_raw' => 'hidden'],
            'list_price' => 65,
            'discount_rate' => 0,
            'unit_price' => 60,
            'line_total' => 6000,
            'has_print' => true,
            'print_total' => 500,
            'status' => 'pending',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source_quote_number' => $order->source_quote_number,
            'work_form_number' => 'IF-' . substr($order->document_number, 3),
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'wf-' . $order->id . '-token',
            'graphic_snapshot' => ['status' => $workflow['graphic_status'] ?? 'bekliyor'],
            'production_snapshot' => [],
            'delivery_snapshot' => [],
            'procurement_snapshot' => [],
            'created_by' => $this->adminUser->id,
        ]);

        OrderItemProcurement::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'requires_procurement' => true,
            'fulfillment_source' => OrderItemProcurement::FULFILLMENT_SUPPLIER,
            'procurement_status' => $workflow['procurement_status'] ?? OrderItemProcurement::STATUS_PENDING,
            'requested_quantity' => 100,
            'received_quantity' => ($workflow['procurement_status'] ?? null) === OrderItemProcurement::STATUS_FULLY_RECEIVED ? 100 : 0,
            'remaining_quantity' => ($workflow['procurement_status'] ?? null) === OrderItemProcurement::STATUS_FULLY_RECEIVED ? 0 : 100,
            'created_by' => $this->adminUser->id,
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
            'print_unit_price' => 5,
            'print_total' => 500,
            'status' => 'pending',
        ]);

        OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'work_form_id' => $workForm->id,
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => $workflow['production_status'] ?? OrderItemPrintProduction::STATUS_PENDING,
            'planned_quantity' => 100,
            'completed_quantity' => ($workflow['production_status'] ?? null) === OrderItemPrintProduction::STATUS_COMPLETED ? 100 : 0,
            'remaining_quantity' => ($workflow['production_status'] ?? null) === OrderItemPrintProduction::STATUS_COMPLETED ? 0 : 100,
            'qc_status' => OrderItemPrintProduction::QC_WAITING,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItemWorkFormDelivery::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'delivery_status' => $workflow['delivery_status'] ?? OrderItemWorkFormDelivery::STATUS_PENDING,
            'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
            'planned_quantity' => 100,
            'delivered_quantity' => ($workflow['delivery_status'] ?? null) === OrderItemWorkFormDelivery::STATUS_DELIVERED ? 100 : 0,
            'remaining_quantity' => ($workflow['delivery_status'] ?? null) === OrderItemWorkFormDelivery::STATUS_DELIVERED ? 0 : 100,
            'financial_warning' => OrderItemWorkFormDelivery::WARNING_NONE,
            'created_by' => $this->adminUser->id,
        ]);

        $paymentMode = $workflow['payment_mode'] ?? null;

        if ($paymentMode === 'partial') {
            OrderPayment::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'order_id' => $order->id,
                'customer_company_id' => $this->customer->id,
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => 3000,
                'currency' => 'TL',
                'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
                'paid_at' => '2026-06-15 12:00:00',
                'created_by' => $this->adminUser->id,
            ]);
        }

        if ($paymentMode === 'pending') {
            OrderPayment::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'order_id' => $order->id,
                'customer_company_id' => $this->customer->id,
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => 7800,
                'currency' => 'TL',
                'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
                'due_date' => '2026-06-16 12:00:00',
                'created_by' => $this->adminUser->id,
            ]);
        }

        return $order->fresh();
    }

    private function createUserWithRole(string $roleKey): User
    {
        $user = User::query()->create([
            'name' => 'Order Index ' . ucfirst($roleKey),
            'email' => 'order-index-' . $roleKey . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }
}
