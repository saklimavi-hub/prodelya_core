<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantUpgradeRequestManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private TenantAccount $tenant;
    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->tenant = $this->createTenant('super-upgrade-a', 'starter');
        $this->tenantAdmin = $this->createTenantUser($this->tenant, 'super-upgrade-admin@example.test', 'admin');
    }

    public function test_super_admin_can_open_generic_request_list_and_use_filters(): void
    {
        $otherTenant = $this->createTenant('super-upgrade-b', 'promotion');
        $otherAdmin = $this->createTenantUser($otherTenant, 'super-upgrade-other@example.test', 'admin');

        TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_module_key' => 'customer_portal',
            'requested_note' => 'Portal ihtiyacı',
        ]);

        TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'requested_by_user_id' => $otherAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'requested_limit_key' => 'orders',
            'current_limit_value' => 10,
            'requested_limit_value' => 20,
            'requested_note' => 'Sipariş limiti artsın',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.index', [
                'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
                'status' => TenantUpgradeRequest::STATUS_APPROVED,
                'search' => $otherTenant->name,
            ]));

        $response->assertOk();
        $response->assertSee('Abone Firma Talepleri');
        $response->assertSee('Uygulama Bekleyenler');
        $response->assertSee($otherTenant->name);
        $response->assertSee('Sipariş limiti artsın');
        $response->assertDontSee('Portal ihtiyacı');
    }

    public function test_tenant_owner_and_public_user_cannot_access_super_admin_generic_request_screens(): void
    {
        $request = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_service_key' => 'xml_export_setup',
        ]);

        $tenantOwner = $this->createTenantUser($this->tenant, 'super-upgrade-owner@example.test', 'tenant_owner');

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.index'))
            ->assertForbidden();

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.show', $request))
            ->assertForbidden();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.index'))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->get('http://' . $this->tenantHost($this->tenant) . '/admin/super-admin/upgrade-requests')
            ->assertForbidden();
    }

    public function test_detail_screen_renders_type_specific_current_state_blocks(): void
    {
        $packageRequest = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'current_package_key' => 'starter',
            'requested_package_key' => 'promotion',
            'requested_note' => 'Paket büyüsün',
        ]);

        $moduleRequest = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_module_key' => 'customer_portal',
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Preview Supplier',
            'code' => 'PREVIEW-SUP',
            'status' => 'active',
        ]);

        $limitRequest = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_limit_key' => 'orders',
            'current_limit_value' => 10,
            'requested_limit_value' => 20,
        ]);

        $supplierRequest = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_supplier_id' => $supplier->id,
            'requested_supplier_key' => $supplier->code,
        ]);

        $packageDetail = $this->openDetail($packageRequest);
        $packageDetail->assertSee('Mevcut Paket');
        $packageDetail->assertSee('Talep Edilen Paket');
        $packageDetail->assertSee('Paket limit farkı', false);

        $moduleDetail = $this->openDetail($moduleRequest);
        $moduleDetail->assertSee('Talep Edilen Modül');
        $moduleDetail->assertSee('Açılma Yolu');
        $moduleDetail->assertSee('Modül açılırsa tenant menüsünde yeni ekranlar', false);

        $limitDetail = $this->openDetail($limitRequest);
        $limitDetail->assertSee('Talep Edilen Limit');
        $limitDetail->assertSee('Kullanım Yüzdesi');

        $supplierDetail = $this->openDetail($supplierRequest);
        $supplierDetail->assertSee('Tedarikçi');
        $supplierDetail->assertSee('Preview Supplier');
        $supplierDetail->assertSee('Product Data Hub katalog ve teklif akışları etkilenebilir', false);
    }

    public function test_super_admin_can_transition_statuses_and_approved_request_waits_for_next_phase(): void
    {
        $request = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_module_key' => 'customer_portal',
            'requested_note' => '<script>alert("xss")</script>Portal açalım',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.in-review', $request), [
                'admin_note' => 'İlk inceleme notu',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHas('success');

        $this->assertSame(TenantUpgradeRequest::STATUS_IN_REVIEW, $request->fresh()->status);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.approve', $request), [
                'admin_note' => '<script>admin</script>Uygun bulundu',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHas('success');

        $approved = $request->fresh();
        $this->assertSame(TenantUpgradeRequest::STATUS_APPROVED, $approved->status);

        $show = $this->openDetail($approved);
        $show->assertSee('Uygula dediğinizde ilgili Abone Firma erişimleri değişecektir.', false);
        $show->assertDontSee('>Uygula<', false);
        $show->assertDontSee('<script>', false);
        $show->assertSee('Portal açalım');
        $show->assertSee('adminUygun bulundu');

        $approveAudit = AuditLog::query()
            ->where('action', 'tenant_upgrade_request_approved')
            ->where('entity_type', 'tenant_upgrade_request')
            ->where('entity_id', $request->id)
            ->firstOrFail();

        $this->assertStringNotContainsString('<script>', json_encode($approveAudit->new_values));
    }

    public function test_reject_requires_admin_note_and_closed_requests_show_passive_actions(): void
    {
        $request = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_service_key' => 'custom_report',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->from(route('admin.super.upgrade-requests.show', $request))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.reject', $request), [
                'admin_note' => '',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHasErrors(['admin_note']);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.reject', $request), [
                'admin_note' => 'Şimdilik uygun değil',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHas('success');

        $rejectedShow = $this->openDetail($request->fresh());
        $rejectedShow->assertSee('Reddedildi');
        $rejectedShow->assertSee('disabled', false);

        $cancelled = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_limit_key' => 'orders',
            'current_limit_value' => 10,
            'requested_limit_value' => 25,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.cancel', $cancelled), [
                'admin_note' => 'Talep sahibi geri çekti',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $cancelled));

        $this->assertSame(TenantUpgradeRequest::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant_upgrade_request_cancelled',
            'entity_type' => 'tenant_upgrade_request',
            'entity_id' => $cancelled->id,
        ]);
    }

    public function test_old_package_only_request_flow_still_works(): void
    {
        $request = \App\Models\TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'current_package_key' => $this->tenant->package_key,
            'requested_package_key' => 'promotion',
            'status' => \App\Models\TenantPackageUpgradeRequest::STATUS_NEW,
            'request_note' => 'Legacy akış',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.package-requests.show', $request));

        $response->assertOk();
        $response->assertSee('Paket Karar Paneli');
    }

    private function openDetail(TenantUpgradeRequest $request)
    {
        return $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.show', $request));
    }

    private function createTenant(string $subdomain, string $packageKey): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Super Upgrade ' . $subdomain,
            'legal_name' => 'Super Upgrade ' . $subdomain . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => Package::query()->where('key', $packageKey)->value('key') ?? $packageKey,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createTenantUser(TenantAccount $tenant, string $email, string $roleKey): User
    {
        $user = User::query()->create([
            'name' => 'Super Upgrade ' . $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'),
        ]);

        return $user;
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
