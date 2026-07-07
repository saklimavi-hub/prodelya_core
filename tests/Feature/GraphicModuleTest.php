<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraphicModuleTest extends TestCase
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

    public function test_graphics_index_renders_real_work_form_rows_without_financial_data(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $response->assertOk();
        $response->assertSee('Grafik Yönetimi');
        $response->assertSee($workForm->work_form_number);
        $response->assertSee((string) data_get($workForm->order_snapshot, 'document_number'));
        $response->assertSee((string) data_get($workForm->customer_snapshot, 'company_name'));
        $response->assertSee((string) data_get($workForm->product_snapshot, 'product_name'));
        $response->assertSee('1a');
        $response->assertSee('1b');
        $response->assertSee('UV Baskı');
        $response->assertSee('Lazer');
        $response->assertSee('Düzenle');
        $response->assertSee(route('admin.graphics.show', $workForm), false);
        $response->assertDontSee(route('admin.work-forms.show', $workForm), false);
        $response->assertDontSee('>Görsel Ekle<', false);
        $response->assertDontSee('>Üretime Hazır İşaretle<', false);
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('group_code', false);
    }

    public function test_graphics_show_renders_snapshot_upload_form_and_public_links(): void
    {
        $workForm = $this->createConvertedWorkForm();

        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/mockup/customer-visible.png',
            'file_name' => 'customer-visible.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Müşteriye açık görsel',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee((string) data_get($workForm->order_snapshot, 'document_number'));
        $response->assertSee((string) data_get($workForm->product_snapshot, 'product_name'));
        $response->assertSee('Grafik Operasyonları');
        $response->assertSee('Görsel Yükle');
        $response->assertSee('admin.graphics.show');
        $response->assertSee('İş Özeti');
        $response->assertSee(route('admin.work-forms.show', $workForm), false);
        $response->assertSee(route('public.work-forms.track', $workForm->public_tracking_token), false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('KDV');
        $response->assertDontSee('group_code', false);
    }

    public function test_tenant_mismatch_is_forbidden_for_graphics_show(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-graphics',
            'panel_subdomain' => 'other-tenant-graphics',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $workForm->tenant_account_id = $otherTenant->id;
        $workForm->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.show', $workForm))
            ->assertForbidden();
    }

    public function test_graphic_upload_from_graphics_screen_updates_snapshot_version_and_log(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $initialVersion = $workForm->version;

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'customer_visible',
                'note' => 'Grafik ekranından yükleme',
                'redirect_to' => 'admin.graphics.show',
                'file' => UploadedFile::fake()->image('graphic-screen.webp'),
            ]);

        $response->assertRedirect(route('admin.graphics.show', $workForm));

        $workForm = $workForm->fresh(['attachments', 'activityLogs']);
        $attachment = $workForm->attachments->firstOrFail();

        $this->assertSame('graphic_visual', $attachment->attachment_type);
        $this->assertSame('customer_visible', $attachment->visibility);
        $this->assertSame($attachment->id, data_get($workForm->graphic_snapshot, 'primary_visual_attachment_id'));
        $this->assertSame('gorsel_eklendi', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame($initialVersion + 1, $workForm->version);
        $this->assertTrue($workForm->activityLogs->contains('action_type', 'graphic_visual_added'));
    }

    public function test_mark_ready_for_production_updates_graphic_status_and_logs_activity(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $initialVersion = $workForm->version;

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.graphics.update-status', $workForm), [
                'status' => 'uretime_hazir',
            ]);

        $response->assertRedirect(route('admin.graphics.show', $workForm));

        $workForm = $workForm->fresh(['activityLogs']);

        $this->assertSame('uretime_hazir', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame('onaylandi', data_get($workForm->graphic_snapshot, 'approval_status'));
        $this->assertSame($initialVersion + 1, $workForm->version);

        $log = $workForm->activityLogs()->latest('id')->firstOrFail();
        $this->assertSame('status_updated', $log->action_type);
        $this->assertSame('uretime_hazir', $log->new_status);
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
                'notes' => 'Graphic module payload',
                'items' => [
                    [
                        'product_name' => 'Grafik Test Kalemi',
                        'product_code' => 'GRAFIK-001',
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
