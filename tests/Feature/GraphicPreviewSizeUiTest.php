<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraphicPreviewSizeUiTest extends TestCase
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

    public function test_graphic_preview_size_markup_renders_for_product_and_per_print_visuals(): void
    {
        $workForm = $this->createConvertedWorkForm('PREVIEW-SIZE-001');
        $workForm->forceFill([
            'product_snapshot' => array_merge($workForm->product_snapshot ?? [], [
                'image_url' => 'https://example.test/previews/size-product.png',
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
            UploadedFile::fake()->image('size-one-a.jpg'),
            ['visibility' => 'internal', 'note' => '1a size preview'],
            $this->adminUser
        );

        $service->attachGraphicVisualToPrintGraphic(
            $graphics['1b'],
            UploadedFile::fake()->create('size-proof.pdf', 120, 'application/pdf'),
            ['visibility' => 'customer_visible', 'note' => '1b size proof'],
            $this->adminUser
        );

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $showResponse->assertOk();
        $showResponse->assertSee('gg-preview-frame--summary', false);
        $showResponse->assertSee('gg-main-preview-frame', false);
        $showResponse->assertSee('graphic-preview-image', false);
        $showResponse->assertSee('graphic-operation-tabs', false);
        $showResponse->assertSee('graphic-action-step-tabs', false);
        $showResponse->assertSee('pd-allow-large', false);
        $showResponse->assertSee('data-lightbox-modal', false);
        $showResponse->assertSee('Büyük Önizleme');
        $showResponse->assertSee('size-one-a.jpg');
        $showResponse->assertSee('1a');
        $showResponse->assertSee('1b');
        $showResponse->assertDontSee('alt="size-proof.pdf"', false);
        $showResponse->assertDontSee('file_path', false);
        $showResponse->assertDontSee('physical_path', false);
        $showResponse->assertDontSee('group_code', false);
        $showResponse->assertDontSee('price_snapshot', false);

        $operationBResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()) . '?operation=' . $graphics['1b']->id);

        $operationBResponse->assertOk();
        $operationBResponse->assertSee('size-proof.pdf');
        $operationBResponse->assertDontSee('alt="size-proof.pdf"', false);
        $operationBResponse->assertSee('gg-operation-tab is-active', false);

        $indexResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $indexResponse->assertOk();
        $indexResponse->assertSee('gg-list-thumb-frame', false);
        $indexResponse->assertSee('graphic-index-product-thumb', false);
        $indexResponse->assertSee('graphic-index-product-image', false);
        $indexResponse->assertSee('graphic-index-preview-thumb', false);
        $indexResponse->assertSee('graphic-index-preview-image', false);
        $indexResponse->assertSee('pd-allow-large', false);
        $indexResponse->assertSee('size-proof.pdf');
        $indexResponse->assertDontSee('file_path', false);
        $indexResponse->assertDontSee('physical_path', false);
        $indexResponse->assertDontSee('group_code', false);
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
                'notes' => 'Graphic preview size payload',
                'items' => [[
                    'product_name' => 'Graphic Preview Size Ürünü',
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
