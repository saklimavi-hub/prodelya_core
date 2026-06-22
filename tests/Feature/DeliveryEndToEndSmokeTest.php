<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryEndToEndSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_delivery_end_to_end_smoke_flow_covers_status_visibility_pdf_public_and_no_financial_leak(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $workForm = OrderItemWorkForm::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->firstOrFail();

        $this->markWorkFormDeliveryReady($workForm);

        $delivery = $workForm->delivery()->firstOrFail();
        $initialVersion = $workForm->version;

        $this->assertSame(OrderItemWorkFormDelivery::STATUS_PENDING, $delivery->delivery_status);
        $this->assertSame(100.0, (float) $delivery->planned_quantity);
        $this->assertSame(0.0, (float) $delivery->delivered_quantity);
        $this->assertSame(100.0, (float) $delivery->remaining_quantity);

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.index'));

        $index->assertOk();
        $index->assertSee($order->document_number);
        $index->assertSee($workForm->work_form_number);
        $index->assertSee('Teslimat Bekleyen');
        $index->assertSee('Üretim tamamlanmadan teslimat başlatılmamalı.');
        $index->assertDontSee('unit_price', false);
        $index->assertDontSee('KDV');
        $index->assertDontSee('group_code', false);

        $detail = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $detail->assertOk();
        $detail->assertSee('Teslimat Detayı');
        $detail->assertSee('Üretim tamamlanmadan teslimat başlatılmamalı.');
        $detail->assertSee('Kalite kontrol tamamlanmadı.');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-details', $delivery), [
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
                'carrier_name' => 'Yurtiçi Kargo',
                'tracking_number' => 'TRK-SMOKE-001',
                'recipient_name' => 'Ahmet Yılmaz',
                'recipient_phone' => '05550001122',
                'delivery_note' => 'Teslim öncesi aranacak',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        app(OrderPaymentService::class)->createPayment($order, [
            'payment_type' => 'tahsilat',
            'amount' => 100,
            'currency' => 'TL',
            'paid_at' => now(),
            'payment_note' => 'Smoke partial payment',
        ], $this->adminUser);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'delivered_quantity' => '40',
                'note' => 'İlk parti teslim edildi',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh(['workForm.activityLogs', 'workForm.attachments']);
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_PARTIALLY_DELIVERED, $delivery->delivery_status);
        $this->assertSame(40.0, (float) $delivery->delivered_quantity);
        $this->assertSame(60.0, (float) $delivery->remaining_quantity);
        $this->assertGreaterThan($initialVersion, $delivery->workForm->version);
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_details_updated'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_partially_completed'));

        $detailPartial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $detailPartial->assertOk();
        $detailPartial->assertSee('Kısmi Teslim Edildi');
        $detailPartial->assertSee('40');
        $detailPartial->assertSee('60');
        $detailPartial->assertSee('Bakiye var');
        $detailPartial->assertDontSee('price_snapshot', false);

        $orderShowPartial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));

        $orderShowPartial->assertOk();
        $orderShowPartial->assertSee('Kısmi Teslim Edildi');
        $orderShowPartial->assertSee(route('admin.work-forms.show', $workForm), false);
        $orderShowPartial->assertDontSee('grand_total', false);
        $orderShowPartial->assertDontSee('group_code', false);

        $workFormShowPartial = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $workFormShowPartial->assertOk();
        $workFormShowPartial->assertSee('Kısmi Teslim Edildi');
        $workFormShowPartial->assertSee('Yurtiçi Kargo');
        $workFormShowPartial->assertSee('40');
        $workFormShowPartial->assertSee('60');
        $workFormShowPartial->assertSee('Bakiye var');

        $publicPartial = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicPartial->assertOk();
        $publicPartial->assertSee('Kısmi teslim edildi');
        $publicPartial->assertDontSee('Bakiye var');
        $publicPartial->assertDontSee('Tahsilat onayı bekleniyor');
        $publicPartial->assertDontSee('Teslim öncesi aranacak');
        $publicPartial->assertDontSee('storage/app');
        $publicPartial->assertDontSee('C:\\');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_photo',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'internal',
                'note' => 'İç teslim fotoğrafı',
                'file' => UploadedFile::fake()->image('delivery-internal.jpg'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_document',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'internal',
                'note' => 'İç teslim belgesi',
                'file' => UploadedFile::fake()->create('delivery-internal.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_photo',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'customer_visible',
                'note' => 'Müşteri teslim fotoğrafı',
                'file' => UploadedFile::fake()->image('delivery-public.jpg'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_document',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'customer_visible',
                'note' => 'Müşteri teslim belgesi',
                'file' => UploadedFile::fake()->create('delivery-public.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $this->assertSame(2, (int) data_get($delivery->workForm->delivery_snapshot, 'photo_count'));
        $this->assertSame(2, (int) data_get($delivery->workForm->delivery_snapshot, 'document_count'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_photo_added'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_document_added'));

        $deliveryShowUploads = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $deliveryShowUploads->assertOk();
        $deliveryShowUploads->assertSee('delivery-internal.jpg');
        $deliveryShowUploads->assertSee('delivery-internal.pdf');
        $deliveryShowUploads->assertSee('delivery-public.jpg');
        $deliveryShowUploads->assertSee('delivery-public.pdf');

        $publicWithFiles = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicWithFiles->assertOk();
        $publicWithFiles->assertSee('delivery-public.jpg');
        $publicWithFiles->assertSee('delivery-public.pdf');
        $publicWithFiles->assertDontSee('delivery-internal.jpg');
        $publicWithFiles->assertDontSee('delivery-internal.pdf');
        $publicWithFiles->assertDontSee('work-forms/');

        $internalAttachment = $delivery->workForm->attachments->firstWhere('file_name', 'delivery-internal.jpg');
        $publicAttachment = $delivery->workForm->attachments->firstWhere('file_name', 'delivery-public.jpg');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $internalAttachment->id,
            ]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $publicAttachment->id,
            ]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.deliveries.show', $delivery))
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'delivered_quantity' => '1000',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery))
            ->assertSessionHasErrors('delivered_quantity');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'delivered',
                'note' => 'Teslim süreci tamamlandı',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_DELIVERED, $delivery->delivery_status);
        $this->assertSame((float) $delivery->planned_quantity, (float) $delivery->delivered_quantity);
        $this->assertSame(0.0, (float) $delivery->remaining_quantity);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_completed'));

        $workForm = $workForm->fresh();
        $this->assertSame('Teslim edildi', data_get($workForm->delivery_snapshot, 'public_status_label'));

        $orderShowDelivered = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));
        $orderShowDelivered->assertOk();
        $orderShowDelivered->assertSee('Teslim Edildi');

        $workFormShowDelivered = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));
        $workFormShowDelivered->assertOk();
        $workFormShowDelivered->assertSee('Teslim Edildi');
        $workFormShowDelivered->assertSee('delivery-internal.jpg');
        $workFormShowDelivered->assertSee('delivery-public.jpg');
        $workFormShowDelivered->assertSee('delivery-internal.pdf');
        $workFormShowDelivered->assertSee('delivery-public.pdf');

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($workForm->fresh(['tenant', 'attachments', 'activityLogs.attachment']));
        $this->assertStringContainsString('Teslim Edildi', $pdfHtml);
        $this->assertStringContainsString('Yurtiçi Kargo', $pdfHtml);
        $this->assertStringContainsString('100', $pdfHtml);
        $this->assertStringContainsString('delivery-public.pdf', $pdfHtml);
        $this->assertStringNotContainsString('unit_price', $pdfHtml);
        $this->assertStringNotContainsString('grand_total', $pdfHtml);
        $this->assertStringNotContainsString('group_code', $pdfHtml);
        $this->assertStringNotContainsString('storage/app', $pdfHtml);

        $pdfResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.pdf', $workForm));
        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('%PDF', (string) $pdfResponse->getContent());

        $publicDelivered = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $publicDelivered->assertOk();
        $publicDelivered->assertSee('Teslim edildi');
        $publicDelivered->assertDontSee('Bakiye var');
        $publicDelivered->assertDontSee('group_code', false);
        $publicDelivered->assertDontSee('storage/app');
        $publicDelivered->assertDontSee('/var/');
    }

    private function createQuoteViaHttp(): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-13',
                'valid_until' => '2026-06-20',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Delivery smoke payload',
                'items' => [[
                    'product_name' => 'Teslimat Smoke Ürünü',
                    'product_code' => 'DLV-SMOKE-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '8.60',
                    'discount_rate' => '45',
                    'unit_price' => '4.70',
                    'manual_unit_price' => '1',
                    'vat_rate' => '10',
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
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                            'note' => 'İsim baskı',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

        return Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
    }

    private function markWorkFormDeliveryReady(OrderItemWorkForm $workForm): void
    {
        $workForm->loadMissing(['orderItem', 'printProductions', 'procurement']);
        $item = $workForm->orderItem;

        if ($item?->has_print) {
            foreach ($workForm->printProductions as $production) {
                $production->forceFill([
                    'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
                    'completed_quantity' => (float) $production->planned_quantity,
                    'remaining_quantity' => 0,
                ])->save();
            }

            $workForm->forceFill([
                'production_snapshot' => array_merge(
                    is_array($workForm->production_snapshot) ? $workForm->production_snapshot : [],
                    [
                        'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
                        'production_status_label' => 'Tamamlandı',
                        'completed_quantity' => (float) ($item?->quantity ?? 0),
                        'remaining_quantity' => 0,
                        'public_status_label' => 'Üretim tamamlandı',
                    ]
                ),
            ])->save();
        }

        if ($workForm->procurement) {
            $workForm->procurement->forceFill([
                'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                'received_quantity' => (float) ($item?->quantity ?? 0),
                'remaining_quantity' => 0,
            ])->save();

            $workForm->forceFill([
                'procurement_snapshot' => array_merge(
                    is_array($workForm->procurement_snapshot) ? $workForm->procurement_snapshot : [],
                    [
                        'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                        'procurement_status_label' => 'Tamamı Geldi',
                        'received_quantity' => (float) ($item?->quantity ?? 0),
                        'remaining_quantity' => 0,
                    ]
                ),
            ])->save();
        }
    }
}
