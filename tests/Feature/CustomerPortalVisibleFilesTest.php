<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerPortalVisibleFilesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private Company $company;
    private CompanyContact $contact;
    private CustomerPortalUser $portalUser;
    private User $adminUser;
    private string $tenantHost;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->tenant->forceFill([
            'panel_subdomain' => 'portal-files-demo',
            'slug' => 'portal-files-demo',
            'status' => 'active',
        ])->save();

        $this->company->forceFill(['portal_enabled' => true])->save();

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Dosya Portal Yetkilisi',
            'title' => 'Operasyon',
            'email' => 'portal-file-contact@example.test',
            'phone' => '02123334455',
            'mobile' => '05323334455',
            'is_primary' => true,
        ]);

        $this->portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Portal File User',
            'email' => 'portal-file-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->tenantHost = 'portal-files-demo.prodelya_core.test';

        $this->enablePortalModule();
        $this->enablePortalFeature('customer_login');
        $this->enablePortalFeature('portal_orders');
        $this->enableVisibleFilesFeature();
    }

    public function test_visible_files_index_and_show_are_company_scoped_and_hide_internal_attachments(): void
    {
        ['order' => $order, 'work_form' => $workForm] = $this->createOrder('SP-FILES-001', $this->tenant, $this->company, 'Portal Dosya Ürünü');
        $visibleAttachment = $this->createAttachment($workForm, 'customer_visible', 'delivery_document', 'portal-visible.pdf', 'portal-visible-content', 'application/pdf');
        $imageAttachment = $this->createAttachment($workForm, 'customer_visible', 'delivery_photo', 'portal-visible.jpg', 'portal-visible-image', 'image/jpeg');
        $this->createAttachment($workForm, 'internal', 'delivery_document', 'portal-internal.pdf', 'portal-internal-content', 'application/pdf');

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Ayni Tenant Baska Musteri',
            'short_name' => 'Ayni Tenant Baska Musteri',
            'email' => 'other-files-company@example.test',
            'phone' => '02124445566',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        ['work_form' => $otherCompanyWorkForm] = $this->createOrder('SP-FILES-OTHER-COMPANY', $this->tenant, $otherCompany, 'Gizli Dosya Ürünü');
        $otherCompanyAttachment = $this->createAttachment($otherCompanyWorkForm, 'customer_visible', 'delivery_document', 'other-company.pdf', 'other-company-content', 'application/pdf');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Portal Files Other Tenant',
            'legal_name' => 'Portal Files Other Tenant Ltd.',
            'slug' => 'portal-files-other-tenant',
            'panel_subdomain' => 'portal-files-other-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);
        $otherTenantCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Baska Tenant Musteri',
            'short_name' => 'Baska Tenant Musteri',
            'email' => 'other-files-tenant-company@example.test',
            'phone' => '02127778899',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        ['work_form' => $otherTenantWorkForm] = $this->createOrder('SP-FILES-OTHER-TENANT', $otherTenant, $otherTenantCompany, 'Baska Tenant Dosya');
        $otherTenantAttachment = $this->createAttachment($otherTenantWorkForm, 'customer_visible', 'delivery_document', 'other-tenant.pdf', 'other-tenant-content', 'application/pdf');

        $index = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar'));

        $index->assertOk()
            ->assertSee('Dosyalarım')
            ->assertSee('Sipariş ve grafik süreçlerinde sizinle paylaşılan dosyaları burada görüntüleyebilirsiniz.')
            ->assertSee('portal-visible.pdf')
            ->assertSee('portal-visible.jpg')
            ->assertSee('SP-FILES-001')
            ->assertSee($workForm->work_form_number)
            ->assertDontSee('portal-internal.pdf')
            ->assertDontSee('other-company.pdf')
            ->assertDontSee('other-tenant.pdf')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('storage/app')
            ->assertDontSee('purchase_total')
            ->assertDontSee('payment_amount')
            ->assertDontSee('group_code');

        $showPdf = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $visibleAttachment->id));

        $showPdf->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('portal-visible-content');

        $showImage = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $imageAttachment->id));

        $showImage->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertSee('portal-visible-image');

        $orderDetail = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $order->id));

        $orderDetail->assertOk()
            ->assertSee('Dosyayı Görüntüle')
            ->assertSee('portal-visible.pdf')
            ->assertSee('portal-visible.jpg')
            ->assertDontSee('portal-internal.pdf')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('group_code');

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $otherCompanyAttachment->id))
            ->assertNotFound();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $otherTenantAttachment->id))
            ->assertNotFound();
    }

    public function test_file_feature_guard_and_access_rules_work(): void
    {
        ['work_form' => $workForm] = $this->createOrder('SP-FILES-GUARD', $this->tenant, $this->company, 'Guard Dosya Ürünü');
        $visibleAttachment = $this->createAttachment($workForm, 'customer_visible', 'delivery_document', 'guard-visible.pdf', 'guard-visible-content', 'application/pdf');
        $internalAttachment = $this->createAttachment($workForm, 'internal', 'delivery_document', 'guard-internal.pdf', 'guard-internal-content', 'application/pdf');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar'))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.files.index'))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $internalAttachment->id))
            ->assertNotFound();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'graphics')
            ->where('feature_key', 'customer_visible_files')
            ->update(['is_enabled' => false]);

        $closed = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.files.index'));

        $this->assertContains($closed->getStatusCode(), [403, 404]);

        $order = $workForm->order()->firstOrFail();
        $orderDetailWithoutFiles = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $order->id));

        $orderDetailWithoutFiles->assertOk()
            ->assertDontSee('Dosyayı Görüntüle')
            ->assertDontSee('guard-visible.pdf');

        $this->enableVisibleFilesFeature();

        $visibleAttachment = $visibleAttachment->fresh();
        $this->assertNotNull($visibleAttachment);
        $this->assertTrue(Storage::disk('public')->exists((string) $visibleAttachment->file_path));

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $visibleAttachment->id))
            ->assertOk();
    }

    public function test_public_attachment_route_remains_public_and_independent(): void
    {
        ['work_form' => $workForm] = $this->createOrder('SP-FILES-PUBLIC', $this->tenant, $this->company, 'Public Route Dosya Ürünü');
        $visibleAttachment = $this->createAttachment($workForm, 'customer_visible', 'delivery_document', 'public-independent.pdf', 'public-independent-content', 'application/pdf');

        $this->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $visibleAttachment->id,
            ]))
            ->assertOk();
    }

    private function createOrder(string $documentNumber, TenantAccount $tenant, Company $company, string $productName): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $company->id,
            'status' => 'pending',
            'workflow_status' => 'active',
            'quote_date' => now()->toDateString(),
            'currency' => 'TL',
            'subtotal' => 12500,
            'vat_total' => 2500,
            'grand_total' => 15000,
            'product_total' => 12500,
            'print_total' => 2500,
            'notes' => 'Müşteriye gösterilebilir sipariş notu',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $productName,
            'product_code' => $documentNumber . '-CODE',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Portal dosya ürünü',
            'price_snapshot' => [
                'purchase_total' => 100,
                'supplier_cost' => 2,
                'group_code' => 'SECRET-GROUP',
                'file_path' => '/hidden/path.pdf',
            ],
            'list_price' => 125,
            'discount_rate' => 0,
            'unit_price' => 125,
            'line_total' => 12500,
            'status' => 'active',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'print_unit_price' => 25,
            'print_total' => 2500,
            'note' => 'Müşteriye gösterilebilir baskı notu',
            'status' => 'draft',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source_quote_number' => 'TK-PORTAL-FILES',
            'work_form_number' => 'IF-' . $documentNumber,
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'track-' . str_replace('-', '', strtolower($documentNumber)) . '-token',
            'order_snapshot' => ['document_number' => $documentNumber],
            'customer_snapshot' => ['company_name' => $company->legal_name],
            'product_snapshot' => [
                'product_name' => $productName,
                'product_code' => $documentNumber . '-CODE',
            ],
            'print_snapshot' => [[
                'sequence' => '1a',
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf',
                'print_quantity' => 100,
            ]],
            'graphic_snapshot' => ['public_status_label' => 'Grafik hazır', 'group_code' => 'HIDDEN-GRAPHIC-GROUP'],
            'production_snapshot' => ['public_status_label' => 'Üretim devam ediyor', 'setup_cost' => 999],
            'delivery_snapshot' => ['public_status_label' => 'Teslimat bekliyor', 'file_path' => '/secret/delivery.pdf'],
            'procurement_snapshot' => ['public_status_label' => 'Ürününüz hazırlanıyor', 'supplier_cost' => 100],
            'notes' => 'customer safe work form note',
        ]);

        return [
            'order' => $order,
            'item' => $item,
            'work_form' => $workForm,
        ];
    }

    private function createAttachment(
        OrderItemWorkForm $workForm,
        string $visibility,
        string $attachmentType,
        string $fileName,
        string $content,
        string $mimeType
    ): OrderItemWorkFormAttachment {
        $path = 'work-forms/' . $workForm->tenant_account_id . '/' . $workForm->order_id . '/' . $workForm->id . '/' . $fileName;
        Storage::disk('public')->put($path, $content);

        return OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => $attachmentType,
            'visibility' => $visibility,
            'file_path' => $path,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'disk' => 'public',
            'note' => 'internal_note should stay hidden',
        ]);
    }

    private function enablePortalModule(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($this->tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    private function enablePortalFeature(string $featureKey): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => $featureKey,
            ],
            ['is_enabled' => true]
        );
    }

    private function enableVisibleFilesFeature(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphics',
                'feature_key' => 'customer_visible_files',
            ],
            ['is_enabled' => true]
        );
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenantHost . $path;
    }
}
