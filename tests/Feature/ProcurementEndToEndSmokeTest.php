<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFolder;
use App\Models\OrderItemWorkForm;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcurementEndToEndSmokeTest extends TestCase
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

        Storage::fake('public');
        Storage::fake('local');

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_procurement_end_to_end_smoke_flow_is_safe_and_complete(): void
    {
        $supplierSource = $this->createSupplierSource('SMOKE-SUPPLIER');
        $order = $this->createConvertedOrderWithProcurementScenario($supplierSource);

        $order->load([
            'items.procurement.workForm.activityLogs',
            'items.workForm',
            'procurements',
        ]);

        $this->assertCount(2, $order->items);
        $this->assertCount(2, $order->procurements);

        $supplierItem = $order->items->firstWhere('product_code', 'PROC-SUP-001');
        $localItem = $order->items->firstWhere('product_code', 'PROC-LOC-001');

        $this->assertNotNull($supplierItem);
        $this->assertNotNull($localItem);
        $this->assertNotNull($supplierItem->procurement);
        $this->assertNotNull($localItem->procurement);
        $this->assertNotNull($supplierItem->workForm);
        $this->assertNotNull($localItem->workForm);

        $folders = OrderItemWorkFolder::query()
            ->whereIn('work_form_id', [$supplierItem->workForm->id, $localItem->workForm->id])
            ->where('folder_type', 'system')
            ->get();

        $this->assertCount(2, $folders);

        $supplierProcurement = $supplierItem->procurement->fresh(['workForm.activityLogs', 'orderItem']);
        $localProcurement = $localItem->procurement->fresh(['workForm.activityLogs', 'orderItem']);
        $initialSupplierVersion = $supplierProcurement->workForm->version;
        $initialSupplierStockSnapshot = $supplierItem->stock_snapshot;
        $initialLocalStockSnapshot = $localItem->stock_snapshot;

        $procurementIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $procurementIndex->assertOk();
        $procurementIndex->assertSee('Tedarik Yönetimi');
        $procurementIndex->assertSee($order->document_number);
        $procurementIndex->assertSee($supplierProcurement->workForm->work_form_number);
        $procurementIndex->assertSee($localProcurement->workForm->work_form_number);
        $procurementIndex->assertSee('PROC-SUP-001');
        $procurementIndex->assertSee('PROC-LOC-001');
        $procurementIndex->assertSee('Tedarikçi');
        $procurementIndex->assertSee('Local Stok');
        $procurementIndex->assertSee(route('admin.procurements.index'), false);
        $this->assertSafeOutput($procurementIndex->getContent());

        $procurementShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', [
                $supplierProcurement,
                'tab' => 'islemler',
            ]));

        $procurementShow->assertOk();
        $procurementShow->assertSee('Tedarik Detayı');
        $procurementShow->assertSee('PROC-SUP-001');
        $procurementShow->assertSee('Talep Aç');
        $procurementShow->assertSee('Kısmi Geldi');
        $procurementShow->assertSee('Tamamı Geldi');
        $this->assertSafeOutput($procurementShow->getContent());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $supplierProcurement), [
                'action' => 'request_created',
                'note' => 'Talep açıldı',
            ])
            ->assertRedirect(route('admin.procurements.show', $supplierProcurement));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $supplierProcurement), [
                'action' => 'supplier_ordered',
                'note' => 'Sipariş geçildi',
            ])
            ->assertRedirect(route('admin.procurements.show', $supplierProcurement));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $supplierProcurement), [
                'action' => 'partially_received',
                'received_quantity' => '30',
                'note' => 'İlk parti geldi',
            ])
            ->assertRedirect(route('admin.procurements.show', $supplierProcurement));

        $supplierProcurement = $supplierProcurement->fresh(['workForm.activityLogs', 'orderItem']);
        $this->assertSame(OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, $supplierProcurement->procurement_status);
        $this->assertSame(30.0, (float) $supplierProcurement->received_quantity);
        $this->assertSame(70.0, (float) $supplierProcurement->remaining_quantity);
        $this->assertSame('Ürünün bir kısmı hazırlandı', data_get($supplierProcurement->workForm->procurement_snapshot, 'public_status_label'));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.procurements.show', $supplierProcurement))
            ->patch(route('admin.procurements.update-status', $supplierProcurement), [
                'action' => 'partially_received',
                'received_quantity' => '1000',
            ])
            ->assertRedirect(route('admin.procurements.show', $supplierProcurement))
            ->assertSessionHasErrors('received_quantity');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $supplierProcurement), [
                'action' => 'fully_received',
                'note' => 'Tamamı geldi',
            ])
            ->assertRedirect(route('admin.procurements.show', $supplierProcurement));

        $supplierProcurement = $supplierProcurement->fresh(['workForm.activityLogs', 'orderItem']);
        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $supplierProcurement->procurement_status);
        $this->assertSame((float) $supplierProcurement->requested_quantity, (float) $supplierProcurement->received_quantity);
        $this->assertSame(0.0, (float) $supplierProcurement->remaining_quantity);
        $this->assertSame($initialSupplierVersion + 4, $supplierProcurement->workForm->version);
        $this->assertSame('Ürün üretime hazır', data_get($supplierProcurement->workForm->procurement_snapshot, 'public_status_label'));
        $this->assertTrue($supplierProcurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_request_created'));
        $this->assertTrue($supplierProcurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'supplier_ordered'));
        $this->assertTrue($supplierProcurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_partially_received'));
        $this->assertTrue($supplierProcurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_fully_received'));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $localProcurement), [
                'action' => 'fully_received',
                'note' => 'Local olarak tamamlandı',
            ])
            ->assertRedirect(route('admin.procurements.show', $localProcurement));

        $localProcurement = $localProcurement->fresh(['workForm.activityLogs', 'orderItem']);
        $this->assertSame(OrderItemProcurement::FULFILLMENT_LOCAL_STOCK, $localProcurement->fulfillment_source);
        $this->assertSame(0.0, (float) $localProcurement->local_allocated_quantity);
        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $localProcurement->procurement_status);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame($initialSupplierStockSnapshot, $supplierProcurement->orderItem->stock_snapshot);
        $this->assertSame($initialLocalStockSnapshot, $localProcurement->orderItem->stock_snapshot);

        $orderShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order->fresh()));

        $orderShow->assertOk();
        $orderShow->assertSee('Genel Özet');
        $orderShow->assertSee('Tedarik');
        $orderShow->assertSee('Tedarik');
        $orderShow->assertSee('Tamamı Geldi');
        $orderShow->assertSee(route('admin.procurements.show', $supplierProcurement), false);
        $this->assertSafeOutput($orderShow->getContent());

        $workFormShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $supplierProcurement->workForm));

        $workFormShow->assertOk();
        $workFormShow->assertSee('Tedarik Durumu');
        $workFormShow->assertSee('Kaynak Tipi');
        $workFormShow->assertSee('İstenen Miktar');
        $workFormShow->assertSee('Gelen Miktar');
        $workFormShow->assertSee('Kalan Miktar');
        $workFormShow->assertSee('Tamamı Geldi');
        $this->assertSafeOutput($workFormShow->getContent());

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($supplierProcurement->workForm->fresh([
            'tenant',
            'attachments',
            'activityLogs.attachment',
            'systemWorkFolder',
        ]));

        $this->assertStringContainsString('Tedarik Durumu', $pdfHtml);
        $this->assertStringContainsString('Kaynak Tipi', $pdfHtml);
        $this->assertStringContainsString('Tamamı Geldi', $pdfHtml);
        $this->assertStringContainsString('Ürün üretime hazır', $pdfHtml);
        $this->assertStringContainsString('İŞ FORMU', $pdfHtml);
        $this->assertSafeOutput($pdfHtml);

        $publicTracking = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $supplierProcurement->workForm->public_tracking_token));

        $publicTracking->assertOk();
        $publicTracking->assertSee('Sipariş Takibi');
        $publicTracking->assertSee('Siparişiniz şu aşamada');
        $publicTracking->assertSee('Ürün üretime hazır');
        $publicTracking->assertSee($supplierProcurement->workForm->work_form_number);
        $publicTracking->assertSee($order->document_number);
        $publicTracking->assertSee('Procurement Supplier Ürünü');
        $publicTracking->assertDontSee($supplierSource->supplier->name);
        $publicTracking->assertDontSee('Tedarikçi Referans Stoğu');
        $publicTracking->assertDontSee('İlk parti geldi');
        $this->assertSafeOutput($publicTracking->getContent());
    }

    private function createConvertedOrderWithProcurementScenario(SupplierSource $source): Order
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-13',
                'valid_until' => '2026-06-20',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Procurement smoke payload',
                'items' => [
                    [
                        'product_name' => 'Procurement Supplier Ürünü',
                        'product_code' => 'PROC-SUP-001',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '9.90',
                        'discount_rate' => '20',
                        'unit_price' => '7.92',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                        'has_print' => '0',
                        'supplier_source_id' => $source->id,
                    ],
                    [
                        'product_name' => 'Procurement Local Ürünü',
                        'product_code' => 'PROC-LOC-001',
                        'quantity' => '25',
                        'unit' => 'Adet',
                        'list_price' => '5.00',
                        'discount_rate' => '10',
                        'unit_price' => '4.50',
                        'manual_unit_price' => '1',
                        'vat_rate' => '10',
                        'has_print' => '0',
                    ],
                ],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $quote->load('items');

        /** @var OrderItem $supplierItem */
        $supplierItem = $quote->items->firstWhere('product_code', 'PROC-SUP-001');
        /** @var OrderItem $localItem */
        $localItem = $quote->items->firstWhere('product_code', 'PROC-LOC-001');

        $supplierItem->forceFill([
            'product_source' => 'supplier_feed',
            'supplier_source_id' => $source->id,
            'product_snapshot' => [
                'product_name' => 'Procurement Supplier Ürünü',
                'product_code' => 'PROC-SUP-001',
                'supplier_name' => $source->supplier->name,
                'warning_badges' => ['Stok kontrolü gerekli'],
                'group_code' => 'SUP-GROUP-HIDDEN',
                'raw_mapping' => ['supplier_stock' => 999],
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 140,
                'local_stock_quantity' => 0,
                'safe_stock_quantity' => 0,
                'snapshot_taken_at' => '2026-06-13T11:00:00+03:00',
            ],
        ])->save();

        $localItem->forceFill([
            'product_source' => 'local_stock',
            'product_snapshot' => [
                'product_name' => 'Procurement Local Ürünü',
                'product_code' => 'PROC-LOC-001',
                'warning_badges' => ['Local stoktan karşılanabilir'],
                'group_code' => 'LOC-GROUP-HIDDEN',
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 18,
                'supplier_stock_quantity' => 0,
                'safe_stock_quantity' => 2,
                'local_stock_priority' => true,
                'snapshot_taken_at' => '2026-06-13T11:05:00+03:00',
            ],
        ])->save();

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

    private function assertSafeOutput(string $html): void
    {
        foreach ([
            'unit_price',
            'list_price',
            'discount_rate',
            'line_total',
            'print_unit_price',
            'print_total',
            'subtotal',
            'vat_total',
            'grand_total',
            'price_snapshot',
            'KDV',
            'alış maliyeti',
            'purchase_cost',
            'kâr',
            'group_code',
            'raw_mapping',
            'supplier_stock_quantity',
            'physical_path',
            'C:\\',
            '/var/',
            'storage/app',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }
}
