<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraphicAttachmentPreviewRuntimeTest extends TestCase
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

    public function test_admin_preview_route_streams_images_and_keeps_non_images_safe(): void
    {
        $workForm = $this->createConvertedWorkForm('PREVIEW-RUNTIME-001');
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');

        $service = app(WorkFormAttachmentService::class);

        $imageAttachment = $service->attachGraphicVisualToPrintGraphic(
            $graphics['1a'],
            UploadedFile::fake()->image('runtime-preview.jpg'),
            ['visibility' => 'internal', 'note' => '1a runtime preview'],
            $this->adminUser
        );

        $pdfAttachment = $service->attachGraphicVisualToPrintGraphic(
            $graphics['1b'],
            UploadedFile::fake()->create('runtime-proof.pdf', 120, 'application/pdf'),
            ['visibility' => 'customer_visible', 'note' => '1b runtime proof'],
            $this->adminUser
        );

        $previewUrl = route('admin.work-forms.attachments.preview', $imageAttachment);

        $previewResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($previewUrl);

        $previewResponse->assertOk();
        $previewResponse->assertHeader('Content-Type', 'image/jpeg');

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $showResponse->assertOk();
        $showResponse->assertSee($previewUrl, false);
        $showResponse->assertSee(route('admin.work-forms.attachments.preview', $pdfAttachment), false);
        $showResponse->assertSee('runtime-preview.jpg');
        $showResponse->assertSee('runtime-proof.pdf');
        $showResponse->assertSee('Dosyayı Aç');
        $showResponse->assertSee('PDF dosyası');
        $showResponse->assertDontSee('/storage/work-forms/', false);
        $showResponse->assertDontSee('file_path', false);
        $showResponse->assertDontSee('physical_path', false);

        $html = $showResponse->getContent();
        $oneACardStart = strpos($html, 'id="operation-' . $graphics['1a']->id . '"');
        $oneBCardStart = strpos($html, 'id="operation-' . $graphics['1b']->id . '"');

        $this->assertNotFalse($oneACardStart);
        $this->assertNotFalse($oneBCardStart);

        $oneACardHtml = substr($html, $oneACardStart, max(0, $oneBCardStart - $oneACardStart));
        $oneBCardHtml = substr($html, $oneBCardStart);

        $this->assertStringContainsString('runtime-preview.jpg', $oneACardHtml);
        $this->assertStringContainsString($previewUrl, $oneACardHtml);
        $this->assertStringNotContainsString('runtime-proof.pdf', $oneACardHtml);
        $this->assertStringContainsString('runtime-proof.pdf', $oneBCardHtml);
        $this->assertStringNotContainsString('alt="runtime-proof.pdf"', $oneBCardHtml);

        Storage::disk('public')->delete($imageAttachment->file_path);

        $missingPreviewResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $missingPreviewResponse->assertOk();
        $missingPreviewResponse->assertSee('Önizleme alınamadı');

        auth()->guard()->logout();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($previewUrl)
            ->assertRedirect(route('login'));

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-preview-runtime',
            'panel_subdomain' => 'other-tenant-preview-runtime',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $imageAttachment->forceFill([
            'tenant_account_id' => $otherTenant->id,
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.attachments.preview', $imageAttachment))
            ->assertForbidden();
    }

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-15',
                'valid_until' => '2026-06-22',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Graphic attachment runtime preview payload',
                'items' => [[
                    'product_name' => 'Graphic Runtime Preview Ürünü',
                    'product_code' => $productCode,
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'İsim lazer',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '10',
                            'print_unit_price' => '10',
                        ],
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '10',
                            'print_unit_price' => '10',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
    }
}
