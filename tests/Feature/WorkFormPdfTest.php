<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkFormPdfTest extends TestCase
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

    public function test_pdf_service_renders_fiyatsiz_html_with_qr_and_turkish_characters(): void
    {
        $workForm = $this->createConvertedWorkForm();
        Storage::disk('public')->put('work-forms/pdf-graphic.png', 'fake-image');

        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'internal',
            'file_path' => 'work-forms/pdf-graphic.png',
            'file_name' => 'grafik-gorseli.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
        ]);

        $service = app(WorkFormPdfService::class);
        $html = $service->renderHtml($workForm->fresh(['attachments', 'activityLogs.attachment', 'tenant']));

        $this->assertStringContainsString($workForm->work_form_number, $html);
        $this->assertStringContainsString((string) data_get($workForm->order_snapshot, 'document_number'), $html);
        $this->assertStringContainsString((string) data_get($workForm->product_snapshot, 'product_name'), $html);
        $this->assertStringContainsString('QR ile müşteri takip ekranı açılır.', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('İŞ FORMU', $html);
        $this->assertStringContainsString('İç üretim', $html);
        $this->assertStringContainsString('Tedarik Durumu', $html);
        $this->assertStringContainsString('Talep Hazırlanacak', $html);
        $this->assertStringContainsString('Ürününüz hazırlanıyor', $html);
        $this->assertStringContainsString('Üretim Durumu', $html);
        $this->assertStringContainsString('Üretim Bekliyor', $html);
        $this->assertStringContainsString('Planlanan / Tamamlanan', $html);
        $this->assertStringContainsString('Kaynak Tipi', $html);
        $this->assertStringContainsString('Gelen Miktar', $html);
        $this->assertStringContainsString('Kalan Miktar', $html);
        $this->assertStringContainsString('Kalan', $html);
        $this->assertStringNotContainsString('Kalan / Klişe Durumu', $html);
        $this->assertStringNotContainsString('unit_price', $html);
        $this->assertStringNotContainsString('grand_total', $html);
        $this->assertStringNotContainsString('print_total', $html);
        $this->assertStringNotContainsString('KDV', $html);
        $this->assertStringNotContainsString('İskonto', $html);
        $this->assertStringNotContainsString('group_code', $html);
        $this->assertStringNotContainsString('purchase_cost', $html);
        $this->assertStringNotContainsString('üretim maliyeti', $html);
    }

    public function test_admin_pdf_route_returns_pdf_response_with_safe_filename(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.pdf', $workForm));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('IF-2026', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_tenant_mismatch_or_cancelled_form_cannot_be_exported_as_pdf(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-pdf',
            'panel_subdomain' => 'other-tenant-pdf',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $workForm->tenant_account_id = $otherTenant->id;
        $workForm->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.pdf', $workForm))
            ->assertForbidden();

        $workForm = $this->createConvertedWorkForm();
        $workForm->forceFill(['status' => 'cancelled'])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.pdf', $workForm))
            ->assertNotFound();
    }

    public function test_admin_show_contains_real_pdf_download_link(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $response->assertOk();
        $response->assertSee('PDF İndir');
        $response->assertSee(route('admin.work-forms.pdf', $workForm), false);
    }

    private function createConvertedWorkForm(): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'PDF çıktı notu',
                'items' => [
                    [
                        'product_name' => 'AK-1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
                        'product_code' => 'AK-1020-KIRMIZI',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '10.50',
                        'discount_rate' => '30',
                        'unit_price' => '7.35',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                        'has_print' => '1',
                        'prints' => [
                            [
                                'print_type' => 'UV Baskı',
                                'print_option' => 'Tek taraf baskılı',
                                'production_type' => 'İç üretim',
                                'print_quantity' => '100',
                                'print_unit_price' => '4',
                                'note' => 'Logo baskı',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        return OrderItemWorkForm::query()->latest('id')->firstOrFail();
    }
}
