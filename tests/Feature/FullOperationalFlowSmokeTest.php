<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFolder;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\FinanceSummaryService;
use App\Services\WorkFormPdfService;
use App\Services\WorkFormQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FullOperationalFlowSmokeTest extends TestCase
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

    public function test_full_operational_flow_smoke_covers_all_operation_modules_and_security(): void
    {
        $supplierSource = $this->createSupplierSource('FULL-SMOKE');
        $partner = $this->createPartnerCompany();
        $quote = $this->createQuoteViaHttp(
            productCode: 'FULL-SMOKE-001',
            productName: 'Full Smoke Operasyon Ürünü',
            invoiceStatus: 'fatura',
            notes: 'Full operational smoke payload',
            supplierSourceId: $supplierSource->id,
            partnerCompanyId: $partner->id
        );

        $quote->load('items');

        /** @var OrderItem $quoteItem */
        $quoteItem = $quote->items->firstOrFail();
        $quoteItem->forceFill([
            'product_source' => 'supplier_feed',
            'supplier_source_id' => $supplierSource->id,
            'product_snapshot' => [
                'product_name' => 'Full Smoke Operasyon Ürünü',
                'product_code' => 'FULL-SMOKE-001',
                'supplier_name' => $supplierSource->supplier->name,
                'warning_badges' => ['Stok kontrolü gerekli'],
                'group_code' => 'FULL-HIDDEN-GROUP',
                'raw_mapping' => ['supplier_stock' => 999],
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 140,
                'local_stock_quantity' => 0,
                'safe_stock_quantity' => 0,
                'snapshot_taken_at' => '2026-06-13T11:00:00+03:00',
            ],
        ])->save();

        $this->assertMatchesRegularExpression('/^TK-\d{4}-\d{4}$/', $quote->document_number);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertMatchesRegularExpression('/^SP-\d{4}-\d{4}$/', $order->document_number);
        $this->assertSame($quote->id, $order->source_quote_id);
        $this->assertSame($quote->document_number, $order->source_quote_number);
        $this->assertSame($quote->invoice_status, $order->invoice_status);
        $this->assertSame($quote->currency, $order->currency);

        $orderSnapshotTotals = [
            'product_total' => (float) $order->product_total,
            'print_total' => (float) $order->print_total,
            'subtotal' => (float) $order->subtotal,
            'vat_total' => (float) $order->vat_total,
            'grand_total' => (float) $order->grand_total,
            'vat_breakdown_json' => $order->vat_breakdown_json,
            'invoice_status' => $order->invoice_status,
            'currency' => $order->currency,
        ];

        $order->load([
            'items.prints.production',
            'items.procurement.workForm.activityLogs',
            'items.workForm.attachments',
            'payments',
            'customer',
        ]);

        /** @var OrderItem $item */
        $item = $order->items->firstOrFail();
        /** @var OrderItemWorkForm $workForm */
        $workForm = $item->workForm->fresh(['attachments', 'activityLogs', 'systemWorkFolder']);
        /** @var OrderItemProcurement $procurement */
        $procurement = $item->procurement->fresh(['workForm.activityLogs', 'orderItem']);
        /** @var OrderItemWorkFormDelivery $delivery */
        $delivery = $workForm->delivery()->firstOrFail();
        $productions = $item->prints
            ->map(fn ($print) => $print->production?->fresh(['workForm', 'orderItemPrint', 'productionCompany']))
            ->filter()
            ->values();

        $this->assertMatchesRegularExpression('/^IF-\d{4}-\d{4}$/', $workForm->work_form_number);
        $this->assertNotEmpty($workForm->public_tracking_token);
        $this->assertCount(2, $item->prints);
        $this->assertCount(2, $productions);
        $this->assertNotNull($workForm->systemWorkFolder);
        $this->assertStringStartsWith('ISLER / ', $workForm->systemWorkFolder->display_path);
        $this->assertSame(0, StockMovement::query()->count());

        $initialFinanceSummary = app(FinanceSummaryService::class)->summarizeOrder($order);
        $this->assertSame(FinanceSummaryService::STATUS_PAYMENT_PENDING, $initialFinanceSummary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING, $delivery->financial_warning);

        $graphicsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));
        $graphicsIndex->assertOk()->assertSee($workForm->work_form_number)->assertSee('UV Baskı')->assertSee('Sıcak Baskı');
        $this->assertOperationalSurfaceSafe($graphicsIndex->getContent());

        $graphicsShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm));
        $graphicsShow->assertOk()->assertSee('Görsel Yükle')->assertSee($workForm->systemWorkFolder->display_path);
        $this->assertOperationalSurfaceSafe($graphicsShow->getContent());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'internal',
                'note' => 'İç grafik görseli',
                'redirect_to' => 'admin.graphics.show',
                'file' => UploadedFile::fake()->image('full-graphic-internal.jpg'),
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'customer_visible',
                'note' => 'Müşteri grafik görseli',
                'redirect_to' => 'admin.graphics.show',
                'file' => UploadedFile::fake()->image('full-graphic-public.jpg'),
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $workForm = $workForm->fresh(['attachments', 'activityLogs', 'systemWorkFolder']);

        $procurementIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));
        $procurementIndex->assertOk()->assertSee($order->document_number)->assertSee($workForm->work_form_number);
        $this->assertOperationalSurfaceSafe($procurementIndex->getContent());

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
                'note' => 'İlk parti geldi',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.update-status', $procurement), [
                'action' => 'fully_received',
                'note' => 'Tamamı geldi',
            ])
            ->assertRedirect(route('admin.procurements.show', $procurement));

        $procurement = $procurement->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame('Ürün üretime hazır', data_get($procurement->workForm->procurement_snapshot, 'public_status_label'));

        $this->markAllGraphicsProductionReady($workForm);

        $productionsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'));
        $productionsIndex->assertOk()->assertSee($order->document_number)->assertSee('UV Baskı')->assertSee('Sıcak Baskı');
        $this->assertOperationalSurfaceSafe($productionsIndex->getContent());

        /** @var OrderItemPrintProduction $internalProduction */
        $internalProduction = $productions->first(fn (OrderItemPrintProduction $production) => $production->orderItemPrint->print_type === 'UV Baskı');
        /** @var OrderItemPrintProduction $externalProduction */
        $externalProduction = $productions->first(fn (OrderItemPrintProduction $production) => $production->orderItemPrint->print_type === 'Sıcak Baskı');

        $this->assertNotNull($internalProduction);
        $this->assertNotNull($externalProduction);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $internalProduction), [
                'production_type' => 'internal',
                'production_unit_name' => 'UV Hattı 1',
                'assigned_to' => $this->adminUser->id,
                'cliche_required' => '0',
                'cliche_status' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
                'production_note' => 'İç hatta hazırlanıyor.',
            ])
            ->assertRedirect(route('admin.productions.show', $internalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $internalProduction), [
                'action' => 'assign_internal',
                'production_unit_name' => 'UV Hattı 1',
                'note' => 'İç üretime alındı',
            ])
            ->assertRedirect(route('admin.productions.show', $internalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $internalProduction), [
                'action' => 'completed',
            ])
            ->assertRedirect(route('admin.productions.show', $internalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'assign_external',
                'production_company_id' => $partner->id,
                'note' => 'Fason hazırlığı',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'sent_to_subcontractor',
                'note' => 'Fasona çıktı',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'returned_from_subcontractor',
                'note' => 'Fasondan döndü',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'qc_started',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'qc_failed',
                'note' => 'Yüzey tekrar kontrol edilecek',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'qc_started',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'qc_passed',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $externalProduction))
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'completed',
                'completed_quantity' => '999',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction))
            ->assertSessionHasErrors('completed_quantity');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $externalProduction), [
                'action' => 'completed',
            ])
            ->assertRedirect(route('admin.productions.show', $externalProduction));

        $workForm = $workForm->fresh(['attachments', 'activityLogs', 'systemWorkFolder']);
        $this->assertSame('Üretim tamamlandı', data_get($workForm->production_snapshot, 'public_status_label'));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'visibility' => 'internal',
                'note' => 'İç üretim fotoğrafı',
                'file' => UploadedFile::fake()->image('full-production-internal.jpg'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'visibility' => 'customer_visible',
                'note' => 'Müşteri üretim fotoğrafı',
                'file' => UploadedFile::fake()->image('full-production-public.jpg'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $externalProduction->fresh()))
            ->assertOk()
            ->assertSee('Tamamlandı');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => '100.00',
                'currency' => 'TL',
                'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
                'paid_at' => '2026-06-13T12:30',
                'payment_reference' => 'FULL-001',
                'payment_note' => 'Kısmi tahsilat',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments', 'customer']);
        $delivery = $delivery->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $partialSummary = app(FinanceSummaryService::class)->summarizeOrder($order);
        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $partialSummary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_BALANCE_DUE, $delivery->financial_warning);

        $deliveriesIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.index'));
        $deliveriesIndex->assertOk()->assertSee('Bakiye var');
        $this->assertOperationalSurfaceSafe($deliveriesIndex->getContent());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-details', $delivery), [
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
                'carrier_name' => 'Yurtiçi Kargo',
                'tracking_number' => 'FULL-TRK-001',
                'recipient_name' => 'Ahmet Yılmaz',
                'recipient_phone' => '05550001122',
                'delivery_note' => 'Teslim öncesi aranacak',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'delivered_quantity' => '40',
                'note' => 'İlk parti teslim edildi',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_PARTIALLY_DELIVERED, $delivery->delivery_status);
        $this->assertSame(40.0, (float) $delivery->delivered_quantity);
        $this->assertSame(60.0, (float) $delivery->remaining_quantity);

        $deliveryShowPartial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));
        $deliveryShowPartial->assertOk()->assertSee('Bakiye var');
        $this->assertOperationalSurfaceSafe($deliveryShowPartial->getContent());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_photo',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'internal',
                'note' => 'İç teslimat fotoğrafı',
                'file' => UploadedFile::fake()->image('full-delivery-internal.jpg'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_document',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'internal',
                'note' => 'İç teslimat belgesi',
                'file' => UploadedFile::fake()->create('full-delivery-internal.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_photo',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'customer_visible',
                'note' => 'Müşteri teslimat fotoğrafı',
                'file' => UploadedFile::fake()->image('full-delivery-public.jpg'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_document',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'customer_visible',
                'note' => 'Müşteri teslimat belgesi',
                'file' => UploadedFile::fake()->create('full-delivery-public.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.mark-paid', $order), [
                'payment_method' => OrderPayment::METHOD_OTHER,
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments', 'customer']);
        $delivery = $delivery->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $paidSummary = app(FinanceSummaryService::class)->summarizeOrder($order);
        $this->assertSame(FinanceSummaryService::STATUS_PAID, $paidSummary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_NONE, $delivery->financial_warning);

        $markPaidPayment = $order->payments()
            ->where('payment_note', 'like', '%Ödendi işaretle işlemi ile oluşturuldu.%')
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $markPaidPayment]), [
                'cancel_note' => 'Smoke iptal senaryosu',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments', 'customer']);
        $delivery = $delivery->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $cancelledSummary = app(FinanceSummaryService::class)->summarizeOrder($order);
        $this->assertSame(FinanceSummaryService::STATUS_PARTIAL_PAYMENT, $cancelledSummary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_BALANCE_DUE, $delivery->financial_warning);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.mark-paid', $order), [
                'payment_method' => OrderPayment::METHOD_OTHER,
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $order = $order->fresh(['payments', 'customer']);
        $delivery = $delivery->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $finalFinanceSummary = app(FinanceSummaryService::class)->summarizeOrder($order);
        $this->assertSame(FinanceSummaryService::STATUS_PAID, $finalFinanceSummary['payment_status']);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_NONE, $delivery->financial_warning);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'delivered',
                'note' => 'Teslimat tamamlandı',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_DELIVERED, $delivery->delivery_status);
        $this->assertSame((float) $delivery->planned_quantity, (float) $delivery->delivered_quantity);
        $this->assertSame(0.0, (float) $delivery->remaining_quantity);
        $this->assertSame('Teslim edildi', data_get($delivery->workForm->delivery_snapshot, 'public_status_label'));
        $workForm = $delivery->workForm->fresh(['attachments', 'activityLogs', 'systemWorkFolder']);

        $financeIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.index'));
        $financeIndex->assertOk()->assertSee('Fatura')->assertSee('Ödendi');

        $financeShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order));
        $financeShow->assertOk()->assertSee('Fatura')->assertSee('Ödendi')->assertSee('Finans uyarısı yok');

        $deliveriesShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));
        $deliveriesShow->assertOk()->assertSee('Finans uyarısı yok');
        $this->assertOperationalSurfaceSafe($deliveriesShow->getContent());

        $ordersShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));
        $ordersShow->assertOk()
            ->assertSee('Tamamlandı')
            ->assertSee('Genel Özet')
            ->assertSee('Kalan Bakiye')
            ->assertSee(route('admin.finance.show', $order), false);

        $workFormShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));
        $this->assertSame(200, $workFormShow->getStatusCode(), 'work form show should stay accessible');
        $workFormShow->assertSee('Teslim Edildi')->assertSee('full-ready-1b.jpg')->assertSee('full-delivery-public.pdf');
        $this->assertOperationalSurfaceSafe($workFormShow->getContent());

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($workForm->fresh([
            'tenant',
            'attachments',
            'activityLogs.attachment',
            'systemWorkFolder',
        ]));
        $this->assertStringContainsString('Teslim Edildi', $pdfHtml);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $pdfHtml);
        $this->assertOperationalSurfaceSafe($pdfHtml);

        $pdfResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.pdf', $workForm));
        $this->assertSame(200, $pdfResponse->getStatusCode(), 'work form pdf should be downloadable');
        $pdfResponse->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('%PDF', (string) $pdfResponse->getContent());

        $publicTracking = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $this->assertSame(200, $publicTracking->getStatusCode(), 'public tracking page should stay accessible');
        $publicTracking->assertSee($workForm->work_form_number);
        $publicTracking->assertSee($order->document_number);
        $publicTracking->assertSee('Full Smoke Operasyon Ürünü');
        $publicTracking->assertSee('Üretim tamamlandı');
        $publicTracking->assertSee('Teslim edildi');
        $publicTracking->assertSee('full-graphic-public.jpg');
        $publicTracking->assertSee('full-production-public.jpg');
        $publicTracking->assertSee('full-delivery-public.jpg');
        $publicTracking->assertSee('full-delivery-public.pdf');
        $publicTracking->assertDontSee('full-graphic-internal.jpg');
        $publicTracking->assertDontSee('full-production-internal.jpg');
        $publicTracking->assertDontSee('full-delivery-internal.jpg');
        $publicTracking->assertDontSee('full-delivery-internal.pdf');
        $this->assertPublicSurfaceSafe($publicTracking->getContent());

        $internalAttachment = $workForm->attachments->firstWhere('file_name', 'full-delivery-internal.jpg');
        $publicAttachment = $workForm->attachments()
            ->where('attachment_type', 'delivery_photo')
            ->where('visibility', 'customer_visible')
            ->where('file_name', 'full-delivery-public.jpg')
            ->latest('id')
            ->first();
        $this->assertNotNull($internalAttachment);
        $this->assertNotNull($publicAttachment);
        $this->assertNotEmpty($publicAttachment->file_path);
        $this->assertTrue(Storage::disk($publicAttachment->disk ?: 'public')->exists($publicAttachment->file_path));

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $internalAttachment->id,
            ]))
            ->assertNotFound();

        $otherWorkForm = $this->createMinimalConvertedWorkForm('WRONG-TOKEN-001', 'Yanlış token kontrolü');
        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $otherWorkForm->public_tracking_token,
                'attachment' => $publicAttachment->id,
            ]))
            ->assertNotFound();

        $publicAttachmentResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $publicAttachment->id,
            ]));
        $this->assertSame(200, $publicAttachmentResponse->getStatusCode(), 'customer-visible attachment should be downloadable');
        $publicAttachmentResponse->assertHeader('X-Content-Type-Options', 'nosniff');

        $deliveryRoleUser = $this->createUserWithRole('delivery');
        $this->actingAs($deliveryRoleUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order))
            ->assertForbidden();

        $this->actingAs($deliveryRoleUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.finance.payments.store', $order), [
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => '50',
                'currency' => 'TL',
            ])
            ->assertForbidden();

        $this->assertSame(
            route('public.work-forms.track', $workForm->public_tracking_token),
            app(WorkFormQrCodeService::class)->trackingUrl($workForm)
        );
        $this->assertStringContainsString('<svg', app(WorkFormQrCodeService::class)->qrSvg($workForm));

        $order = $order->fresh();
        $this->assertSame($orderSnapshotTotals['product_total'], (float) $order->product_total);
        $this->assertSame($orderSnapshotTotals['print_total'], (float) $order->print_total);
        $this->assertSame($orderSnapshotTotals['subtotal'], (float) $order->subtotal);
        $this->assertSame($orderSnapshotTotals['vat_total'], (float) $order->vat_total);
        $this->assertSame($orderSnapshotTotals['grand_total'], (float) $order->grand_total);
        $this->assertSame($orderSnapshotTotals['vat_breakdown_json'], $order->vat_breakdown_json);
        $this->assertSame($orderSnapshotTotals['invoice_status'], $order->invoice_status);
        $this->assertSame($orderSnapshotTotals['currency'], $order->currency);
    }

    public function test_fis_conversion_keeps_zero_vat_totals_and_source_snapshot_intact(): void
    {
        $quote = $this->createQuoteViaHttp(
            productCode: 'FULL-FIS-001',
            productName: 'Fiş Smoke Ürünü',
            invoiceStatus: 'fis',
            notes: 'Fiş smoke payload'
        );

        $this->assertSame('fis', $quote->invoice_status);
        $this->assertSame(0.0, (float) $quote->vat_total);
        $this->assertSame((float) $quote->subtotal, (float) $quote->grand_total);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('fis', $order->invoice_status);
        $this->assertSame(0.0, (float) $order->vat_total);
        $this->assertSame((float) $order->subtotal, (float) $order->grand_total);
        $this->assertSame($quote->document_number, $order->source_quote_number);
    }

    private function createQuoteViaHttp(
        string $productCode,
        string $productName,
        string $invoiceStatus,
        string $notes,
        ?int $supplierSourceId = null,
        ?int $partnerCompanyId = null
    ): Order {
        $payload = [
            'customer_company_id' => $this->customer->id,
            'quote_date' => '2026-06-13',
            'valid_until' => '2026-06-20',
            'invoice_status' => $invoiceStatus,
            'currency' => 'TL',
            'delivery_type' => 'Kargo',
            'notes' => $notes,
            'items' => [[
                'product_name' => $productName,
                'product_code' => $productCode,
                'quantity' => '100',
                'unit' => 'Adet',
                'list_price' => '12.50',
                'discount_rate' => '20',
                'unit_price' => '10.00',
                'manual_unit_price' => '1',
                'vat_rate' => '20',
                'has_print' => '1',
                'prints' => [
                    [
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf baskılı',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '100',
                        'print_unit_price' => '5',
                        'note' => 'Logo baskı',
                    ],
                    [
                        'print_type' => 'Sıcak Baskı',
                        'print_option' => 'Gövde baskı',
                        'production_type' => 'Dış üretim / Fason',
                        'print_quantity' => '100',
                        'print_unit_price' => '10',
                        'note' => 'İsim baskı',
                    ],
                ],
            ]],
        ];

        if ($supplierSourceId) {
            $payload['items'][0]['supplier_source_id'] = $supplierSourceId;
        }

        if ($partnerCompanyId) {
            $payload['items'][0]['prints'][1]['subcontractor_company_id'] = $partnerCompanyId;
        }

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', $payload)
            ->assertRedirect();

        return Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
    }

    private function createMinimalConvertedWorkForm(string $productCode, string $notes): OrderItemWorkForm
    {
        $quote = $this->createQuoteViaHttp(
            productCode: $productCode,
            productName: 'Yanlış Token Smoke Ürünü',
            invoiceStatus: 'fatura',
            notes: $notes
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        return OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
    }

    private function createPartnerCompany(): Company
    {
        $existing = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('status', 'active')
            ->whereKeyNot($this->customer->id)
            ->whereHas('companyRoles', fn ($query) => $query->whereIn('role_key', ['print_fason', 'production_partner']))
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Full Smoke Partner',
            'short_name' => 'Full Smoke Partner',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => 'print_fason',
        ]);

        return $company;
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

    private function markAllGraphicsProductionReady(OrderItemWorkForm $workForm): void
    {
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get();

        foreach ($graphics as $graphic) {
            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post(route('admin.work-forms.attachments.store', $workForm), [
                    'attachment_type' => 'graphic_visual',
                    'visibility' => 'internal',
                    'note' => 'Final görsel ' . $graphic->sequence_code,
                    'redirect_to' => 'admin.graphics.show',
                    'order_item_print_graphic_id' => $graphic->id,
                    'file' => UploadedFile::fake()->image('full-ready-' . $graphic->sequence_code . '.jpg'),
                ])
                ->assertRedirect(route('admin.graphics.show', $workForm));

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->patch(route('admin.graphics.operations.update-status', [
                    'workForm' => $workForm,
                    'graphic' => $graphic,
                ]), [
                    'action' => 'approved',
                ])
                ->assertRedirect(route('admin.graphics.show', $workForm));

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->patch(route('admin.graphics.operations.update-status', [
                    'workForm' => $workForm,
                    'graphic' => $graphic,
                ]), [
                    'action' => 'production_ready',
                ])
                ->assertRedirect(route('admin.graphics.show', $workForm));
        }
    }

    private function createUserWithRole(string $roleKey): User
    {
        $user = User::query()->create([
            'name' => 'Full Operational Unauthorized',
            'email' => 'full-operational-' . $roleKey . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }

    private function assertOperationalSurfaceSafe(string $html): void
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
            'paid_total',
            'balance_due',
            'price_snapshot',
            'KDV',
            'tahsilat tutarı',
            'bakiye tutarı',
            'maliyet',
            'kâr',
            'group_code',
            'raw_mapping',
            'physical_path',
            'storage/app',
            'C:\\',
            '/var/',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    private function assertPublicSurfaceSafe(string $html): void
    {
        foreach ([
            'unit_price',
            'list_price',
            'line_total',
            'print_unit_price',
            'print_total',
            'subtotal',
            'vat_total',
            'grand_total',
            'paid_total',
            'balance_due',
            'financial_warning',
            'Ödeme bekliyor',
            'Bakiye var',
            'Tahsilat onayı bekleniyor',
            'price_snapshot',
            'group_code',
            'physical_path',
            'storage/app',
            'C:\\',
            '/var/',
            'work-forms/',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }
}
