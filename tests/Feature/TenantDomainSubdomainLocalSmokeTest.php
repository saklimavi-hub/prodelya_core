<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\GraphicApprovalRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\QuoteApprovalRequest;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CustomerPortalAuthWorkflowService;
use App\Services\GraphicApprovalRequestService;
use App\Services\QuoteApprovalService;
use App\Services\TenantAccessService;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantDomainSubdomainLocalSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $demoTenant;
    private Role $adminRole;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
    }

    public function test_central_host_root_is_not_resolved_as_tenant_and_subdomains_resolve_correctly(): void
    {
        $tenant = $this->createTenant('saklimavi-test');
        $resolver = app(TenantResolver::class);

        $centralRoot = Request::create('http://' . self::CENTRAL_HOST . '/');
        $centralAdmin = Request::create('http://' . self::CENTRAL_HOST . '/admin/dashboard');
        $demoRequest = Request::create('http://demo.' . self::CENTRAL_HOST . '/admin/dashboard');
        $fixtureRequest = Request::create('http://saklimavi-test.' . self::CENTRAL_HOST . '/admin/dashboard');

        $this->assertNull($resolver->resolve($centralRoot));
        $this->assertNull($resolver->resolve($centralAdmin));
        $this->assertSame($this->demoTenant->id, $resolver->resolve($demoRequest)?->id);
        $this->assertSame($tenant->id, $resolver->resolve($fixtureRequest)?->id);
        $this->assertTrue($resolver->isCentralAdmin($centralRoot));
    }

    public function test_platform_admin_central_login_goes_to_superadmin_and_tenant_host_does_not_become_daily_admin_surface(): void
    {
        $tenant = $this->createTenant('platform-host-guarded');

        $this->post($this->centralUrl('/login'), [
            'email' => 'admin@prodelya.local',
            'password' => 'password',
        ])->assertRedirect(route('admin.super.dashboard'));

        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->tenantUrl($tenant, '/admin/dashboard'))
            ->assertForbidden();
    }

    public function test_tenant_owner_can_use_own_host_but_is_blocked_from_foreign_and_superadmin_routes(): void
    {
        $tenant = $this->createTenant('saklimavi-test');
        $foreignTenant = $this->createTenant('foreign-host');
        $owner = $this->createTenantUser($tenant, 'tenant_owner', 'tenant-owner-host@example.test', 'Tenant Owner');

        $this->post($this->centralUrl('/login'), [
            'email' => $owner->email,
            'password' => 'secret-password',
        ])->assertRedirect($this->tenantUrl($tenant, '/admin/dashboard'));

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/dashboard'))
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($foreignTenant, '/admin/dashboard'))
            ->assertForbidden()
            ->assertSee('Bu tenant paneline erişim yetkiniz yok.');

        $this->actingAs($owner, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertForbidden();
    }

    public function test_tenant_admin_entrypoint_redirects_guest_to_login_and_owner_to_dashboard(): void
    {
        $tenant = $this->createTenant('saklimavi-entry');
        $owner = $this->createTenantUser($tenant, 'tenant_owner', 'tenant-entry-owner@example.test', 'Tenant Entry Owner');

        $this->get($this->tenantUrl($tenant, '/admin'))
            ->assertRedirect(route('login'));

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin'))
            ->assertRedirect($this->tenantUrl($tenant, '/admin/dashboard'));
    }

    public function test_demo_tenant_user_keeps_demo_access_but_cannot_use_superadmin_routes_or_passive_modules(): void
    {
        $demoUser = $this->createTenantUser($this->demoTenant, 'admin', 'demo-smoke-user@example.test', 'Demo User');
        $access = app(TenantAccessService::class);

        $this->actingAs($demoUser, 'web')
            ->get('http://demo.' . self::CENTRAL_HOST . '/admin/dashboard')
            ->assertOk();

        $this->actingAs($demoUser, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'))
            ->assertForbidden();

        $this->assertTrue($access->canAccessModule($this->demoTenant->fresh(), 'customer_portal'));
        $this->assertTrue($access->canAccessFeature($this->demoTenant->fresh(), 'customer_login', 'customer_portal'));
        $this->assertFalse($access->canAccessModule($this->demoTenant->fresh(), 'xml_export'));
        $this->assertFalse($access->canAccessModule($this->demoTenant->fresh(), 'web_quote_widget'));
    }

    public function test_customer_portal_invite_and_reset_central_local_fallback_remains_openable(): void
    {
        $tenant = $this->createTenant('portal-local-smoke');
        $company = $this->createPortalCompany($tenant);
        $contact = $this->createPortalContact($tenant, $company);
        $portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'name' => 'Portal Smoke User',
            'email' => 'portal-smoke-user@example.test',
            'status' => CustomerPortalUser::STATUS_INVITED,
            'invited_at' => now(),
        ]);

        $this->enableCustomerPortal($tenant);

        $workflow = app(CustomerPortalAuthWorkflowService::class);
        $inviteUrl = $workflow->issueInvite($tenant, $portalUser, $this->platformAdmin, $this->tenantHost($tenant))['invite_link'];

        $this->assertStringStartsWith($this->centralUrl('/musteri-davet/'), $inviteUrl);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($inviteUrl)
            ->assertOk()
            ->assertSee('Şifrenizi Belirleyin');

        $resetUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'company_contact_id' => $contact->id,
            'name' => 'Portal Reset User',
            'email' => 'portal-reset-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
            'password_set_at' => now()->subDay(),
            'password_reset_token' => hash('sha256', 'portal-local-reset-token'),
            'password_reset_expires_at' => now()->addHour(),
        ]);

        $resetUrl = $workflow->tenantPortalUrl(
            $tenant,
            '/musteri-sifre-yenile/portal-local-reset-token',
            $this->tenantHost($tenant)
        );

        $this->assertSame($this->centralUrl('/musteri-sifre-yenile/portal-local-reset-token'), $resetUrl);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($resetUrl)
            ->assertOk()
            ->assertSee('Şifrenizi Yenileyin');

        $this->assertArrayNotHasKey('password_reset_token', $resetUser->fresh()->toArray());
    }

    public function test_public_tracking_quote_and_graphic_links_remain_guest_accessible_without_sensitive_leakage(): void
    {
        $tenant = $this->createTenant('public-link-smoke');
        [$company, $contact] = [$this->createPortalCompany($tenant), null];
        $contact = $this->createPortalContact($tenant, $company);

        $tracking = $this->createTrackingContext($tenant, $company, 'PUBLIC-SMOKE-001');
        $quoteRequest = $this->createQuoteApprovalContext($tenant, $company, 'TK-DOMAIN-SMOKE-001');
        $graphicRequest = $this->createGraphicApprovalContext($tenant, $company, 'PG-DOMAIN-SMOKE-001');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $tracking->public_tracking_token))
            ->assertOk()
            ->assertDontSee($tracking->public_tracking_token)
            ->assertDontSee('file_path', false)
            ->assertDontSee('password', false);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.quotes.approval.show', $quoteRequest->token))
            ->assertOk()
            ->assertDontSee($quoteRequest->token)
            ->assertDontSee('file_path', false)
            ->assertDontSee('password', false);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.graphics.approval.show', $graphicRequest->token))
            ->assertOk()
            ->assertDontSee($graphicRequest->token)
            ->assertDontSee('file_path', false)
            ->assertDontSee('password', false);
    }

    public function test_superadmin_tenant_show_and_edit_display_local_host_preview_and_helper_note(): void
    {
        $tenant = $this->createTenant('saklimavi-test');
        $tenant->forceFill([
            'custom_domain' => 'app.saklimavi.test',
            'portal_domain' => 'portal.saklimavi.test',
        ])->save();

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.show', $tenant));

        $show->assertOk();
        $show->assertSee('http://saklimavi-test.' . self::CENTRAL_HOST . '/admin');
        $show->assertSee('C:\\Windows\\System32\\drivers\\etc\\hosts', false);
        $show->assertSee('127.0.0.1 saklimavi-test.' . self::CENTRAL_HOST, false);
        $show->assertSee('http://app.saklimavi.test/admin');
        $show->assertSee('http://portal.saklimavi.test/musteri-giris');
        $show->assertDontSee('password', false);
        $show->assertDontSee('file_path', false);

        $edit = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.edit', $tenant));

        $edit->assertOk();
        $edit->assertSee('http://saklimavi-test.' . self::CENTRAL_HOST . '/admin');
        $edit->assertSee('hosts veya wildcard vhost gerekebilir');
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $subdomain)),
            'legal_name' => ucfirst(str_replace('-', ' ', $subdomain)) . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createTenantUser(TenantAccount $tenant, string $roleKey, string $email, string $name): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function createPortalCompany(TenantAccount $tenant): Company
    {
        return Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Portal Smoke Company ' . $tenant->id,
            'short_name' => 'Portal Smoke',
            'email' => 'portal-company-' . $tenant->id . '@example.test',
            'phone' => '02120000000',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
    }

    private function createPortalContact(TenantAccount $tenant, Company $company): CompanyContact
    {
        return CompanyContact::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Portal Contact',
            'email' => 'portal-contact-' . $tenant->id . '@example.test',
            'phone' => '02121111111',
            'is_primary' => true,
        ]);
    }

    private function enableCustomerPortal(TenantAccount $tenant): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => 'customer_login',
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    private function enableQuoteApproval(TenantAccount $tenant): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function enableGraphicApproval(TenantAccount $tenant): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => 'public_graphic_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function createTrackingContext(TenantAccount $tenant, Company $company, string $documentNumber): OrderItemWorkForm
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $company->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
            'product_total' => 1000,
            'print_total' => 0,
            'created_by' => $this->platformAdmin->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Tracking Smoke Product',
            'product_code' => 'TRACK-' . $documentNumber,
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Public smoke',
            'product_snapshot' => ['display_name' => 'Tracking Smoke Product'],
            'price_snapshot' => ['product_total' => 1000],
            'stock_snapshot' => ['visible_stock_quantity' => 50],
            'list_price' => 100,
            'discount_rate' => 0,
            'unit_price' => 100,
            'line_total' => 1000,
            'has_print' => true,
            'print_total' => 0,
            'status' => 'active',
        ]);

        return OrderItemWorkForm::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'work_form_number' => 'WF-' . $documentNumber,
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'tracking-token-' . strtolower($documentNumber),
            'order_snapshot' => ['document_number' => $documentNumber],
            'customer_snapshot' => ['company_name' => $company->legal_name],
            'product_snapshot' => ['display_name' => 'Tracking Smoke Product'],
            'print_snapshot' => [],
            'graphic_snapshot' => [],
            'production_snapshot' => [],
            'delivery_snapshot' => [],
            'created_by' => $this->platformAdmin->id,
            'updated_by' => $this->platformAdmin->id,
        ]);
    }

    private function createQuoteApprovalContext(TenantAccount $tenant, Company $company, string $documentNumber): QuoteApprovalRequest
    {
        $this->enableQuoteApproval($tenant);

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $company->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-22',
            'valid_until' => '2026-06-29',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
            'product_total' => 1000,
            'print_total' => 0,
            'created_by' => $this->platformAdmin->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Quote Smoke Product',
            'product_code' => 'QUOTE-' . $documentNumber,
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Quote smoke',
            'product_snapshot' => ['display_name' => 'Quote Smoke Product'],
            'price_snapshot' => [
                'product_total' => 1000,
                'vat_rate' => 20,
                'vat_breakdown' => [['rate' => 20, 'total' => 200, 'scope' => 'product']],
            ],
            'stock_snapshot' => ['visible_stock_quantity' => 50],
            'list_price' => 100,
            'discount_rate' => 0,
            'unit_price' => 100,
            'line_total' => 1000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'active',
        ]);

        return app(QuoteApprovalService::class)->sendToCustomer($quote, [
            'contact_email' => 'quote-smoke@example.test',
        ], $this->platformAdmin);
    }

    private function createGraphicApprovalContext(TenantAccount $tenant, Company $company, string $documentNumber): GraphicApprovalRequest
    {
        $this->enableGraphicApproval($tenant);

        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $company->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
            'product_total' => 1000,
            'print_total' => 0,
            'created_by' => $this->platformAdmin->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Graphic Smoke Product',
            'product_code' => 'GRAPHIC-' . $documentNumber,
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Graphic smoke',
            'product_snapshot' => ['display_name' => 'Graphic Smoke Product'],
            'price_snapshot' => ['product_total' => 1000],
            'stock_snapshot' => ['visible_stock_quantity' => 50],
            'list_price' => 100,
            'discount_rate' => 0,
            'unit_price' => 100,
            'line_total' => 1000,
            'has_print' => true,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_number' => 'WF-GR-' . $documentNumber,
            'item_sequence' => 1,
            'status' => 'pending',
            'version' => 1,
            'public_tracking_token' => 'graphic-tracking-' . strtolower($documentNumber),
            'order_snapshot' => ['document_number' => $documentNumber],
            'customer_snapshot' => ['company_name' => $company->legal_name],
            'product_snapshot' => ['display_name' => 'Graphic Smoke Product'],
            'print_snapshot' => [],
            'graphic_snapshot' => [],
            'production_snapshot' => [],
            'delivery_snapshot' => [],
            'created_by' => $this->platformAdmin->id,
            'updated_by' => $this->platformAdmin->id,
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'production_type' => 'İç üretim',
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'print_quantity' => 10,
            'print_unit_price' => 0,
            'print_total' => 0,
            'status' => 'draft',
        ]);

        $graphic = OrderItemPrintGraphic::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'order_item_work_form_id' => $workForm->id,
            'sequence_code' => 'GR-' . $documentNumber,
            'status' => OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED,
            'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_NOT_REQUIRED,
            'visibility_default' => 'customer_visible',
            'created_by' => $this->platformAdmin->id,
            'updated_by' => $this->platformAdmin->id,
        ]);

        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $tenant->id,
            'work_form_id' => $workForm->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'order_item_print_graphic_id' => $graphic->id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/graphic-smoke.png',
            'file_name' => 'graphic-smoke.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->platformAdmin->id,
        ]);

        $graphic->forceFill([
            'latest_attachment_id' => $attachment->id,
        ])->save();

        return app(GraphicApprovalRequestService::class)->createRequest(
            $graphic->fresh(['orderItem', 'tenant', 'workForm']),
            $attachment,
            [],
            $this->platformAdmin
        );
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $this->tenantHost($tenant) . $path;
    }

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }
}
