<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\GraphicApprovalRequest;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\CustomerPortalAuthWorkflowService;
use App\Services\GraphicApprovalRequestService;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerPortalInvitePasswordFlowTest extends TestCase
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

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->tenant->forceFill([
            'panel_subdomain' => 'portal-invite-flow',
            'slug' => 'portal-invite-flow',
            'status' => 'active',
        ])->save();

        $this->company->forceFill(['portal_enabled' => true])->save();

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Portal Davet Yetkilisi',
            'title' => 'Satınalma',
            'email' => 'portal-invite-contact@example.test',
            'phone' => '02121111111',
            'mobile' => '05321111111',
            'is_primary' => true,
        ]);

        $this->tenantHost = 'portal-invite-flow.prodelya_core.test';

        $this->enableCustomerPortal();
        $this->enableCurrentAccounts();
    }

    public function test_local_invite_link_uses_central_host_and_acceptance_flow_stays_openable(): void
    {
        $portalUser = $this->createInvitedPortalUser('local-invite-link@example.test');

        $result = app(CustomerPortalAuthWorkflowService::class)->issueInvite(
            $this->tenant,
            $portalUser,
            $this->adminUser,
            $this->tenantHost
        );

        $inviteUrl = (string) $result['invite_link'];
        $plainToken = (string) str($inviteUrl)->afterLast('/');

        $this->assertStringStartsWith($this->centralUrl('/musteri-davet/'), $inviteUrl);
        $this->assertStringNotContainsString($this->tenantHost, $inviteUrl);
        $this->assertStringNotContainsString((string) $portalUser->fresh()->invite_token, $inviteUrl);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($inviteUrl)
            ->assertOk()
            ->assertSee('Şifrenizi Belirleyin');

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post($inviteUrl, [
                'password' => 'local-central-password',
                'password_confirmation' => 'local-central-password',
            ]);

        $response->assertRedirect($this->centralUrl('/musteri-portal'));

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($this->centralUrl('/musteri-portal'))
            ->assertOk();
    }

    public function test_admin_can_create_portal_user_invite_is_scoped_and_same_email_rules_work(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.companies.portal-users.store', $this->company), [
                'name' => 'Portal Davet Kullanıcısı',
                'email' => 'invite-user@example.test',
                'phone' => '05320000000',
                'company_contact_id' => $this->contact->id,
            ]);

        $response->assertRedirect(route('admin.companies.show', $this->company));
        $response->assertSessionHas('portal_invite_link');
        $response->assertSessionHas('portal_invite_link', function (string $inviteLink): bool {
            return str_starts_with($inviteLink, $this->centralUrl('/musteri-davet/'))
                && ! str_contains($inviteLink, $this->tenantHost);
        });

        $portalUser = CustomerPortalUser::query()->where('email', 'invite-user@example.test')->firstOrFail();
        $this->assertSame(CustomerPortalUser::STATUS_INVITED, $portalUser->status);
        $this->assertNotNull($portalUser->invite_token);
        $this->assertNotNull($portalUser->invite_expires_at);
        $this->assertNull($portalUser->password);
        $this->assertArrayNotHasKey('invite_token', $portalUser->toArray());

        $log = NotificationLog::query()->latest('id')->firstOrFail();
        $this->assertSame(NotificationLog::STATUS_SKIPPED, $log->status);
        $this->assertSame('customer_portal_invite_sent', $log->notification_key);
        $this->assertStringNotContainsString('musteri-davet', (string) $log->safeDisplayPreview());
        $this->assertStringNotContainsString('token', json_encode($log->meta_json, JSON_UNESCAPED_UNICODE));

        $duplicate = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.companies.show', $this->company))
            ->post(route('admin.companies.portal-users.store', $this->company), [
                'name' => 'Portal Davet Kullanıcısı 2',
                'email' => 'invite-user@example.test',
            ]);

        $duplicate->assertRedirect(route('admin.companies.show', $this->company));
        $duplicate->assertSessionHasErrors('email');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Portal Invite Other Tenant',
            'legal_name' => 'Portal Invite Other Tenant Ltd.',
            'slug' => 'portal-invite-other-tenant',
            'panel_subdomain' => 'portal-invite-other-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Portal Invite Other Company',
            'short_name' => 'Portal Invite Other Company',
            'email' => 'other-company@example.test',
            'phone' => '02125555555',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        CustomerPortalUser::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Tenant User',
            'email' => 'invite-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->assertCount(2, CustomerPortalUser::query()->where('email', 'invite-user@example.test')->get());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.companies.portal-users.store', $otherCompany), [
                'name' => 'Blocked User',
                'email' => 'blocked@example.test',
            ])
            ->assertForbidden();
    }

    public function test_invite_link_acceptance_activates_user_hashes_password_and_prevents_reuse(): void
    {
        $portalUser = $this->createInvitedPortalUser('accept-flow@example.test');
        $plainToken = 'invite-token-accept-flow';

        $portalUser->forceFill([
            'invite_token' => hash('sha256', $plainToken),
            'invite_expires_at' => now()->addHour(),
        ])->save();

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-davet/' . $plainToken))
            ->assertOk()
            ->assertSee('Şifrenizi Belirleyin');

        $response = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->post($this->tenantUrl('/musteri-davet/' . $plainToken), [
                'password' => 'new-secret-password',
                'password_confirmation' => 'new-secret-password',
            ]);

        $response->assertRedirect($this->tenantUrl('/musteri-portal'));
        $this->assertTrue(auth('customer_portal')->check());

        $portalUser->refresh();
        $this->assertSame(CustomerPortalUser::STATUS_ACTIVE, $portalUser->status);
        $this->assertNotNull($portalUser->password_set_at);
        $this->assertNull($portalUser->invite_token);
        $this->assertNull($portalUser->invite_expires_at);
        $this->assertTrue(Hash::check('new-secret-password', (string) $portalUser->getAuthPassword()));
        $this->assertStringNotContainsString('new-secret-password', json_encode($portalUser->toArray(), JSON_UNESCAPED_UNICODE));

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-davet/' . $plainToken))
            ->assertNotFound();

        $expiredUser = $this->createInvitedPortalUser('expired-invite@example.test');
        $expiredUser->forceFill([
            'invite_token' => hash('sha256', 'expired-invite-token'),
            'invite_expires_at' => now()->subMinute(),
        ])->save();

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-davet/expired-invite-token'))
            ->assertNotFound();
    }

    public function test_password_reset_is_generic_scoped_and_updates_password_when_token_is_valid(): void
    {
        $portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Reset User',
            'email' => 'reset-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
            'password_set_at' => now()->subDay(),
        ]);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-sifre-sifirla'))
            ->assertOk()
            ->assertSee('Şifremi Unuttum');

        $unknown = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->from($this->tenantUrl('/musteri-sifre-sifirla'))
            ->post($this->tenantUrl('/musteri-sifre-sifirla'), [
                'email' => 'unknown@example.test',
            ]);

        $unknown->assertRedirect($this->tenantUrl('/musteri-sifre-sifirla'));
        $unknown->assertSessionHas('success');

        $response = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->from($this->tenantUrl('/musteri-sifre-sifirla'))
            ->post($this->tenantUrl('/musteri-sifre-sifirla'), [
                'email' => 'reset-user@example.test',
            ]);

        $response->assertRedirect($this->tenantUrl('/musteri-sifre-sifirla'));
        $response->assertSessionHas('success');

        $portalUser->refresh();
        $this->assertNotNull($portalUser->password_reset_token);
        $this->assertNotNull($portalUser->password_reset_expires_at);

        $resetToken = 'reset-user-token';
        $portalUser->forceFill([
            'password_reset_token' => hash('sha256', $resetToken),
            'password_reset_expires_at' => now()->addHour(),
        ])->save();

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-sifre-yenile/' . $resetToken))
            ->assertOk()
            ->assertSee('Şifrenizi Yenileyin');

        $update = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->post($this->tenantUrl('/musteri-sifre-yenile/' . $resetToken), [
                'password' => 'renewed-secret-password',
                'password_confirmation' => 'renewed-secret-password',
            ]);

        $update->assertRedirect($this->tenantUrl('/musteri-giris'));
        $update->assertSessionHas('success');

        $portalUser->refresh();
        $this->assertTrue(Hash::check('renewed-secret-password', (string) $portalUser->getAuthPassword()));
        $this->assertNull($portalUser->password_reset_token);
        $this->assertNull($portalUser->password_reset_expires_at);

        $expired = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Expired Reset User',
            'email' => 'expired-reset@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
            'password_set_at' => now()->subDay(),
            'password_reset_token' => hash('sha256', 'expired-reset-token'),
            'password_reset_expires_at' => now()->subMinute(),
        ]);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-sifre-yenile/expired-reset-token'))
            ->assertNotFound();

        $passive = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Passive Reset User',
            'email' => 'passive-reset@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_PASSIVE,
        ]);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->post($this->tenantUrl('/musteri-sifre-sifirla'), [
                'email' => 'passive-reset@example.test',
            ])
            ->assertRedirect($this->tenantUrl('/musteri-sifre-sifirla'));

        $this->assertNull($passive->fresh()->password_reset_token);
    }

    public function test_local_password_reset_link_uses_central_host_and_login_screen_remains_openable(): void
    {
        $portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Local Reset User',
            'email' => 'local-reset-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
            'password_set_at' => now()->subDay(),
        ]);

        $resetToken = 'local-reset-token';
        $portalUser->forceFill([
            'password_reset_token' => hash('sha256', $resetToken),
            'password_reset_expires_at' => now()->addHour(),
        ])->save();

        $resetUrl = app(CustomerPortalAuthWorkflowService::class)->tenantPortalUrl(
            $this->tenant,
            '/musteri-sifre-yenile/' . $resetToken,
            $this->tenantHost
        );

        $this->assertSame($this->centralUrl('/musteri-sifre-yenile/' . $resetToken), $resetUrl);
        $this->assertStringNotContainsString($this->tenantHost, $resetUrl);
        $this->assertStringNotContainsString((string) $portalUser->fresh()->password_reset_token, $resetUrl);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($resetUrl)
            ->assertOk()
            ->assertSee('Şifrenizi Yenileyin');

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post($resetUrl, [
                'password' => 'local-renewed-password',
                'password_confirmation' => 'local-renewed-password',
            ]);

        $response->assertRedirect($this->centralUrl('/musteri-giris'));

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($this->centralUrl('/musteri-giris'))
            ->assertOk();
    }

    public function test_dedicated_portal_or_custom_domain_logic_is_preserved_for_non_local_hosts(): void
    {
        $this->tenant->forceFill([
            'portal_domain' => null,
            'custom_domain' => null,
            'panel_subdomain' => 'live-tenant',
        ])->save();

        $subdomainUrl = app(CustomerPortalAuthWorkflowService::class)->tenantPortalUrl(
            $this->tenant->fresh(),
            '/musteri-davet/prod-token',
            'admin.example.com'
        );

        $this->assertSame('http://live-tenant.admin.example.com/musteri-davet/prod-token', $subdomainUrl);

        $this->tenant->forceFill([
            'portal_domain' => 'portal.customer-example.com',
            'custom_domain' => 'app.customer-example.com',
        ])->save();

        $portalDomainUrl = app(CustomerPortalAuthWorkflowService::class)->tenantPortalUrl(
            $this->tenant->fresh(),
            '/musteri-sifre-yenile/prod-reset-token',
            'admin.example.com'
        );

        $this->assertSame('http://portal.customer-example.com/musteri-sifre-yenile/prod-reset-token', $portalDomainUrl);
    }

    public function test_public_tracking_and_approval_routes_remain_guest_accessible(): void
    {
        $tracking = $this->createTrackingContext();
        $quoteRequest = $this->createQuoteApprovalContext();
        $graphicRequest = $this->createGraphicApprovalContext();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $tracking->public_tracking_token))
            ->assertOk();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.quotes.approval.show', $quoteRequest->token))
            ->assertOk();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.graphics.approval.show', $graphicRequest->token))
            ->assertOk();
    }

    private function createInvitedPortalUser(string $email): CustomerPortalUser
    {
        return CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Invited Portal User',
            'email' => $email,
            'status' => CustomerPortalUser::STATUS_INVITED,
            'invited_at' => now(),
        ]);
    }

    private function enableCustomerPortal(): void
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

    private function enableCurrentAccounts(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    private function createTrackingContext(): OrderItemWorkForm
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->company->id,
                'quote_date' => '2026-06-20',
                'valid_until' => '2026-06-27',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Portal invite tracking context',
                'items' => [[
                    'product_name' => 'Portal Invite Tracking',
                    'product_code' => 'PORTAL-INV-TRACK',
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

        return OrderItemWorkForm::query()
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
                'quote_date' => '2026-06-20',
                'valid_until' => '2026-06-27',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Portal invite quote approval context',
                'items' => [[
                    'product_name' => 'Portal Invite Quote Approval',
                    'product_code' => 'PORTAL-INV-QUOTE',
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
            'file_path' => 'work-forms/' . $workForm->id . '/portal-invite-graphic.png',
            'file_name' => 'portal-invite-graphic.png',
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

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }
}
