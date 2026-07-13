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
use App\Models\User;
use App\Services\OrderListSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListSummaryServiceRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private Company $customer;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_not_required_graphic_status_does_not_count_as_graphic_waiting(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-SUM-1001',
        ], [
            'has_print' => false,
            'graphic_status' => 'gerekli_degil',
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
        ]);

        $row = app(OrderListSummaryService::class)->buildRow($order->fresh([
            'customer',
            'sourceQuote',
            'workForms',
            'procurements',
            'printProductions',
            'deliveries',
            'payments',
        ]), true);

        $this->assertSame('Tedarik Bekliyor', $row['operation_status_label']);
        $this->assertSame('Tedarik bekliyor', $row['next_action_label']);
        $this->assertSame('procurement_pending', $row['workflow_focus_key']);
    }

    public function test_printed_graphic_waiting_item_still_counts_as_graphic_waiting_when_no_higher_priority_blocker_exists(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-SUM-1002',
        ], [
            'graphic_status' => 'bekliyor',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
            'skip_delivery' => true,
        ]);

        $row = app(OrderListSummaryService::class)->buildRow($order->fresh([
            'customer',
            'sourceQuote',
            'workForms',
            'procurements',
            'printProductions',
            'deliveries',
            'payments',
        ]), true);

        $this->assertSame('Grafik Bekliyor', $row['operation_status_label']);
        $this->assertSame('Grafik kontrol et', $row['next_action_label']);
        $this->assertSame('graphic_pending', $row['workflow_focus_key']);
    }

    public function test_graphic_waiting_now_takes_priority_over_procurement_when_both_are_open(): void
    {
        $order = $this->createOrder([
            'document_number' => 'SP-SUM-1003',
        ], [
            'graphic_status' => 'bekliyor',
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
        ]);

        $row = app(OrderListSummaryService::class)->buildRow($order->fresh([
            'customer',
            'sourceQuote',
            'workForms',
            'procurements',
            'printProductions',
            'deliveries',
            'payments',
        ]), true);

        $this->assertSame('Grafik Bekliyor', $row['operation_status_label']);
        $this->assertSame('Grafik kontrol et', $row['next_action_label']);
        $this->assertSame('graphic_pending', $row['workflow_focus_key']);
    }

    public function test_completed_and_payment_pending_filters_stay_consistent_with_permission_rules(): void
    {
        $completedOrder = $this->createOrder([
            'document_number' => 'SP-SUM-2001',
        ], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
            'payment_mode' => 'pending',
        ]);

        $rows = app(OrderListSummaryService::class)->buildRows(
            Order::query()->with(['customer', 'sourceQuote', 'workForms', 'procurements', 'printProductions', 'deliveries', 'payments'])
                ->whereKey($completedOrder->id)
                ->get(),
            true
        );

        $completedRows = app(OrderListSummaryService::class)->filterRows($rows, 'completed', true);
        $this->assertCount(1, $completedRows);
        $this->assertSame($completedOrder->id, $completedRows->first()['order']->id);

        $paymentPendingRowsForFinance = app(OrderListSummaryService::class)->filterRows($rows, 'payment_pending', true);
        $this->assertCount(1, $paymentPendingRowsForFinance);

        $paymentPendingRowsForOperations = app(OrderListSummaryService::class)->filterRows($rows, 'payment_pending', false);
        $this->assertCount(0, $paymentPendingRowsForOperations);
    }

    private function createOrder(array $overrides = [], array $workflow = []): Order
    {
        $order = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-SUM-0001',
            'source_quote_number' => 'TK-SUM-0001',
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-06-15',
            'valid_until' => '2026-06-25',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'product_total' => 6000,
            'print_total' => ($workflow['has_print'] ?? true) ? 500 : 0,
            'subtotal' => 6500,
            'vat_total' => 1300,
            'grand_total' => 7800,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $hasPrint = $workflow['has_print'] ?? true;

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Summary Servis Ürünü',
            'product_code' => 'SUM-ITEM-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Summary servis test kalemi',
            'product_snapshot' => ['product_name' => 'Summary Servis Ürünü'],
            'list_price' => 65,
            'discount_rate' => 0,
            'unit_price' => 60,
            'line_total' => 6000,
            'has_print' => $hasPrint,
            'print_total' => $hasPrint ? 500 : 0,
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
            'public_tracking_token' => 'sum-' . $order->id . '-token',
            'graphic_snapshot' => ['status' => $workflow['graphic_status'] ?? ($hasPrint ? 'bekliyor' : 'gerekli_degil')],
            'production_snapshot' => ['status' => $hasPrint ? 'bekliyor' : 'gerekli_degil'],
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

        if ($hasPrint) {
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
        }

        if (!(bool) ($workflow['skip_delivery'] ?? false)) {
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
        }

        if (($workflow['payment_mode'] ?? null) === 'pending') {
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
}
