<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraphicPerPrintUiTest extends TestCase
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

    public function test_graphic_ui_binds_to_per_print_operations_and_keeps_uploads_separate(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');

        $this->assertCount(4, $graphics);

        $indexResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $indexResponse->assertOk();
        $indexResponse->assertSee('Grafik İşleri');
        $indexResponse->assertSee($workForm->work_form_number);
        $indexResponse->assertSee('1a');
        $indexResponse->assertSee('1b');
        $indexResponse->assertSee('1c');
        $indexResponse->assertSee('1d');
        $indexResponse->assertSee('Lazer');
        $indexResponse->assertSee('UV Baskı');
        $indexResponse->assertSee('Serigrafi');
        $indexResponse->assertSee('İsim lazer');
        $indexResponse->assertSee('Tek taraf');
        $indexResponse->assertSee('Gövde');
        $indexResponse->assertSee('Kutu');
        $indexResponse->assertSee('Görsel Yükle');
        $indexResponse->assertDontSee('Düzenle');
        $indexResponse->assertDontSee('>Üretime Hazır İşaretle<', false);

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $showResponse->assertOk();
        $showResponse->assertSee('Grafik Operasyonları');
        $showResponse->assertSee('1a / Lazer / İsim lazer / 10 adet');
        $showResponse->assertSee('1b / UV Baskı / Tek taraf / 10 adet');
        $showResponse->assertSee('1c / Serigrafi / Gövde / 10 adet');
        $showResponse->assertSee('1d / Lazer / Kutu / 10 adet');
        $showResponse->assertSee('Görsel Yükle');
        $showResponse->assertSee('graphic-action-step-tabs', false);
        $showResponse->assertDontSee('group_code', false);
        $showResponse->assertDontSee('file_path', false);
        $showResponse->assertDontSee('physical_path', false);
        $showResponse->assertDontSee('grand_total', false);
        $showResponse->assertDontSee('KDV');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'internal',
                'note' => '1a görsel notu',
                'redirect_to' => 'admin.graphics.show',
                'order_item_print_graphic_id' => $graphics['1a']->id,
                'file' => UploadedFile::fake()->image('one-a.jpg'),
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'customer_visible',
                'note' => '1b görsel notu',
                'redirect_to' => 'admin.graphics.show',
                'order_item_print_graphic_id' => $graphics['1b']->id,
                'file' => UploadedFile::fake()->image('one-b.jpg'),
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with('latestAttachment')
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');

        $this->assertSame('visual_uploaded', $graphics['1a']->status);
        $this->assertSame('visual_uploaded', $graphics['1b']->status);
        $this->assertSame('1a görsel notu', $graphics['1a']->graphic_note);
        $this->assertSame('1b görsel notu', $graphics['1b']->graphic_note);
        $this->assertNotNull($graphics['1a']->latest_attachment_id);
        $this->assertNotNull($graphics['1b']->latest_attachment_id);
        $this->assertNull($graphics['1c']->latest_attachment_id);
        $this->assertNull($graphics['1d']->latest_attachment_id);
        $this->assertSame('one-a.jpg', $graphics['1a']->latestAttachment->file_name);
        $this->assertSame('one-b.jpg', $graphics['1b']->latestAttachment->file_name);

        $afterUploadResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $afterUploadResponse->assertOk();
        $afterUploadResponse->assertSee('one-a.jpg');
        $afterUploadResponse->assertSee('graphic-operation-tabs', false);
        $afterUploadResponse->assertSee('1c / Serigrafi / Gövde / 10 adet');

        $afterUploadSecondOperationResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm) . '?operation=' . $graphics['1b']->id);

        $afterUploadSecondOperationResponse->assertOk();
        $afterUploadSecondOperationResponse->assertSee('one-b.jpg');
        $afterUploadSecondOperationResponse->assertSee('graphic-step-panel-approval', false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.graphics.operations.update-status', [
                'workForm' => $workForm,
                'graphic' => $graphics['1a'],
            ]), [
                'action' => 'approved',
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.graphics.operations.update-status', [
                'workForm' => $workForm,
                'graphic' => $graphics['1b'],
            ]), [
                'action' => 'revision_requested',
                'note' => 'Yalnız 1b revize olacak.',
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $graphics['1a']->refresh();
        $graphics['1b']->refresh();

        $this->assertSame('approved', $graphics['1a']->status);
        $this->assertSame('revision_requested', $graphics['1b']->status);
        $this->assertSame('Yalnız 1b revize olacak.', $graphics['1b']->customer_note);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.graphics.show', $workForm))
            ->patch(route('admin.graphics.operations.update-status', [
                'workForm' => $workForm,
                'graphic' => $graphics['1b'],
            ]), [
                'action' => 'production_ready',
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm))
            ->assertSessionHasErrors('note');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.graphics.operations.update-status', [
                'workForm' => $workForm,
                'graphic' => $graphics['1a'],
            ]), [
                'action' => 'production_ready',
            ])
            ->assertRedirect(route('admin.graphics.show', $workForm));

        $graphics['1a']->refresh();
        $this->assertSame('production_ready', $graphics['1a']->status);
        $this->assertNotNull($graphics['1a']->production_ready_at);

        $otherWorkForm = $this->createConvertedWorkForm('PERPRINT-SECONDARY');
        $otherGraphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $otherWorkForm->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.graphics.operations.update-status', [
                'workForm' => $workForm,
                'graphic' => $otherGraphic,
            ]), [
                'action' => 'approved',
            ])
            ->assertForbidden();
    }

    private function createConvertedWorkForm(string $productCode = 'PERPRINT-UI-001'): OrderItemWorkForm
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
                'notes' => 'Graphic per-print UI payload',
                'items' => [[
                    'product_name' => 'Per-print Grafik UI Ürünü',
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
                        [
                            'print_type' => 'Serigrafi',
                            'print_option' => 'Gövde',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '10',
                            'print_unit_price' => '10',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Kutu',
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
