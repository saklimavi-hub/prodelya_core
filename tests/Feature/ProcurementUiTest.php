<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementUiTest extends TestCase
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

    public function test_procurement_index_renders_real_records_without_financial_or_technical_leaks(): void
    {
        $procurement = $this->createProcurementRecord([
            'product_name' => 'UI Tedarikçi Kalem',
            'product_code' => 'UI-SUP-001',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $response->assertOk();
        $response->assertSee('Tedarik Talepleri');
        $response->assertSee('Yeni Tedarik Talep Ailesi');
        $response->assertSee('Talep Hazırlanacak');
        $response->assertSee('Talebi Aç');
        $response->assertSee($procurement->order->document_number);
        $response->assertSee($procurement->workForm->work_form_number);
        $response->assertSee('UI Tedarikçi Kalem');
        $response->assertSee('UI-SUP-001');
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('KDV', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_procurement_show_renders_reference_family_next_action_and_work_form_link(): void
    {
        $procurement = $this->createProcurementRecord([
            'product_name' => 'Detay Ürünü',
            'product_code' => 'UI-DET-001',
            'quantity' => 75,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement));

        $response->assertOk();
        $response->assertSee('Tedarik Detayı');
        $response->assertSee('Üst Sıradaki İş');
        $response->assertSee('Üç Aşamalı Süreç');
        $response->assertSee('Sağ Kısa Özet');
        $response->assertSee('Detay Ürünü');
        $response->assertSee('UI-DET-001');
        $response->assertSee($procurement->order->document_number);
        $response->assertSee($procurement->workForm->work_form_number);
        $response->assertSee(route('admin.work-forms.show', $procurement->workForm), false);
        $response->assertSee('data-procurement-depth-marker="true"', false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_tenant_external_procurement_access_returns_403(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Second Tenant',
            'legal_name' => 'Second Tenant Ltd.',
            'slug' => 'second-tenant',
            'panel_subdomain' => 'second-tenant',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-OTHER-001',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Other Tenant Product',
            'product_code' => 'OTH-001',
            'quantity' => 10,
        ]);

        $procurement = OrderItemProcurement::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requires_procurement' => true,
            'fulfillment_source' => OrderItemProcurement::FULFILLMENT_SUPPLIER,
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'requested_quantity' => 10,
            'remaining_quantity' => 10,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement))
            ->assertForbidden();
    }

    public function test_procurement_status_actions_update_work_form_snapshot_version_and_logs_without_stock_movement(): void
    {
        $procurement = $this->createProcurementRecord([
            'product_name' => 'Aksiyon Ürünü',
            'product_code' => 'UI-ACT-001',
            'quantity' => 100,
        ]);

        $initialVersion = $procurement->workForm->version;
        $initialSnapshot = $procurement->orderItem->stock_snapshot;

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'request_created',
                'note' => 'Talep açıldı',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'supplier_ordered',
                'note' => 'Sipariş verildi',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'partially_received',
                'received_quantity' => '40',
                'note' => 'Kısmi teslim',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $procurement = $procurement->fresh(['workForm.activityLogs', 'orderItem']);
        $this->assertSame(OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame(40.0, (float) $procurement->received_quantity);
        $this->assertSame(60.0, (float) $procurement->remaining_quantity);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'fully_received',
                'note' => 'Tamamlandı',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $procurement = $procurement->fresh(['workForm.activityLogs', 'orderItem']);

        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame(100.0, (float) $procurement->received_quantity);
        $this->assertSame(0.0, (float) $procurement->remaining_quantity);
        $this->assertSame($initialVersion + 4, $procurement->workForm->version);
        $this->assertSame('Tedarik Tamamlandı', data_get($procurement->workForm->procurement_snapshot, 'procurement_status_label'));
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_request_created'));
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'supplier_ordered'));
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_partially_received'));
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_fully_received'));
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame($initialSnapshot, $procurement->orderItem->stock_snapshot);
    }

    public function test_procurement_partial_receive_validation_blocks_overflow(): void
    {
        $procurement = $this->createProcurementRecord([
            'product_name' => 'Validation Ürünü',
            'product_code' => 'UI-VAL-001',
            'quantity' => 15,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.show', $procurement))
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'partially_received',
                'received_quantity' => '20',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement))
            ->assertSessionHasErrors('received_quantity');

        $procurement = $procurement->fresh();
        $this->assertSame(0.0, (float) $procurement->received_quantity);
        $this->assertSame(15.0, (float) $procurement->remaining_quantity);
    }

    public function test_local_procurement_action_does_not_create_stock_movement_in_this_phase(): void
    {
        $procurement = $this->createProcurementRecord([
            'product_name' => 'Local UI Ürünü',
            'product_code' => 'UI-LOC-001',
            'product_source' => 'local_stock',
            'quantity' => 12,
            'stock_snapshot' => [
                'local_stock_quantity' => 9,
                'supplier_stock_quantity' => 0,
                'local_stock_priority' => true,
            ],
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'fully_received',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $procurement = $procurement->fresh();
        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame(0, StockMovement::query()->count());
    }

    private function createProcurementRecord(array $itemOverrides = []): OrderItemProcurement
    {
        $source = $this->createSupplierSource('UI-SUPPLIER-' . substr((string) microtime(true), -6));
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-UI-' . random_int(1000, 9999),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => 'UI Procurement Product',
            'product_code' => 'UI-PROC-001',
            'supplier_id' => null,
            'supplier_source_id' => $source->id,
            'quantity' => 20,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'UI Procurement Product',
                'product_code' => 'UI-PROC-001',
                'supplier_name' => $source->supplier->name,
                'warning_badges' => ['Stok kontrolü gerekli'],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 55,
                'line_total' => 1100,
                'vat_total' => 110,
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 33,
                'local_stock_quantity' => 0,
                'safe_stock_quantity' => 0,
                'snapshot_taken_at' => '2026-06-13T11:00:00+03:00',
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 70,
            'discount_rate' => 5,
            'unit_price' => 55,
            'line_total' => 1100,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ], $itemOverrides));

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order', 'procurement.order.customer', 'procurement.orderItem'])->procurement;
    }

    private function createSupplierSource(string $code): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);
    }
}
