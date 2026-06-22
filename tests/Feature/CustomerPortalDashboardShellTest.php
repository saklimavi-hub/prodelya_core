<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalDashboardShellTest extends TestCase
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

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->tenant->forceFill([
            'panel_subdomain' => 'portal-dashboard-demo',
            'slug' => 'portal-dashboard-demo',
            'status' => 'active',
        ])->save();

        $this->company->forceFill(['portal_enabled' => true])->save();

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Dashboard Yetkilisi',
            'title' => 'Operasyon',
            'email' => 'portal-dashboard-contact@example.test',
            'phone' => '02121111111',
            'mobile' => '05321111111',
            'is_primary' => true,
        ]);

        $this->portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Portal Dashboard User',
            'email' => 'portal-dashboard-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->tenantHost = 'portal-dashboard-demo.prodelya_core.test';

        $this->enablePortalModule();
        $this->enablePortalFeature('customer_login');
        $this->enablePortalFeature('portal_quotes');
        $this->enablePortalFeature('portal_orders');
    }

    public function test_dashboard_shell_is_company_scoped_and_hides_sensitive_data(): void
    {
        $this->seedDashboardFixtures();

        $response = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'));

        $response->assertOk()
            ->assertSee('Merhaba')
            ->assertSee($this->portalUser->safeDisplayName())
            ->assertSee($this->company->legal_name)
            ->assertSee('Tekliflerim')
            ->assertSee('Onay Bekleyen Teklifler')
            ->assertSee('Siparişlerim')
            ->assertSee('Dosyalarım')
            ->assertSee('2')
            ->assertSee('1')
            ->assertSee('Müşteri Takip Ekranı')
            ->assertSee('TK-PORTAL-OPEN-001')
            ->assertSee('SP-PORTAL-ACTIVE-001')
            ->assertDontSee('sonraki fazda')
            ->assertDontSee('TK-OTHER-COMPANY-001')
            ->assertDontSee('SP-OTHER-COMPANY-001')
            ->assertDontSee('TK-OTHER-TENANT-001')
            ->assertDontSee('SP-OTHER-TENANT-001')
            ->assertDontSee('12.500,00')
            ->assertDontSee('Finance Warning')
            ->assertDontSee('odeme_bekliyor')
            ->assertDontSee('balance_due')
            ->assertDontSee('payment_amount')
            ->assertDontSee('purchase_total')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('subcontractor_cost')
            ->assertDontSee('group_code')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('storage/app')
            ->assertDontSee('internal_note')
            ->assertDontSee('notification_logs')
            ->assertDontSee('current_account_transactions');
    }

    public function test_guest_and_admin_web_guard_cannot_use_customer_dashboard(): void
    {
        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertRedirect(route('customer.login'));
    }

    public function test_feature_visibility_and_access_guards_work_on_dashboard(): void
    {
        $this->seedDashboardFixtures();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->where('feature_key', 'portal_quotes')
            ->update(['is_enabled' => false]);

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertOk()
            ->assertSee('Teklif görünümü bu tenant için aktif değil.');

        $this->enablePortalFeature('portal_quotes');

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->where('feature_key', 'portal_orders')
            ->update(['is_enabled' => false]);

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertOk()
            ->assertSee('Sipariş görünümü bu tenant için aktif değil.');

        $this->enablePortalFeature('portal_orders');
        $this->company->forceFill(['portal_enabled' => false])->save();

        $this->actingAs($this->portalUser->fresh(), 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertRedirect(route('customer.login'));

        $this->company->forceFill(['portal_enabled' => true])->save();
        $this->portalUser->forceFill(['status' => CustomerPortalUser::STATUS_SUSPENDED])->save();

        $this->actingAs($this->portalUser->fresh(), 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertRedirect(route('customer.login'));

        $this->portalUser->forceFill(['status' => CustomerPortalUser::STATUS_ACTIVE])->save();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->whereNull('feature_key')
            ->update(['is_enabled' => false]);

        $this->actingAs($this->portalUser->fresh(), 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertRedirect(route('customer.login'));
    }

    public function test_public_tracking_stays_public_while_dashboard_uses_safe_helper_links(): void
    {
        $workForm = $this->createWorkForm(
            $this->createOrder('SP-PORTAL-PUBLIC-001', 'order', $this->tenant, $this->company->id, 'pending'),
            'IF-PORTAL-PUBLIC-001',
            'portal-dashboard-public-token'
        );

        $response = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'));

        $response->assertOk()
            ->assertSee('/musteri-portal/siparisler/' . $workForm->order_id . '/takip/' . $workForm->id, false)
            ->assertDontSee($workForm->public_tracking_token);

        $this->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token))
            ->assertOk();
    }

    private function seedDashboardFixtures(): void
    {
        $openQuote = $this->createOrder(
            'TK-PORTAL-OPEN-001',
            'quote',
            $this->tenant,
            $this->company->id,
            'draft',
            Order::CUSTOMER_APPROVAL_NOT_SENT,
            'Açık Teklif Ürünü'
        );
        $pendingQuote = $this->createOrder(
            'TK-PORTAL-PENDING-001',
            'quote',
            $this->tenant,
            $this->company->id,
            'draft',
            Order::CUSTOMER_APPROVAL_WAITING,
            'Onay Bekleyen Teklif Ürünü'
        );
        $this->createOrder(
            'SP-PORTAL-ACTIVE-001',
            'order',
            $this->tenant,
            $this->company->id,
            'pending',
            null,
            'Aktif Sipariş Ürünü'
        );
        $completedOrder = $this->createOrder(
            'SP-PORTAL-COMPLETE-001',
            'order',
            $this->tenant,
            $this->company->id,
            'completed',
            null,
            'Tamamlanan Sipariş Ürünü'
        );

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Tenant İçi Başka Company',
            'short_name' => 'Tenant İçi Başka Company',
            'email' => 'other-company@example.test',
            'phone' => '02122223344',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        $this->createOrder('TK-OTHER-COMPANY-001', 'quote', $this->tenant, $otherCompany->id, 'draft', Order::CUSTOMER_APPROVAL_WAITING, 'Gizli Teklif');
        $this->createOrder('SP-OTHER-COMPANY-001', 'order', $this->tenant, $otherCompany->id, 'pending', null, 'Gizli Sipariş');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Portal Dashboard Other Tenant',
            'legal_name' => 'Portal Dashboard Other Tenant Ltd.',
            'slug' => 'portal-dashboard-other-tenant',
            'panel_subdomain' => 'portal-dashboard-other-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $otherTenantCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Başka Tenant Company',
            'short_name' => 'Başka Tenant Company',
            'email' => 'other-tenant-company@example.test',
            'phone' => '02125554433',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        $this->createOrder('TK-OTHER-TENANT-001', 'quote', $otherTenant, $otherTenantCompany->id, 'draft', Order::CUSTOMER_APPROVAL_WAITING, 'Başka Tenant Teklif');
        $this->createOrder('SP-OTHER-TENANT-001', 'order', $otherTenant, $otherTenantCompany->id, 'pending', null, 'Başka Tenant Sipariş');

        $visibleWorkForm = $this->createWorkForm($openQuote, 'IF-PORTAL-001', 'portal-dashboard-track-001');
        $this->createWorkForm($completedOrder, 'IF-PORTAL-002', 'portal-dashboard-track-002', 'Teslim edildi');

        $this->createAttachment($visibleWorkForm, 'customer_visible', 'dashboard-visible.pdf');
        $this->createAttachment($visibleWorkForm, 'internal', 'dashboard-internal.pdf');

        $foreignWorkForm = $this->createWorkForm(
            $this->createOrder('SP-OTHER-COMPANY-WF-001', 'order', $this->tenant, $otherCompany->id, 'pending', null, 'Başka Company WF'),
            'IF-OTHER-COMPANY-001',
            'portal-dashboard-track-foreign'
        );
        $this->createAttachment($foreignWorkForm, 'customer_visible', 'dashboard-foreign.pdf');

        $this->assertNotNull($pendingQuote->id);
    }

    private function createOrder(
        string $documentNumber,
        string $documentType,
        TenantAccount $tenant,
        int $companyId,
        string $status,
        ?string $customerApprovalStatus = null,
        string $productName = 'Portal Ürünü'
    ): Order {
        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'customer_company_id' => $companyId,
            'status' => $status,
            'workflow_status' => $documentType === 'quote' ? 'draft' : 'order_active',
            'customer_approval_status' => $customerApprovalStatus,
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'currency' => 'TL',
            'subtotal' => 12500,
            'vat_total' => 2500,
            'grand_total' => 15000,
            'product_total' => 12500,
            'print_total' => 0,
            'notes' => 'internal_note should stay hidden',
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $productName,
            'product_code' => $documentNumber . '-CODE',
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Dashboard güvenli ürün özeti',
            'price_snapshot' => [
                'purchase_total' => 1,
                'supplier_cost' => 2,
                'group_code' => 'SECRET-GROUP',
            ],
            'list_price' => 1250,
            'discount_rate' => 0,
            'unit_price' => 1250,
            'line_total' => 12500,
            'status' => 'active',
        ]);

        return $order;
    }

    private function createWorkForm(
        Order $order,
        string $workFormNumber,
        string $token,
        string $deliveryLabel = 'Teslimata hazırlanıyor'
    ): OrderItemWorkForm {
        $orderItemId = (int) $order->items()->value('id');

        return OrderItemWorkForm::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $orderItemId,
            'source_quote_id' => $order->source_quote_id,
            'source_quote_number' => $order->source_quote_number,
            'work_form_number' => $workFormNumber,
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => $token,
            'order_snapshot' => ['document_number' => $order->document_number],
            'customer_snapshot' => ['company_name' => $order->customer?->legal_name],
            'product_snapshot' => ['product_name' => 'Portal Dashboard Ürünü'],
            'print_snapshot' => [],
            'graphic_snapshot' => ['public_status_label' => 'Grafik bekliyor'],
            'production_snapshot' => ['public_status_label' => 'Üretim bekliyor'],
            'delivery_snapshot' => [
                'public_status_label' => $deliveryLabel,
                'financial_warning' => 'odeme_bekliyor',
                'group_code' => 'DELIVERY-SECRET',
                'file_path' => '/hidden/path.pdf',
            ],
            'procurement_snapshot' => ['public_status_label' => 'Ürününüz hazırlanıyor'],
            'notes' => 'internal_note hidden',
        ]);
    }

    private function createAttachment(OrderItemWorkForm $workForm, string $visibility, string $fileName): void
    {
        OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => 'delivery_document',
            'visibility' => $visibility,
            'file_path' => 'work-forms/' . $workForm->id . '/' . $fileName,
            'file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'internal attachment note',
            'sort_order' => 1,
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

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenantHost . $path;
    }
}
