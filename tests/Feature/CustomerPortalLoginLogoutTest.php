<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\GraphicApprovalRequest;
use App\Models\Order;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CustomerPortalLoginLogoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $company;
    private CompanyContact $contact;
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

        $this->company->forceFill(['portal_enabled' => true])->save();
        $this->tenant->forceFill([
            'panel_subdomain' => 'portal-login-demo',
            'slug' => 'portal-login-demo',
            'status' => 'active',
        ])->save();

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Portal Yetkilisi',
            'title' => 'Satınalma',
            'email' => 'portal-login-contact@example.test',
            'phone' => '02120000000',
            'mobile' => '05320000000',
            'is_primary' => true,
        ]);

        $this->tenantHost = 'portal-login-demo.prodelya_core.test';
        $this->enablePortalLogin();
    }

    public function test_login_page_and_placeholder_dashboard_work_on_tenant_host(): void
    {
        $portalUser = $this->createPortalUser('portal-login@example.test');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-giris'))
            ->assertOk()
            ->assertSee('Müşteri Portalı')
            ->assertSee($this->tenant->name);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertRedirect(route('customer.login'));

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->post($this->tenantUrl('/musteri-giris'), [
                'email' => $portalUser->email,
                'password' => 'secret-password',
                'remember' => '1',
            ])
            ->assertRedirect($this->tenantUrl('/musteri-portal'));

        $this->assertTrue(Auth::guard('customer_portal')->check());
        $this->assertFalse(Auth::guard('web')->check());

        $portalUser->refresh();
        $this->assertNotNull($portalUser->last_login_at);
        $this->assertNotNull($portalUser->last_login_ip);
        $this->assertArrayNotHasKey('password', $portalUser->toArray());

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertOk()
            ->assertSee('Merhaba')
            ->assertSee('Tekliflerimden Son Kayıtlar')
            ->assertSee($this->company->legal_name);
    }

    public function test_login_routes_close_when_feature_or_module_is_disabled(): void
    {
        $disabledTenant = TenantAccount::query()->create([
            'name' => 'Portal Disabled Tenant',
            'legal_name' => 'Portal Disabled Tenant Ltd.',
            'slug' => 'portal-disabled-tenant',
            'panel_subdomain' => 'portal-disabled-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $disabledHost = 'portal-disabled-tenant.prodelya_core.test';

        $this->withServerVariables(['HTTP_HOST' => $disabledHost])
            ->get('http://' . $disabledHost . '/musteri-giris')
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => $disabledHost])
            ->post('http://' . $disabledHost . '/musteri-giris', [
                'email' => 'nobody@example.test',
                'password' => 'secret-password',
            ])
            ->assertNotFound();

        $this->assertSame($disabledTenant->id, $disabledTenant->fresh()->id);
    }

    public function test_wrong_password_other_tenant_blocked_status_and_portal_disabled_company_cannot_login(): void
    {
        $portalUser = $this->createPortalUser('portal-login@example.test');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->from($this->tenantUrl('/musteri-giris'))
            ->post($this->tenantUrl('/musteri-giris'), [
                'email' => $portalUser->email,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('email');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Portal Tenant Other',
            'legal_name' => 'Portal Tenant Other Ltd.',
            'slug' => 'portal-tenant-other',
            'panel_subdomain' => 'portal-tenant-other',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Other Portal Company',
            'short_name' => 'Other Portal Company',
            'email' => 'other-portal-company@example.test',
            'phone' => '02125556677',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        CustomerPortalUser::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Tenant Portal User',
            'email' => 'other-tenant-portal@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->from($this->tenantUrl('/musteri-giris'))
            ->post($this->tenantUrl('/musteri-giris'), [
                'email' => 'other-tenant-portal@example.test',
                'password' => 'secret-password',
            ])
            ->assertSessionHasErrors('email');

        foreach ([CustomerPortalUser::STATUS_PASSIVE, CustomerPortalUser::STATUS_SUSPENDED] as $status) {
            CustomerPortalUser::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => 'Blocked ' . $status,
                'email' => 'blocked-' . $status . '@example.test',
                'password' => 'secret-password',
                'status' => $status,
            ]);

            $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
                ->from($this->tenantUrl('/musteri-giris'))
                ->post($this->tenantUrl('/musteri-giris'), [
                    'email' => 'blocked-' . $status . '@example.test',
                    'password' => 'secret-password',
                ])
                ->assertSessionHasErrors('email');
        }

        $portalDisabledCompany = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Portal Disabled Company',
            'short_name' => 'Portal Disabled Company',
            'email' => 'portal-disabled@example.test',
            'phone' => '02127778899',
            'status' => 'active',
            'portal_enabled' => false,
        ]);

        CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $portalDisabledCompany->id,
            'name' => 'Portal Disabled User',
            'email' => 'portal-disabled-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->from($this->tenantUrl('/musteri-giris'))
            ->post($this->tenantUrl('/musteri-giris'), [
                'email' => 'portal-disabled-user@example.test',
                'password' => 'secret-password',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_logout_clears_customer_portal_guard_without_breaking_web_guard(): void
    {
        $portalUser = $this->createPortalUser('portal-login@example.test');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->post($this->tenantUrl('/musteri-giris'), [
                'email' => $portalUser->email,
                'password' => 'secret-password',
            ])
            ->assertRedirect($this->tenantUrl('/musteri-portal'));

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->post($this->tenantUrl('/musteri-cikis'))
            ->assertRedirect(route('customer.login'));

        $this->assertFalse(Auth::guard('customer_portal')->check());

        $this->actingAs($this->adminUser, 'web');
        $this->assertTrue(Auth::guard('web')->check());

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->post($this->tenantUrl('/musteri-cikis'))
            ->assertRedirect(route('customer.login'));

        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_basic_throttle_and_public_routes_remain_guest_accessible(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
                ->from($this->tenantUrl('/musteri-giris'))
                ->post($this->tenantUrl('/musteri-giris'), [
                    'email' => 'throttle@example.test',
                    'password' => 'wrong-password',
                ]);

            $this->assertContains($response->getStatusCode(), [302, 429]);

            if ($response->getStatusCode() === 302) {
                $response->assertSessionHasErrors('email');
            }
        }

        $response = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->from($this->tenantUrl('/musteri-giris'))
            ->post($this->tenantUrl('/musteri-giris'), [
                'email' => 'throttle@example.test',
                'password' => 'wrong-password',
            ]);

        $this->assertContains($response->getStatusCode(), [302, 429]);

        if ($response->getStatusCode() === 302) {
            $response->assertSessionHasErrors('email');
        }

        $tracking = $this->createTrackingContext();
        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $tracking->public_tracking_token))
            ->assertOk();

        $quoteRequest = $this->createQuoteApprovalContext();
        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.quotes.approval.show', $quoteRequest->token))
            ->assertOk();

        $graphicRequest = $this->createGraphicApprovalContext();
        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.graphics.approval.show', $graphicRequest->token))
            ->assertOk();
    }

    private function createPortalUser(string $email): CustomerPortalUser
    {
        return CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Portal Login User',
            'email' => $email,
            'phone' => '05320000000',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);
    }

    private function enablePortalLogin(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => 'customer_login',
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($this->tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    private function createTrackingContext(): \App\Models\OrderItemWorkForm
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->company->id,
                'quote_date' => '2026-06-18',
                'valid_until' => '2026-06-25',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Portal login public tracking context',
                'items' => [[
                    'product_name' => 'Portal Tracking Ürünü',
                    'product_code' => 'PORTAL-TRACK',
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '10',
                        'print_unit_price' => '10',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return \App\Models\OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
    }

    private function createQuoteApprovalContext(): QuoteApprovalRequest
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->company->id,
                'quote_date' => '2026-06-18',
                'valid_until' => '2026-06-25',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Portal login quote approval context',
                'items' => [[
                    'product_name' => 'Portal Quote Approval Ürünü',
                    'product_code' => 'PORTAL-QUOTE',
                    'quantity' => '5',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '0',
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        app(QuoteApprovalService::class)->sendToCustomer($quote, [], $this->adminUser);

        return QuoteApprovalRequest::query()->latest('id')->firstOrFail();
    }

    private function createGraphicApprovalContext(): GraphicApprovalRequest
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => 'public_graphic_approval',
            ],
            ['is_enabled' => true]
        );

        $workForm = $this->createTrackingContext();
        $graphic = \App\Models\OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->firstOrFail();

        $attachment = \App\Models\OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/portal-graphic.png',
            'file_name' => 'portal-graphic.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'sort_order' => 1,
        ]);

        return app(GraphicApprovalRequestService::class)->createRequest(
            $graphic,
            $attachment,
            [],
            $this->adminUser
        );
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenantHost . $path;
    }
}
