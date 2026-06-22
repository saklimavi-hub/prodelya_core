<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Services\WorkFormAttachmentService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraphicVisualPreviewPasteUiTest extends TestCase
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

    public function test_graphic_visual_preview_paste_and_lightbox_ui_renders_safely(): void
    {
        $workForm = $this->createConvertedWorkForm('PREVIEW-PASTE-001');
        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'https://example.test/previews/product-preview.png',
            ]),
        ])->save();

        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');

        $service = app(WorkFormAttachmentService::class);
        $service->attachGraphicVisualToPrintGraphic(
            $graphics['1a'],
            UploadedFile::fake()->image('one-a-preview.jpg'),
            ['visibility' => 'internal', 'note' => '1a preview image'],
            $this->adminUser
        );

        $service->attachGraphicVisualToPrintGraphic(
            $graphics['1b'],
            UploadedFile::fake()->create('manual-proof.pdf', 120, 'application/pdf'),
            ['visibility' => 'customer_visible', 'note' => '1b pdf proof'],
            $this->adminUser
        );

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee('https://example.test/previews/product-preview.png', false);
        $response->assertSee('data-product-image-wrap', false);
        $response->assertSee('one-a-preview.jpg');
        $response->assertSee('manual-proof.pdf');
        $response->assertSee('PDF dosyası');
        $response->assertSee('data-lightbox-modal', false);
        $response->assertSee('data-lightbox-trigger', false);
        $response->assertSee('data-paste-zone', false);
        $response->assertSee('Ctrl + V ile ekran görüntüsü yapıştırın', false);
        $response->assertSee('data-order-item-print-graphic-id="' . $graphics['1a']->id . '"', false);
        $response->assertSee('data-order-item-print-graphic-id="' . $graphics['1b']->id . '"', false);
        $response->assertSee('İç Kayıt');
        $response->assertSee('Müşteriye Açık');
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('price_snapshot', false);

        $html = $response->getContent();
        $oneACardStart = strpos($html, 'id="operation-' . $graphics['1a']->id . '"');
        $oneBCardStart = strpos($html, 'id="operation-' . $graphics['1b']->id . '"');

        $this->assertNotFalse($oneACardStart);
        $this->assertNotFalse($oneBCardStart);

        $oneACardHtml = substr($html, $oneACardStart, max(0, $oneBCardStart - $oneACardStart));
        $oneBCardHtml = substr($html, $oneBCardStart);

        $this->assertStringContainsString('one-a-preview.jpg', $oneACardHtml);
        $this->assertStringNotContainsString('manual-proof.pdf', $oneACardHtml);
        $this->assertStringContainsString('manual-proof.pdf', $oneBCardHtml);
        $this->assertStringNotContainsString('alt="manual-proof.pdf"', $oneBCardHtml);

        $noImageWorkForm = $this->createConvertedWorkForm('PREVIEW-PASTE-002');

        $noImageResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $noImageWorkForm));

        $noImageResponse->assertOk();
        $noImageResponse->assertSee('Ürün Görseli');
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
                'notes' => 'Graphic visual preview payload',
                'items' => [[
                    'product_name' => 'Graphic Visual Preview Ürünü',
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
