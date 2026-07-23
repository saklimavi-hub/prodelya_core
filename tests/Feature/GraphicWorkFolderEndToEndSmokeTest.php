<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkFolder;
use App\Models\OrderItemWorkForm;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\WorkFormPdfService;
use App\Services\WorkFormQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraphicWorkFolderEndToEndSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->makeDirectory('work-forms');
        Storage::disk('local')->makeDirectory('work-forms');
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_graphic_work_folder_end_to_end_smoke_flow_is_safe_and_complete(): void
    {
        $firstWorkForm = $this->createConvertedWorkForm('E2E-GRAPHIC-001', 'Default root payload');
        $firstFolder = OrderItemWorkFolder::query()
            ->where('work_form_id', $firstWorkForm->id)
            ->where('folder_type', 'system')
            ->firstOrFail();

        $this->assertStringStartsWith('ISLER / ', $firstFolder->display_path);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.update'), [
                'work_folder_root_name' => 'Grafik İşleri',
            ])
            ->assertRedirect(route('admin.settings'));

        $this->assertSame(
            'GRAFIK-ISLERI',
            TenantSetting::query()->where('key', 'work_folder_root_name')->value('value')
        );

        $workForm = $this->createConvertedWorkForm('E2E-GRAPHIC-002', 'Updated root payload');
        $folder = OrderItemWorkFolder::query()
            ->where('work_form_id', $workForm->id)
            ->where('folder_type', 'system')
            ->firstOrFail();

        $firstFolder->refresh();

        $this->assertStringStartsWith('ISLER / ', $firstFolder->display_path);
        $this->assertStringStartsWith('GRAFIK-ISLERI / ', $folder->display_path);
        $this->assertStringStartsWith('GRAFIK-ISLERI/', $folder->relative_path);
        $this->assertTrue(Storage::disk('local')->directoryExists($folder->relative_path . '/01_GRAFIK'));
        $this->assertTrue(Storage::disk('local')->directoryExists($folder->relative_path . '/02_BASKIYA_HAZIR'));
        $this->assertTrue(Storage::disk('local')->directoryExists($folder->relative_path . '/03_URETIM_TESLIMAT'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/02_ONAY'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/04_MONTAJ'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/05_URETIM'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/06_TESLIMAT'));
        $this->assertFalse(Storage::disk('local')->directoryExists($folder->relative_path . '/07_ARSIV'));

        $graphicsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $graphicsIndex->assertOk();
        $graphicsIndex->assertSee((string) data_get($workForm->order_snapshot, 'document_number'));
        $graphicsIndex->assertSee($workForm->work_form_number);
        $graphicsIndex->assertSee((string) data_get($workForm->customer_snapshot, 'company_name'));
        $graphicsIndex->assertSee((string) data_get($workForm->product_snapshot, 'product_name'));
        $graphicsIndex->assertSee('1a');
        $graphicsIndex->assertSee('1b');
        $graphicsIndex->assertSee('UV Baskı');
        $graphicsIndex->assertSee('Lazer');
        $graphicsIndex->assertDontSee('unit_price', false);
        $graphicsIndex->assertDontSee('list_price', false);
        $graphicsIndex->assertDontSee('discount_rate', false);
        $graphicsIndex->assertDontSee('grand_total', false);
        $graphicsIndex->assertDontSee('group_code', false);
        $graphicsIndex->assertDontSee('physical_path', false);
        $graphicsIndex->assertDontSee('C:\\', false);
        $graphicsIndex->assertDontSee('/var/', false);
        $graphicsIndex->assertDontSee('storage/app', false);
        $graphicsIndex->assertDontSee('laragon', false);
        $graphicsIndex->assertDontSee('vhosts', false);

        $graphicsShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $graphicsShow->assertOk();
        $graphicsShow->assertSee('Görsel Yükle');
        $graphicsShow->assertSee('İç Kayıt');
        $graphicsShow->assertSee('Müşteriye Açık');
        $graphicsShow->assertSee('Çalışma Klasörü');
        $graphicsShow->assertSee($folder->display_path);
        $graphicsShow->assertDontSee('physical_path', false);
        $graphicsShow->assertDontSee('C:\\', false);
        $graphicsShow->assertDontSee('/var/', false);
        $graphicsShow->assertDontSee('storage/app', false);

        $initialVersion = $workForm->version;

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'internal',
                'note' => 'İç kayıt görseli',
                'redirect_to' => 'admin.graphics.show',
                'file' => UploadedFile::fake()->image('internal-graphic.jpg'),
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $workForm = $workForm->fresh(['attachments', 'activityLogs']);
        $internalAttachment = $workForm->attachments->firstWhere('file_name', 'internal-graphic.jpg');

        $this->assertNotNull($internalAttachment);
        $this->assertSame('internal', $internalAttachment->visibility);
        $this->assertSame($internalAttachment->id, data_get($workForm->graphic_snapshot, 'primary_visual_attachment_id'));
        $this->assertSame('gorsel_eklendi', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame($initialVersion + 1, $workForm->version);
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'graphic_visual_added' && $log->visibility === 'internal'));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'customer_visible',
                'note' => 'Müşteri görseli',
                'redirect_to' => 'admin.graphics.show',
                'file' => UploadedFile::fake()->image('customer-visible-graphic.jpg'),
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $workForm = $workForm->fresh(['attachments', 'activityLogs']);
        $customerVisibleAttachment = $workForm->attachments->firstWhere('file_name', 'customer-visible-graphic.jpg');

        $this->assertNotNull($customerVisibleAttachment);
        $this->assertSame('customer_visible', $customerVisibleAttachment->visibility);
        $this->assertSame($customerVisibleAttachment->id, data_get($workForm->graphic_snapshot, 'primary_visual_attachment_id'));
        $this->assertSame($initialVersion + 2, $workForm->version);
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'graphic_visual_added' && $log->visibility === 'customer_visible'));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.graphics.update-status', $workForm), [
                'status' => 'uretime_hazir',
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $workForm = $workForm->fresh(['attachments', 'activityLogs']);

        $this->assertSame('uretime_hazir', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame('onaylandi', data_get($workForm->graphic_snapshot, 'approval_status'));
        $this->assertSame($initialVersion + 3, $workForm->version);
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'status_updated' && $log->new_status === 'uretime_hazir'));

        $graphicsIndexAfterReady = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $graphicsIndexAfterReady->assertOk();
        $graphicsIndexAfterReady->assertSee('Üretime Hazır');

        $workFormShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $workFormShow->assertOk();
        $workFormShow->assertSee((string) data_get($workForm->order_snapshot, 'document_number'));
        $workFormShow->assertSee($workForm->work_form_number);
        $workFormShow->assertSee((string) data_get($workForm->product_snapshot, 'product_name'));
        $workFormShow->assertSee('UV Baskı');
        $workFormShow->assertSee('Lazer');
        $workFormShow->assertSee('customer-visible-graphic.jpg');
        $workFormShow->assertSee($folder->display_path);
        $workFormShow->assertSee('PDF İndir');
        $workFormShow->assertSee('QR ile müşteri takip ekranı açılır.');
        $workFormShow->assertDontSee('unit_price', false);
        $workFormShow->assertDontSee('list_price', false);
        $workFormShow->assertDontSee('discount_rate', false);
        $workFormShow->assertDontSee('grand_total', false);
        $workFormShow->assertDontSee('group_code', false);
        $workFormShow->assertDontSee('physical_path', false);
        $workFormShow->assertDontSee('C:\\', false);
        $workFormShow->assertDontSee('/var/', false);
        $workFormShow->assertDontSee('storage/app', false);

        $publicTracking = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicTracking->assertOk();
        $publicTracking->assertSee($workForm->work_form_number);
        $publicTracking->assertSee((string) data_get($workForm->order_snapshot, 'document_number'));
        $publicTracking->assertSee((string) data_get($workForm->product_snapshot, 'product_name'));
        $publicTracking->assertSee('customer-visible-graphic.jpg');
        $publicTracking->assertDontSee('internal-graphic.jpg');
        $publicTracking->assertDontSee($folder->display_path);
        $publicTracking->assertDontSee('work-forms/');
        $publicTracking->assertDontSee('unit_price', false);
        $publicTracking->assertDontSee('list_price', false);
        $publicTracking->assertDontSee('discount_rate', false);
        $publicTracking->assertDontSee('grand_total', false);
        $publicTracking->assertDontSee('group_code', false);
        $publicTracking->assertDontSee('physical_path', false);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $internalAttachment->id,
            ]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $firstWorkForm->public_tracking_token,
                'attachment' => $customerVisibleAttachment->id,
            ]))
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $customerVisibleAttachment->id,
            ]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $qrService = app(WorkFormQrCodeService::class);
        $this->assertSame(route('public.work-forms.track', $workForm->public_tracking_token), $qrService->trackingUrl($workForm));
        $this->assertStringContainsString('<svg', $qrService->qrSvg($workForm));

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($workForm->fresh(['tenant', 'attachments', 'activityLogs.attachment', 'systemWorkFolder']));
        $this->assertStringContainsString($workForm->work_form_number, $pdfHtml);
        $this->assertStringContainsString((string) data_get($workForm->order_snapshot, 'document_number'), $pdfHtml);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $pdfHtml);
        $this->assertStringNotContainsString('unit_price', $pdfHtml);
        $this->assertStringNotContainsString('list_price', $pdfHtml);
        $this->assertStringNotContainsString('discount_rate', $pdfHtml);
        $this->assertStringNotContainsString('grand_total', $pdfHtml);
        $this->assertStringNotContainsString('group_code', $pdfHtml);
        $this->assertStringNotContainsString('physical_path', $pdfHtml);
        $this->assertStringNotContainsString('C:\\', $pdfHtml);
        $this->assertStringNotContainsString('/var/', $pdfHtml);
        $this->assertStringNotContainsString('storage/app', $pdfHtml);

        $pdfResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.pdf', $workForm));

        $pdfResponse->assertOk();
        $pdfResponse->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('%PDF', (string) $pdfResponse->getContent());
    }

    private function createConvertedWorkForm(string $productCode, string $notes): OrderItemWorkForm
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
                'notes' => $notes,
                'items' => [[
                    'product_name' => 'Graphic Smoke Test Ürünü',
                    'product_code' => $productCode,
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
                            'print_type' => 'Lazer',
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

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        return OrderItemWorkForm::query()->latest('id')->firstOrFail();
    }
}
