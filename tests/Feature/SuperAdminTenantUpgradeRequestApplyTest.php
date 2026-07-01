<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantUpgradeRequestApplyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $tenant;
    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = $this->createTenant('apply-tenant-a', 'starter');
        $this->tenantAdmin = $this->createTenantUser($this->tenant, 'apply-admin@example.test', 'admin');
    }

    public function test_super_admin_can_apply_approved_package_upgrade_and_tenant_package_screen_reflects_it(): void
    {
        $request = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'current_package_key' => 'starter',
            'requested_package_key' => 'promotion',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $request), [
                'apply_note' => 'Paket değişimi tamamlandı',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHas('success');

        $this->assertSame('promotion', $this->tenant->fresh()->package_key);

        $applied = $request->fresh();
        $this->assertSame(TenantUpgradeRequest::STATUS_APPLIED, $applied->status);
        $this->assertSame('promotion', data_get($applied->meta_json, 'apply_summary.new_package_key'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant_upgrade_request_applied',
            'entity_type' => 'tenant_upgrade_request',
            'entity_id' => $request->id,
        ]);

        $packageScreen = $this->actingAs($this->tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.my-package.index'));

        $packageScreen->assertOk();
        $packageScreen->assertSee('promotion', false);
    }

    public function test_inactive_package_apply_is_blocked_and_request_stays_approved(): void
    {
        $inactive = Package::query()->create([
            'key' => 'apply-passive-pack',
            'name' => 'Apply Passive Pack',
            'status' => 'passive',
            'is_public' => true,
        ]);

        $request = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'current_package_key' => 'starter',
            'requested_package_key' => $inactive->key,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $request))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHas('error');

        $this->assertSame(TenantUpgradeRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant_upgrade_request_apply_blocked',
            'entity_type' => 'tenant_upgrade_request',
            'entity_id' => $request->id,
        ]);
    }

    public function test_super_admin_can_apply_module_and_feature_addons(): void
    {
        $moduleRequest = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ]);

        $featureRequest = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_FEATURE_ADDON,
            'requested_feature_key' => 'public_quote_approval',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $moduleRequest))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $moduleRequest));

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $featureRequest))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $featureRequest));

        $this->assertDatabaseHas('tenant_modules', [
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'customer_portal',
            'feature_key' => null,
            'is_enabled' => true,
        ]);

        $this->assertDatabaseHas('tenant_modules', [
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => true,
        ]);

        /** @var TenantAccessService $access */
        $access = app(TenantAccessService::class);
        $this->assertTrue($access->canAccessModule($this->tenant->fresh(), 'customer_portal'));
        $this->assertTrue($access->canAccessFeature($this->tenant->fresh(), 'public_quote_approval', 'quote_customer_approval'));
    }

    public function test_invalid_or_already_active_module_apply_is_blocked(): void
    {
        $coreRequest = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'core',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $coreRequest))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $coreRequest))
            ->assertSessionHas('error');

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $this->tenant->id, 'module_key' => 'customer_portal', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $alreadyActive = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $alreadyActive))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $alreadyActive))
            ->assertSessionHas('error');
    }

    public function test_super_admin_can_apply_limit_increase_and_package_screen_reflects_new_limit(): void
    {
        TenantSetting::setValue($this->tenant->id, 'limit_orders', 10, 'integer');

        $request = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'requested_limit_key' => 'orders',
            'current_limit_value' => 10,
            'requested_limit_value' => 25,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $request))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $this->assertSame(25, TenantSetting::getValue($this->tenant->id, 'limit_orders'));
        $this->assertSame(TenantUpgradeRequest::STATUS_APPLIED, $request->fresh()->status);

        $screen = $this->actingAs($this->tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.my-package.index'));

        $screen->assertOk();
        $screen->assertSee('25');
    }

    public function test_invalid_limit_apply_and_unlimited_limit_are_blocked(): void
    {
        TenantSetting::setValue($this->tenant->id, 'limit_orders', 20, 'integer');

        $equalRequest = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'requested_limit_key' => 'orders',
            'current_limit_value' => 10,
            'requested_limit_value' => 20,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $equalRequest))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $equalRequest))
            ->assertSessionHas('error');

        TenantSetting::setValue($this->tenant->id, 'limit_orders', 'unlimited', 'string');

        $unlimitedRequest = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'requested_limit_key' => 'orders',
            'current_limit_value' => null,
            'requested_limit_value' => 99,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $unlimitedRequest))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $unlimitedRequest))
            ->assertSessionHas('error');
    }

    public function test_super_admin_can_apply_supplier_access_and_existing_access_blocks_reapply(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Apply Supplier',
            'code' => 'APPLY-SUP',
            'status' => 'active',
        ]);

        $request = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
            'requested_supplier_id' => $supplier->id,
            'requested_supplier_key' => $supplier->code,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $request))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $this->assertDatabaseHas('tenant_supplier_access', [
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
        ]);

        $duplicate = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
            'requested_supplier_id' => $supplier->id,
            'requested_supplier_key' => $supplier->code,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $duplicate))
            ->assertRedirect(route('admin.super.upgrade-requests.show', $duplicate))
            ->assertSessionHas('error');
    }

    public function test_service_request_apply_requires_note_and_does_not_mutate_system_access(): void
    {
        $request = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_service_key' => 'custom_integration',
            'requested_note' => 'Harici destek gerekli',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $request), [
                'apply_note' => '',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHasErrors(['apply_note']);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $request), [
                'apply_note' => 'Manuel hizmet tamamlandı',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHas('success');

        $this->assertSame(TenantUpgradeRequest::STATUS_APPLIED, $request->fresh()->status);
        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_account_id' => $this->tenant->id,
        ]);
        $this->assertDatabaseMissing('tenant_supplier_access', [
            'tenant_account_id' => $this->tenant->id,
        ]);
    }

    public function test_non_approved_or_closed_requests_cannot_be_applied_and_access_is_restricted(): void
    {
        $pending = $this->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_service_key' => 'pending_case',
        ]);
        $rejected = $this->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_REJECTED,
            'requested_service_key' => 'rejected_case',
        ]);
        $applied = $this->createRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_APPLIED,
            'requested_service_key' => 'applied_case',
            'applied_by_user_id' => $this->platformAdmin->id,
            'applied_at' => now(),
        ]);

        foreach ([$pending, $rejected, $applied] as $item) {
            $this->actingAs($this->platformAdmin, 'web')
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post(route('admin.super.upgrade-requests.apply', $item), [
                    'apply_note' => '<script>alert("xss")</script>Not',
                ])
                ->assertRedirect(route('admin.super.upgrade-requests.show', $item))
                ->assertSessionHas('error');
        }

        $tenantOwner = $this->createTenantUser($this->tenant, 'apply-owner@example.test', 'tenant_owner');

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $pending), [
                'apply_note' => 'Yetkisiz',
            ])
            ->assertForbidden();

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $pending), [
                'apply_note' => 'Anonim',
            ])
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->post('http://' . $this->tenantHost($this->tenant) . '/admin/super-admin/upgrade-requests/' . $pending->id . '/apply', [
                'apply_note' => 'Central access test',
            ])
            ->assertForbidden();

        $blockedAudit = AuditLog::query()
            ->where('action', 'tenant_upgrade_request_apply_blocked')
            ->where('entity_type', 'tenant_upgrade_request')
            ->where('entity_id', $pending->id)
            ->latest()
            ->firstOrFail();

        $this->assertStringNotContainsString('<script>', json_encode($blockedAudit->new_values));
    }

    public function test_apply_event_appears_in_timeline_and_old_package_only_flow_stays_working(): void
    {
        $request = $this->createApprovedRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_service_key' => 'timeline_case',
            'requested_note' => '<script>note</script>Zaman çizgisi',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $request), [
                'apply_note' => '<script>apply</script>Manuel tamamlandı',
            ])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.show', $request));

        $show->assertOk();
        $show->assertSee('Talep uygulandı');
        $show->assertSee('Manuel hizmet tamamlandı olarak kapatıldı.');
        $show->assertDontSee('<script>', false);

        $legacy = \App\Models\TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'current_package_key' => 'starter',
            'requested_package_key' => 'promotion',
            'status' => \App\Models\TenantPackageUpgradeRequest::STATUS_NEW,
        ]);

        $legacyShow = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.package-requests.show', $legacy));

        $legacyShow->assertOk();
        $legacyShow->assertSee('Paket Karar Paneli');
    }

    private function createApprovedRequest(array $attributes): TenantUpgradeRequest
    {
        return $this->createRequest(array_merge($attributes, [
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'reviewed_by_user_id' => $this->platformAdmin->id,
            'reviewed_at' => now(),
        ]));
    }

    private function createRequest(array $attributes): TenantUpgradeRequest
    {
        return TenantUpgradeRequest::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'meta_json' => [],
        ], $attributes));
    }

    private function createTenant(string $subdomain, string $packageKey): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Apply Tenant ' . $subdomain,
            'legal_name' => 'Apply Tenant ' . $subdomain . ' Ltd.',
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
            'name' => 'Apply User ' . $email,
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
