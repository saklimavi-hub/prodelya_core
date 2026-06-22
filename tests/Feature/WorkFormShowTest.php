<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Services\WorkFormQrCodeService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkFormShowTest extends TestCase
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

    public function test_admin_work_form_show_route_renders_runtime_snapshot_view(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $trackingUrl = app(WorkFormQrCodeService::class)->trackingUrl($workForm);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $response->assertOk();
        $response->assertSee($workForm->work_form_number);
        $response->assertSee((string) data_get($workForm->order_snapshot, 'document_number'));
        $response->assertSee((string) data_get($workForm->product_snapshot, 'product_name'));
        $response->assertSee('UV Baskı');
        $response->assertSee('Lazer');
        $response->assertSee('Yazdır');
        $response->assertSee('Telefondan Fotoğraf Ekle');
        $response->assertSee('Teslimat Fotoğrafı Ekle');
        $response->assertSee('İş Formu Takip QR Kodu');
        $response->assertSee('Tedarik');
        $response->assertSee('Tedarik Durumu');
        $response->assertSee('Kaynak Tipi');
        $response->assertSee('Tedarik Bekliyor');
        $response->assertSee('Ürününüz hazırlanıyor');
        $response->assertSee('Üretim Durumu');
        $response->assertSee('Üretim Tipi');
        $response->assertSee('Planlanan Miktar');
        $response->assertSee('Klişe / Kalıp Durumu');
        $response->assertSee('Müşteriye Görünen Durum');
        $response->assertSee('Üretim bekliyor');
        $response->assertSee('<svg', false);
        $response->assertSee($trackingUrl, false);
        $response->assertSee('QR ile müşteri takip ekranı açılır.');
        $response->assertDontSee('file_path', false);
    }

    public function test_tenant_mismatch_is_forbidden(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant',
            'panel_subdomain' => 'other-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $workForm->tenant_account_id = $otherTenant->id;
        $workForm->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm))
            ->assertForbidden();
    }

    public function test_show_view_does_not_render_financial_labels_or_values(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $response->assertOk();
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('line_total', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('print_total', false);
        $response->assertDontSee('KDV');
        $response->assertDontSee('İskonto');
        $response->assertDontSee('Birim Fiyat');
        $response->assertDontSee('Genel Toplam');
        $response->assertDontSee('purchase_cost', false);
        $response->assertDontSee('alış maliyeti');
        $response->assertDontSee('üretim maliyeti');
    }

    public function test_attachment_sections_render_files_and_placeholder_states(): void
    {
        $workForm = $this->createConvertedWorkForm();

        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'graphic_visual',
            'file_path' => 'work-forms/graphic/logo-approval.png',
            'file_name' => 'logo-approval.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Grafik eklendi',
        ]);

        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'production_photo',
            'file_path' => 'work-forms/production/uretim-1.jpg',
            'file_name' => 'uretim-1.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
        ]);

        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_document',
            'file_path' => 'work-forms/delivery/irsaliye.pdf',
            'file_name' => 'irsaliye.pdf',
            'mime_type' => 'application/pdf',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee('logo-approval.png');
        $response->assertSee('uretim-1.jpg');
        $response->assertSee('irsaliye.pdf');
        $response->assertDontSee('Grafik görseli henüz eklenmedi');
        $response->assertDontSee('Üretim fotoğrafı henüz eklenmedi');
        $response->assertDontSee('Teslimat belgesi/fotoğrafı henüz eklenmedi');
    }

    public function test_placeholder_texts_render_when_attachments_are_missing(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $response->assertOk();
        $response->assertSee('Grafik görseli henüz eklenmedi');
        $response->assertSee('Üretim fotoğrafı henüz eklenmedi');
        $response->assertSee('Teslimat belgesi/fotoğrafı henüz eklenmedi');
    }

    private function createConvertedWorkForm(): OrderItemWorkForm
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
                'notes' => 'Work form show payload',
                'items' => [
                    [
                        'product_name' => 'Smoke Test Kalem',
                        'product_code' => 'SMOKE-001',
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
