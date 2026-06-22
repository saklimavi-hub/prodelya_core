<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkFormAttachmentUploadTest extends TestCase
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

    public function test_graphic_visual_upload_creates_attachment_updates_snapshot_and_log(): void
    {
        $workForm = $this->createConvertedWorkForm();
        $initialVersion = $workForm->version;

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'customer_visible',
                'note' => 'Onay görseli',
                'file' => UploadedFile::fake()->image('graphic.webp'),
            ]);

        $response->assertRedirect(route('admin.work-forms.show', $workForm));

        $workForm = $workForm->fresh(['attachments', 'activityLogs']);
        $attachment = $workForm->attachments->firstOrFail();

        $this->assertSame('graphic_visual', $attachment->attachment_type);
        $this->assertSame('customer_visible', $attachment->visibility);
        $this->assertNotNull(data_get($workForm->graphic_snapshot, 'primary_visual_attachment_id'));
        $this->assertNotNull(data_get($workForm->graphic_snapshot, 'updated_at'));
        $this->assertSame($initialVersion + 1, $workForm->version);
        $this->assertSame('graphic_visual_added', $workForm->activityLogs->firstWhere('attachment_id', $attachment->id)?->action_type);
        $this->assertSame('customer_visible', $workForm->activityLogs->firstWhere('attachment_id', $attachment->id)?->visibility);
        Storage::disk('public')->assertExists($attachment->file_path);
    }

    public function test_production_and_delivery_uploads_support_mobile_friendly_files(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'note' => 'Üretimden çıktı',
                'file' => UploadedFile::fake()->image('production.jpg'),
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_photo',
                'note' => 'Teslim anı',
                'file' => UploadedFile::fake()->image('delivery.png'),
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_document',
                'note' => 'İrsaliye',
                'file' => UploadedFile::fake()->create('delivery-note.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $workForm = $workForm->fresh(['attachments', 'activityLogs']);

        $this->assertSame(3, $workForm->attachments()->count());
        $this->assertSame(1, (int) data_get($workForm->production_snapshot, 'photo_count'));
        $this->assertSame(1, (int) data_get($workForm->delivery_snapshot, 'photo_count'));
        $this->assertSame(1, (int) data_get($workForm->delivery_snapshot, 'document_count'));
        $this->assertNotNull(data_get($workForm->production_snapshot, 'updated_at'));
        $this->assertNotNull(data_get($workForm->delivery_snapshot, 'updated_at'));
        $this->assertTrue($workForm->activityLogs->contains('action_type', 'production_photo_added'));
        $this->assertTrue($workForm->activityLogs->contains('action_type', 'delivery_photo_added'));
        $this->assertTrue($workForm->activityLogs->contains('action_type', 'delivery_document_added'));
    }

    public function test_visibility_defaults_to_internal_and_invalid_uploads_are_rejected(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'file' => UploadedFile::fake()->image('internal.jpg'),
            ])
            ->assertRedirect();

        $attachment = $workForm->fresh()->attachments()->latest('id')->firstOrFail();
        $this->assertSame('internal', $attachment->visibility);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'other',
                'file' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'delivery_document',
                'file' => UploadedFile::fake()->create('too-large.pdf', 11000, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_tenant_scope_is_enforced_for_attachment_uploads(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant-upload',
            'panel_subdomain' => 'other-tenant-upload',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $workForm->tenant_account_id = $otherTenant->id;
        $workForm->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'file' => UploadedFile::fake()->image('forbidden.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_work_form_show_renders_upload_forms_and_attachment_cards_without_financial_data(): void
    {
        $workForm = $this->createConvertedWorkForm();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'production_photo',
                'note' => 'Thumbnail testi',
                'file' => UploadedFile::fake()->image('thumb.jpg'),
            ])
            ->assertRedirect();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm->fresh()));

        $response->assertOk();
        $response->assertSee('Grafik Görseli Ekle');
        $response->assertSee('Telefondan Fotoğraf Ekle');
        $response->assertSee('Teslimat Fotoğrafı / Belgesi Ekle');
        $response->assertSee('wf-upload-only', false);
        $response->assertSee('capture="environment"', false);
        $response->assertSee('thumb.jpg');
        $response->assertSee('İç Kayıt');
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('print_total', false);
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
                'notes' => 'Work form upload payload',
                'items' => [
                    [
                        'product_name' => 'Upload Test Ürün',
                        'product_code' => 'UPLOAD-001',
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
