<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantAccessService;
use App\Services\TenantUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUpgradeRequestFinalSecuritySmokeTest extends TestCase
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
        $this->tenant = $this->createTenant('upgrade-final-a', 'starter');
        $this->tenantAdmin = $this->createTenantUser($this->tenant, 'upgrade-final-admin@example.test', 'admin');
    }

    public function test_full_package_upgrade_chain_is_secure_and_visible_to_tenant(): void
    {
        $request = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'requested_package_key' => 'promotion',
            'requested_note' => '<script>alert("xss")</script>Paket büyütelim',
        ]);

        $this->approveAndApply($request, 'Paket geçişi güvenli tamamlandı');

        $request = $request->fresh();
        $this->assertSame(TenantUpgradeRequest::STATUS_APPLIED, $request->status);
        $this->assertNotNull($request->applied_by_user_id);
        $this->assertNotNull($request->applied_at);
        $this->assertSame('promotion', $this->tenant->fresh()->package_key);
        $this->assertSame('starter', data_get($request->meta_json, 'apply_summary.old_package_key'));
        $this->assertSame('promotion', data_get($request->meta_json, 'apply_summary.new_package_key'));

        $screen = $this->tenantGet(route('admin.my-package.index'));
        $screen->assertOk();
        $screen->assertSee('Paketim ve Kullanımım');
        $screen->assertSee('promotion', false);
        $screen->assertSee('Talep Merkezi');

        $detail = $this->superGet(route('admin.super.upgrade-requests.show', $request));
        $detail->assertOk();
        $detail->assertSee('Talep uygulandı');
        $detail->assertSee('Uygulama Özeti');
        $detail->assertDontSee('Talebi Uygula', false);
        $detail->assertDontSee('<script>', false);
    }

    public function test_full_module_and_feature_addon_chain_opens_access_and_route_visibility(): void
    {
        $moduleRequest = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
            'requested_note' => 'Portal modulu acilsin',
        ]);
        $featureRequest = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_FEATURE_ADDON,
            'requested_feature_key' => 'public_quote_approval',
        ]);

        $this->approveAndApply($moduleRequest, 'Modul acildi');
        $this->approveAndApply($featureRequest, 'Ozellik acildi');

        /** @var TenantAccessService $access */
        $access = app(TenantAccessService::class);
        $tenant = $this->tenant->fresh();

        $this->assertDatabaseHas('tenant_modules', [
            'tenant_account_id' => $tenant->id,
            'module_key' => 'customer_portal',
            'feature_key' => null,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('tenant_modules', [
            'tenant_account_id' => $tenant->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => true,
        ]);
        $this->assertTrue($access->canAccessModule($tenant, 'customer_portal'));
        $this->assertTrue($access->canAccessFeature($tenant, 'public_quote_approval', 'quote_customer_approval'));

        $center = $this->tenantGet(route('admin.upgrade-requests.index'));
        $center->assertOk();
        $center->assertSee('Talep Merkezi');
        $center->assertSee('Uygulandı');

        $show = $this->superGet(route('admin.super.upgrade-requests.show', $moduleRequest->fresh()));
        $show->assertSee('Talep Zaman Çizgisi');
        $show->assertSee('Talep uygulandı');
        $show->assertDontSee('Talebi Uygula', false);
    }

    public function test_full_limit_increase_chain_updates_usage_snapshot_and_package_screen(): void
    {
        TenantSetting::setValue($this->tenant->id, 'limit_orders', 10, 'integer');

        $request = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'requested_limit_key' => 'orders',
            'requested_limit_value' => 25,
            'requested_note' => 'Siparis limiti artsin',
        ]);

        $this->approveAndApply($request, 'Limit guncellendi');

        $this->assertSame(25, TenantSetting::getValue($this->tenant->id, 'limit_orders'));

        /** @var TenantUsageService $usage */
        $usage = app(TenantUsageService::class);
        $snapshot = $usage->getUsageForKey($this->tenant->fresh(), 'orders');
        $this->assertSame(25, $snapshot['limit']);

        $screen = $this->tenantGet(route('admin.my-package.index'));
        $screen->assertOk();
        $screen->assertSee('25');
        $screen->assertSee('Kullanımım');
    }

    public function test_full_supplier_access_chain_creates_access_without_extra_mutation(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Final Supplier',
            'code' => 'FINAL-SUP',
            'status' => 'active',
        ]);

        $request = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
            'requested_supplier_id' => $supplier->id,
            'requested_supplier_key' => $supplier->code,
            'requested_note' => 'Tedarikci acilsin',
        ]);

        $beforeCount = TenantSupplierAccess::query()->count();
        $this->approveAndApply($request, 'Tedarikci erisimi acildi');

        $this->assertSame($beforeCount + 1, TenantSupplierAccess::query()->count());
        $this->assertDatabaseHas('tenant_supplier_access', [
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => false,
        ]);
    }

    public function test_service_request_requires_apply_note_and_does_not_mutate_access(): void
    {
        $request = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_service_key' => 'custom_training',
            'requested_note' => '<script>alert("xss")</script>Yerinde egitim istiyoruz',
        ]);

        $this->superPost(route('admin.super.upgrade-requests.approve', $request), [
            'admin_note' => '<script>alert("xss")</script>Uygun',
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $this->superPost(route('admin.super.upgrade-requests.apply', $request), [
            'apply_note' => '',
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHasErrors(['apply_note']);

        $beforeModules = TenantModule::query()->count();
        $beforeSuppliers = TenantSupplierAccess::query()->count();

        $this->superPost(route('admin.super.upgrade-requests.apply', $request), [
            'apply_note' => '<script>alert("xss")</script>Manuel hizmet tamamlandi',
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHas('success');

        $this->assertSame(TenantUpgradeRequest::STATUS_APPLIED, $request->fresh()->status);
        $this->assertSame($beforeModules, TenantModule::query()->count());
        $this->assertSame($beforeSuppliers, TenantSupplierAccess::query()->count());

        $show = $this->superGet(route('admin.super.upgrade-requests.show', $request->fresh()));
        $show->assertSee('Manuel hizmet tamamlandı');
        $show->assertDontSee('<script>', false);
    }

    public function test_tenant_and_public_access_boundaries_and_duplicate_guard_hold_in_final_flow(): void
    {
        $otherTenant = $this->createTenant('upgrade-final-b', 'starter');
        $otherAdmin = $this->createTenantUser($otherTenant, 'upgrade-final-other@example.test', 'admin');
        $tenantOwner = $this->createTenantUser($this->tenant, 'upgrade-final-owner@example.test', 'tenant_owner');

        $request = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'requested_by_user_id' => $otherAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_service_key' => 'other_case',
            'requested_note' => 'Bu kayıt görünmemeli',
        ]);

        $this->tenantGet(route('admin.upgrade-requests.index'))->assertDontSee('Bu kayıt görünmemeli.');

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.index'))
            ->assertForbidden();

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.upgrade-requests.apply', $request), [
                'apply_note' => 'Yetkisiz',
            ])
            ->assertForbidden();

        auth('web')->logout();

        $tenantAnonymous = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.upgrade-requests.index'));
        $this->assertTrue(in_array($tenantAnonymous->getStatusCode(), [302, 403], true));

        $superAnonymous = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.upgrade-requests.index'));
        $this->assertTrue(in_array($superAnonymous->getStatusCode(), [302, 403], true));

        $tenantHostRequest = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'requested_service_key' => 'tenant_host_guard',
            'reviewed_by_user_id' => $this->platformAdmin->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->post('http://' . $this->tenantHost($this->tenant) . '/admin/super-admin/upgrade-requests/' . $tenantHostRequest->id . '/apply', [
                'apply_note' => 'Central access guard',
            ])
            ->assertForbidden();

        $this->tenantPost(route('admin.upgrade-requests.store'), [
            'tenant_account_id' => $otherTenant->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_service_key' => 'isolation_attempt',
            'requested_note' => 'Karsi tenant adina talep',
        ])->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'service_request']));

        $this->assertDatabaseMissing('tenant_upgrade_requests', [
            'tenant_account_id' => $otherTenant->id,
            'requested_service_key' => 'isolation_attempt',
        ]);

        $first = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ]);

        $this->actingAs($this->tenantAdmin, 'web')
            ->from(route('admin.upgrade-requests.index', ['type' => 'module_addon']))
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
                'requested_module_key' => 'customer_portal',
            ])->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'module_addon']))
            ->assertSessionHasErrors(['requested_module_key']);

        $first->update(['status' => TenantUpgradeRequest::STATUS_REJECTED]);

        $this->tenantPost(route('admin.upgrade-requests.store'), [
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ])->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'module_addon']));
    }

    public function test_apply_guard_and_audit_chain_hold_when_conditions_change_before_apply(): void
    {
        $package = Package::query()->create([
            'key' => 'final-temp-package',
            'name' => 'Final Temp Package',
            'status' => 'active',
            'is_public' => true,
        ]);

        $packageRequest = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'requested_package_key' => $package->key,
        ]);
        $this->superPost(route('admin.super.upgrade-requests.approve', $packageRequest), ['admin_note' => 'Onaylandı']);
        $package->update(['status' => 'passive']);

        $this->superPost(route('admin.super.upgrade-requests.apply', $packageRequest), ['apply_note' => 'Deneme'])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $packageRequest))
            ->assertSessionHas('error');

        $this->assertSame(TenantUpgradeRequest::STATUS_APPROVED, $packageRequest->fresh()->status);

        $moduleRequest = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'requested_module_key' => 'customer_portal',
        ]);
        $this->superPost(route('admin.super.upgrade-requests.approve', $moduleRequest), ['admin_note' => 'Onaylandı']);
        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $this->tenant->id, 'module_key' => 'customer_portal', 'feature_key' => null],
            ['is_enabled' => true]
        );
        $this->superPost(route('admin.super.upgrade-requests.apply', $moduleRequest), ['apply_note' => 'Deneme'])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $moduleRequest))
            ->assertSessionHas('error');

        $featureRequest = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_FEATURE_ADDON,
            'requested_feature_key' => 'public_quote_approval',
        ]);
        $this->superPost(route('admin.super.upgrade-requests.approve', $featureRequest), ['admin_note' => 'Onaylandı']);
        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $this->tenant->id, 'module_key' => 'quote_customer_approval', 'feature_key' => 'public_quote_approval'],
            ['is_enabled' => true]
        );
        $this->superPost(route('admin.super.upgrade-requests.apply', $featureRequest), ['apply_note' => 'Deneme'])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $featureRequest))
            ->assertSessionHas('error');

        TenantSetting::setValue($this->tenant->id, 'limit_orders', 10, 'integer');
        $limitRequest = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'requested_limit_key' => 'orders',
            'requested_limit_value' => 99,
        ]);
        $this->superPost(route('admin.super.upgrade-requests.approve', $limitRequest), ['admin_note' => 'Onaylandı']);
        TenantSetting::setValue($this->tenant->id, 'limit_orders', 'unlimited', 'string');
        $this->superPost(route('admin.super.upgrade-requests.apply', $limitRequest), ['apply_note' => 'Deneme'])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $limitRequest))
            ->assertSessionHas('error');

        $supplier = Supplier::query()->create([
            'name' => 'Final Guard Supplier',
            'code' => 'FINAL-GUARD',
            'status' => 'active',
        ]);
        $supplierRequest = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
            'requested_supplier_id' => $supplier->id,
            'requested_supplier_key' => $supplier->code,
        ]);
        $this->superPost(route('admin.super.upgrade-requests.approve', $supplierRequest), ['admin_note' => 'Onaylandı']);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => false,
        ]);
        $this->superPost(route('admin.super.upgrade-requests.apply', $supplierRequest), ['apply_note' => 'Deneme'])
            ->assertRedirect(route('admin.super.upgrade-requests.show', $supplierRequest))
            ->assertSessionHas('error');

        $blockedLogs = AuditLog::query()
            ->where('action', 'tenant_upgrade_request_apply_blocked')
            ->where('entity_type', 'tenant_upgrade_request')
            ->count();

        $this->assertGreaterThanOrEqual(5, $blockedLogs);
    }

    public function test_full_audit_chain_and_legacy_package_only_flow_stay_intact(): void
    {
        $request = $this->createTenantRequest([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'requested_service_key' => 'final_chain',
            'requested_note' => '<script>alert("xss")</script>Final zincir',
        ]);

        $this->superPost(route('admin.super.upgrade-requests.in-review', $request), [
            'admin_note' => 'İlk değerlendirme',
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $this->superPost(route('admin.super.upgrade-requests.approve', $request), [
            'admin_note' => '<script>alert("xss")</script>Uygun bulundu',
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $this->superPost(route('admin.super.upgrade-requests.apply', $request), [
            'apply_note' => '<script>alert("xss")</script>Manuel tamamlandi',
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $detail = $this->superGet(route('admin.super.upgrade-requests.show', $request->fresh()));
        $detail->assertOk();
        $detail->assertSee('Talep Zaman Çizgisi');
        $detail->assertSee('Talep oluşturuldu');
        $detail->assertSee('Talep incelemeye alındı');
        $detail->assertSee('Talep onaylandı');
        $detail->assertSee('Talep uygulandı');
        $detail->assertDontSee('<script>', false);

        $json = AuditLog::query()
            ->where('entity_type', 'tenant_upgrade_request')
            ->where('entity_id', $request->id)
            ->pluck('new_values')
            ->map(fn ($value) => json_encode($value))
            ->implode(' ');

        $this->assertStringNotContainsString('<script>', $json);
        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('token', strtolower($json));
        $this->assertStringNotContainsString('secret', strtolower($json));
        $this->assertStringNotContainsString('smtp_password', strtolower($json));

        $legacy = TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'current_package_key' => 'starter',
            'requested_package_key' => 'promotion',
            'status' => TenantPackageUpgradeRequest::STATUS_NEW,
            'request_note' => 'Legacy akış devam etmeli',
        ]);

        $this->tenantPost(route('admin.package-requests.store'), [
            'requested_package_key' => 'promotion',
            'request_note' => 'Eski tenant akışı',
        ])->assertRedirect(route('admin.package-requests.index'));

        $legacyShow = $this->superGet(route('admin.super.package-requests.show', $legacy));
        $legacyShow->assertOk();
        $legacyShow->assertSee('Paket Karar Paneli');
    }

    private function approveAndApply(TenantUpgradeRequest $request, string $applyNote): void
    {
        $this->superPost(route('admin.super.upgrade-requests.in-review', $request), [
            'admin_note' => 'İncelemeye alındı',
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $this->superPost(route('admin.super.upgrade-requests.approve', $request), [
            'admin_note' => 'Onaylandı',
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request));

        $approvedShow = $this->superGet(route('admin.super.upgrade-requests.show', $request->fresh()));
        $approvedShow->assertOk();
        $approvedShow->assertSee('Talebi Uygula');

        $this->superPost(route('admin.super.upgrade-requests.apply', $request), [
            'apply_note' => $applyNote,
        ])->assertRedirect(route('admin.super.upgrade-requests.show', $request))
            ->assertSessionHas('success');
    }

    private function createTenantRequest(array $attributes): TenantUpgradeRequest
    {
        $payload = array_merge([
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
        ], $attributes);

        $response = $this->tenantPost(route('admin.upgrade-requests.store'), $payload);

        $response->assertRedirect(route('admin.upgrade-requests.index', ['type' => $payload['request_type']]));

        return TenantUpgradeRequest::query()->latest('id')->firstOrFail();
    }

    private function superGet(string $url)
    {
        return $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($url);
    }

    private function superPost(string $url, array $payload = [])
    {
        return $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post($url, $payload);
    }

    private function tenantGet(string $url)
    {
        return $this->actingAs($this->tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get($url);
    }

    private function tenantPost(string $url, array $payload = [])
    {
        return $this->actingAs($this->tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post($url, $payload);
    }

    private function createTenant(string $subdomain, string $packageKey): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Final Tenant ' . $subdomain,
            'legal_name' => 'Final Tenant ' . $subdomain . ' Ltd.',
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
            'name' => 'Final User ' . $email,
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
