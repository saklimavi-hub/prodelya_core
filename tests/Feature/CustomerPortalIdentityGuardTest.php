<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCustomerPortalAuthenticated;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemWorkForm;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CustomerPortalIdentityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $company;
    private CompanyContact $contact;

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

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Portal Yetkilisi',
            'title' => 'Satınalma',
            'email' => 'portal-contact@example.test',
            'phone' => '02120000000',
            'mobile' => '05320000000',
            'is_primary' => true,
        ]);

        $this->enableCustomerPortal();
    }

    public function test_customer_portal_identity_model_and_guard_are_isolated_and_scoped(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('customer_portal_users'));

        $portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Portal Kullanıcısı',
            'email' => 'portal-user@example.test',
            'phone' => '05321111111',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $this->assertTrue(Hash::check('secret-password', (string) $portalUser->getAuthPassword()));
        $this->assertArrayNotHasKey('password', $portalUser->toArray());
        $this->assertArrayNotHasKey('remember_token', $portalUser->toArray());
        $this->assertSame($this->tenant->id, $portalUser->tenantAccount->id);
        $this->assertSame($this->company->id, $portalUser->company->id);
        $this->assertSame($this->contact->id, $portalUser->companyContact->id);
        $this->assertTrue($this->company->portalUsers->contains($portalUser));
        $this->assertTrue($this->contact->portalUsers->contains($portalUser));
        $this->assertSame($portalUser->id, $this->contact->primaryPortalUser?->id);

        $guardConfig = config('auth.guards.customer_portal');
        $providerConfig = config('auth.providers.customer_portal_users');
        $this->assertSame('session', $guardConfig['driver']);
        $this->assertSame('customer_portal_users', $guardConfig['provider']);
        $this->assertSame(CustomerPortalUser::class, $providerConfig['model']);

        $this->actingAs($this->adminUser, 'web');
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertFalse(Auth::guard('customer_portal')->check());
        Auth::guard('web')->logout();

        Auth::guard('customer_portal')->login($portalUser);
        $this->assertTrue(Auth::guard('customer_portal')->check());
        $this->assertFalse(Auth::guard('web')->check());
        Auth::guard('customer_portal')->logout();

        $this->assertTrue($portalUser->isActive());
        $this->assertFalse($portalUser->isSuspended());
        $this->assertTrue($portalUser->canAccessPortal());
        $this->assertSame($this->tenant->id, $portalUser->scopeTenantId());
        $this->assertSame($this->company->id, $portalUser->scopeCompanyId());
        $this->assertTrue($portalUser->belongsToTenant($this->tenant));
        $this->assertTrue($portalUser->belongsToCompany($this->company));
        $this->assertSame('Portal Kullanıcısı', $portalUser->safeDisplayName());

        $suspendedUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Suspended User',
            'email' => 'portal-suspended@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_SUSPENDED,
        ]);
        $passiveUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Passive User',
            'email' => 'portal-passive@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_PASSIVE,
        ]);

        $this->assertFalse($suspendedUser->canAccessPortal());
        $this->assertFalse($passiveUser->canAccessPortal());

        $sameTenantDuplicate = false;

        try {
            CustomerPortalUser::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => 'Duplicate',
                'email' => 'portal-user@example.test',
                'password' => 'secret-password',
                'status' => CustomerPortalUser::STATUS_ACTIVE,
            ]);
            $sameTenantDuplicate = true;
        } catch (\Throwable) {
            $sameTenantDuplicate = false;
        }

        $this->assertFalse($sameTenantDuplicate);

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Portal Tenant 2',
            'legal_name' => 'Portal Tenant 2 Ltd.',
            'slug' => 'portal-tenant-2',
            'panel_subdomain' => 'portal-tenant-2',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Other Portal Company',
            'short_name' => 'Other Portal Company',
            'email' => 'other-company@example.test',
            'phone' => '02123334455',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        $sameEmailOtherTenant = CustomerPortalUser::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Tenant User',
            'email' => 'portal-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->assertNotNull($sameEmailOtherTenant->id);

        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'document_type' => 'quote',
            'document_number' => 'TK-PORTAL-001',
            'order_family' => 'promotion',
            'customer_company_id' => $this->company->id,
            'status' => 'pending',
            'currency' => 'TL',
            'subtotal' => 100,
            'vat_total' => 20,
            'grand_total' => 120,
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'document_type' => 'order',
            'document_number' => 'SP-PORTAL-001',
            'order_family' => 'promotion',
            'customer_company_id' => $this->company->id,
            'status' => 'pending',
            'currency' => 'TL',
            'subtotal' => 100,
            'vat_total' => 20,
            'grand_total' => 120,
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $this->createOrderItem($order, 'Portal Test Ürün')->id,
            'work_form_number' => 'IF-PORTAL-001',
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'portal-work-form-token',
            'order_snapshot' => ['document_number' => 'SP-PORTAL-001'],
            'customer_snapshot' => ['company_name' => $this->company->legal_name],
            'product_snapshot' => ['product_name' => 'Portal Test Ürün'],
        ]);

        $otherCompanySameTenant = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Other Same Tenant Company',
            'short_name' => 'Other Same Tenant Company',
            'email' => 'other-same-tenant@example.test',
            'phone' => '02124445566',
            'status' => 'active',
            'portal_enabled' => true,
        ]);

        $otherCompanyOrder = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'document_type' => 'order',
            'document_number' => 'SP-PORTAL-002',
            'order_family' => 'promotion',
            'customer_company_id' => $otherCompanySameTenant->id,
            'status' => 'pending',
            'currency' => 'TL',
            'subtotal' => 100,
            'vat_total' => 20,
            'grand_total' => 120,
        ]);

        $otherTenantOrder = Order::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'document_type' => 'order',
            'document_number' => 'SP-PORTAL-003',
            'order_family' => 'promotion',
            'customer_company_id' => $otherCompany->id,
            'status' => 'pending',
            'currency' => 'TL',
            'subtotal' => 100,
            'vat_total' => 20,
            'grand_total' => 120,
        ]);

        $otherTenantWorkForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $otherTenantOrder->id,
            'order_item_id' => $this->createOrderItem($otherTenantOrder, 'Portal Test Ürün 2')->id,
            'work_form_number' => 'IF-PORTAL-002',
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'portal-work-form-token-2',
            'order_snapshot' => ['document_number' => 'SP-PORTAL-003'],
            'customer_snapshot' => ['company_name' => $otherCompany->legal_name],
            'product_snapshot' => ['product_name' => 'Portal Test Ürün 2'],
        ]);

        $this->assertTrue($portalUser->canSeeCompany($this->company));
        $this->assertTrue($portalUser->canSeeQuote($quote));
        $this->assertTrue($portalUser->canSeeOrder($order));
        $this->assertTrue($portalUser->canSeeWorkForm($workForm));
        $this->assertFalse($portalUser->canSeeCompany($otherCompany));
        $this->assertFalse($portalUser->canSeeOrder($otherCompanyOrder));
        $this->assertFalse($portalUser->canSeeOrder($otherTenantOrder));
        $this->assertFalse($portalUser->canSeeWorkForm($otherTenantWorkForm));

        $this->assertInstanceOf(EnsureCustomerPortalAuthenticated::class, app(EnsureCustomerPortalAuthenticated::class));

        Route::middleware(['web', 'resolve.tenant', 'customer.portal.auth'])
            ->get('/_test/customer-portal-probe', fn () => 'ok');

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/_test/customer-portal-probe')
            ->assertRedirect('/musteri-giris');
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

    private function createOrderItem(Order $order, string $productName): OrderItem
    {
        return OrderItem::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $productName,
            'product_code' => 'PORTAL-ITEM-' . $order->id,
            'quantity' => 1,
            'unit' => 'Adet',
            'unit_price' => 100,
            'line_total' => 100,
            'has_print' => false,
            'status' => 'pending',
        ]);
    }
}
