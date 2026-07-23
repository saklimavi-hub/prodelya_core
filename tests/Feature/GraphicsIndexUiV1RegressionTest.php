<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphicsIndexUiV1RegressionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_graphics_index_shows_grouped_order_cards_with_exact_print_identity_and_no_leaks(): void
    {
        $workForm = $this->createConvertedWorkForm('GRAPHICS-V1-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $response->assertOk();
        $response->assertSee('pd-ui-v1-graphics', false);
        $response->assertSee('pd-graphic-order-group', false);
        $response->assertSee('Grafik İşleri');
        $response->assertSee($workForm->order_snapshot['document_number'] ?? '');
        $response->assertSee('1a');
        $response->assertSee('1b');
        $response->assertSee('Exact SKU: GRAPHICS-V1-001');
        $response->assertSee('İş Formu: ' . $workForm->work_form_number);
        $response->assertSee('Görsel Bekliyor');
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee($workForm->public_tracking_token ?? 'token-should-not-leak', false);
    }

    public function test_graphics_index_default_action_waiting_excludes_fully_completed_groups(): void
    {
        $workForm = $this->createConvertedWorkForm('GRAPHICS-V1-READY');
        $graphics = $workForm->printGraphics()->orderBy('sequence_code')->get()->keyBy('sequence_code');

        $graphics['1a']->update(['status' => OrderItemPrintGraphic::STATUS_PRODUCTION_READY]);
        $graphics['1b']->update(['status' => OrderItemPrintGraphic::STATUS_WAITING_VISUAL]);

        $defaultResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $defaultResponse->assertOk();
        $defaultResponse->assertSee($workForm->order_snapshot['document_number'] ?? '');
        $defaultResponse->assertSee('Görsel Yükle');
        $defaultResponse->assertSee('Üretime Hazır');
        $defaultResponse->assertDontSee('Arşiv kaydı');

        $readyResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index', ['queue' => 'production_ready']));

        $readyResponse->assertOk();
        $readyResponse->assertSee($workForm->order_snapshot['document_number'] ?? '');
        $readyResponse->assertSee('Üretime Hazır');
        $readyResponse->assertSee('Görsel Yükle');
    }

    public function test_graphics_index_shows_attachment_visibility_labels_without_path_leak(): void
    {
        $workForm = $this->createConvertedWorkForm('GRAPHICS-V1-VIS');
        $graphics = $workForm->printGraphics()->orderBy('sequence_code')->get()->keyBy('sequence_code');

        $internal = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphics['1a']->id,
            'order_item_print_id' => $graphics['1a']->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'internal',
            'file_path' => 'work-forms/mock/internal-1a.png',
            'file_name' => 'internal-1a.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
        ]);

        $customerVisible = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphics['1b']->id,
            'order_item_print_id' => $graphics['1b']->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/mock/customer-1b.png',
            'file_name' => 'customer-1b.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
        ]);

        $graphics['1a']->update([
            'status' => OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED,
            'latest_attachment_id' => $internal->id,
        ]);
        $graphics['1b']->update([
            'status' => OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED,
            'latest_attachment_id' => $customerVisible->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index', ['queue' => 'control_waiting']));

        $response->assertOk();
        $response->assertSee('Yalnız İç Kullanım');
        $response->assertSee('Müşteriye Açık');
        $response->assertSee('1 görsel');
        $response->assertDontSee('work-forms/mock/internal-1a.png', false);
        $response->assertDontSee('work-forms/mock/customer-1b.png', false);
    }

    public function test_graphics_index_hides_rows_from_other_tenant_context(): void
    {
        $visibleWorkForm = $this->createConvertedWorkForm('GRAPHICS-V1-TENANT-A');
        $hiddenWorkForm = $this->createConvertedWorkForm('GRAPHICS-V1-TENANT-B');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Graphics Tenant',
            'legal_name' => 'Other Graphics Tenant Ltd.',
            'slug' => 'other-graphics-tenant',
            'panel_subdomain' => 'other-graphics-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $hiddenWorkForm->update(['tenant_account_id' => $otherTenant->id]);
        $hiddenWorkForm->printGraphics()->update(['tenant_account_id' => $otherTenant->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $response->assertOk();
        $response->assertSee($visibleWorkForm->order_snapshot['document_number'] ?? '');
        $response->assertDontSee($hiddenWorkForm->order_snapshot['document_number'] ?? '');
    }

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-20',
                'valid_until' => '2026-07-27',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Graphics index UI v1 regression payload',
                'items' => [[
                    'product_name' => 'Grafik Liste Test Ürünü',
                    'product_code' => $productCode,
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'list_price' => '42',
                    'discount_rate' => '0',
                    'unit_price' => '42',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '10',
                            'print_unit_price' => '5',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Gövde',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '10',
                            'print_unit_price' => '5',
                        ],
                    ],
                ]],
            ])->assertRedirect();

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
