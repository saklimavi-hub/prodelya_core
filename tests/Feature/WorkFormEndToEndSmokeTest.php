<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\WorkFormPdfService;
use App\Services\WorkFormQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkFormEndToEndSmokeTest extends TestCase
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

    public function test_work_form_end_to_end_smoke_flow_covers_conversion_upload_public_qr_and_pdf_without_price_leak(): void
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

        $this->assertMatchesRegularExpression('/^SP-\d{4}-\d{4}$/', $order->document_number);
        $this->assertMatchesRegularExpression('/^IF-\d{4}-\d{4}$/', $workForm->work_form_number);
        $this->assertSame(1, $order->items()->count());

        $adminShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $adminShow->assertOk();
        $adminShow->assertSee('Grafik Görseli Ekle');
        $adminShow->assertSee('Telefondan Fotoğraf Ekle');
        $adminShow->assertSee('Teslimat Fotoğrafı / Belgesi Ekle');
        $adminShow->assertSee('capture="environment"', false);
        $adminShow->assertSee('wf-upload-only', false);
        $adminShow->assertDontSee('unit_price', false);
        $adminShow->assertDontSee('grand_total', false);
        $adminShow->assertDontSee('group_code', false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'customer_visible',
                'note' => 'Müşteri onay görseli',
                'file' => UploadedFile::fake()->image('graphic-customer.webp'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'visibility' => 'internal',
                'note' => 'İç üretim fotoğrafı',
                'file' => UploadedFile::fake()->image('production-internal.jpg'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_photo',
                'visibility' => 'customer_visible',
                'note' => 'Teslim fotoğrafı',
                'file' => UploadedFile::fake()->image('delivery-customer.png'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_document',
                'visibility' => 'customer_visible',
                'note' => 'Teslim belgesi',
                'file' => UploadedFile::fake()->create('delivery-document.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $workForm));

        $workForm = $workForm->fresh(['attachments', 'activityLogs']);

        $this->assertSame(9, $workForm->version);
        $this->assertSame(4, $workForm->attachments()->count());
        $this->assertSame(9, $workForm->activityLogs()->count());
        $this->assertNotNull(data_get($workForm->graphic_snapshot, 'primary_visual_attachment_id'));
        $this->assertSame(1, (int) data_get($workForm->production_snapshot, 'photo_count'));
        $this->assertSame(1, (int) data_get($workForm->delivery_snapshot, 'photo_count'));
        $this->assertSame(1, (int) data_get($workForm->delivery_snapshot, 'document_count'));

        $publicShow = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicShow->assertOk();
        $publicShow->assertSee($workForm->work_form_number);
        $publicShow->assertSee('graphic-customer.webp');
        $publicShow->assertSee('delivery-customer.png');
        $publicShow->assertSee('delivery-document.pdf');
        $publicShow->assertDontSee('production-internal.jpg');
        $publicShow->assertDontSee('work-forms/');
        $publicShow->assertDontSee('unit_price', false);
        $publicShow->assertDontSee('grand_total', false);
        $publicShow->assertDontSee('group_code', false);

        $internalAttachment = $workForm->attachments->firstWhere('attachment_type', 'production_photo');
        $customerAttachment = $workForm->attachments->firstWhere('attachment_type', 'delivery_photo');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $internalAttachment->id,
            ]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $customerAttachment->id,
            ]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $qrService = app(WorkFormQrCodeService::class);
        $this->assertSame(route('public.work-forms.track', $workForm->public_tracking_token), $qrService->trackingUrl($workForm));
        $this->assertStringContainsString('<svg', $qrService->qrSvg($workForm));

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($workForm->fresh(['tenant', 'attachments', 'activityLogs.attachment']));
        $this->assertStringContainsString($workForm->work_form_number, $pdfHtml);
        $this->assertStringContainsString($order->document_number, $pdfHtml);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $pdfHtml);
        $this->assertStringNotContainsString('unit_price', $pdfHtml);
        $this->assertStringNotContainsString('grand_total', $pdfHtml);
        $this->assertStringNotContainsString('KDV', $pdfHtml);
        $this->assertStringNotContainsString('group_code', $pdfHtml);

        $pdfResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.pdf', $workForm));

        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('%PDF', (string) $pdfResponse->getContent());
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
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Uçtan uca smoke test notu',
                'items' => [[
                    'product_name' => 'Smoke Test Kırmızı Metal Tükenmez Kalem',
                    'product_code' => 'SMOKE-END-001',
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
}
