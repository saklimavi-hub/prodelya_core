<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\TenantSignupRequest;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ProductDataHub\ProductHubOperationFlowService;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SuperAdminOperationDashboardFinalSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private Role $tenantAdminRole;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_dashboard_renders_final_operational_sections_and_safe_queue_content(): void
    {
        $package = Package::query()->where('status', 'active')->firstOrFail();

        $this->seedSignupQueue($package);
        $this->seedUpgradeQueue();
        $this->seedProductHubRows();

        $mock = Mockery::mock(ProductHubOperationFlowService::class);
        $mock->shouldReceive('buildOverview')->andReturn([
            'counts' => [
                'auto_updated' => 4,
                'review_required' => 3,
                'projection_issues' => 2,
            ],
        ]);
        $this->app->instance(ProductHubOperationFlowService::class, $mock);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Operasyon Özeti');
        $response->assertSee('Aksiyon Gerektirenler');
        $response->assertSee('Canlıya Hazırlık');
        $response->assertSee('Başvuru ve Satış Akışı');
        $response->assertSee('Abone Firma Talepleri');
        $response->assertSee('Product Data Hub Durumu');
        $response->assertSee('Sistem Sağlığı');
        $response->assertSee('Son Operasyonlar');
        $response->assertSee('Canlı Güvenlik Uyarıları');

        $response->assertSee('Dönüşüm Önizlemesi');
        $response->assertSee('Onboarding Hazırlığını Gör');
        $response->assertSee('Uygulama Bekliyor');
        $response->assertSee('Güvenlik Kontrolüne Takıldı');
        $response->assertSee('Uygulama Hatası');
        $response->assertSee('İncelemeyi Aç');
        $response->assertSee('Kataloğa Yansıtma');
        $response->assertSee('Kontrol Gerekir');

        $response->assertDontSee('owner_temporary_password', false);
        $response->assertDontSee('temporary password', false);
        $response->assertDontSee('smtp_password', false);
        $response->assertDontSee('auth_token', false);
        $response->assertDontSee('api key', false);
        $response->assertDontSee('secret', false);
        $response->assertDontSee('<script>', false);
        $response->assertDontSee(base_path(), false);
        $response->assertDontSee(storage_path(), false);
    }

    public function test_dashboard_action_queue_includes_sources_priorities_and_action_labels(): void
    {
        $package = Package::query()->where('status', 'active')->firstOrFail();

        $this->seedSignupQueue($package);
        $this->seedUpgradeQueue();

        $mock = Mockery::mock(ProductHubOperationFlowService::class);
        $mock->shouldReceive('buildOverview')->andReturn([
            'counts' => [
                'auto_updated' => 1,
                'review_required' => 2,
                'projection_issues' => 4,
            ],
        ]);
        $this->app->instance(ProductHubOperationFlowService::class, $mock);

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();

        $this->assertTrue(collect($context['action_queue']['critical'])->contains(
            fn (array $item): bool => $item['source'] === 'upgrade_request'
                && $item['action_label'] === 'Kuyruğu Aç'
                && $item['is_actionable'] === true
        ));
        $this->assertTrue(collect($context['action_queue']['critical'])->contains(
            fn (array $item): bool => $item['source'] === 'product_data_hub'
                && $item['action_label'] === 'Detaya Git'
        ));
        $this->assertTrue(collect($context['action_queue']['today'])->contains(
            fn (array $item): bool => $item['source'] === 'signup'
                && in_array($item['action_label'], ['İncele', 'Kuyruğu Aç'], true)
        ));

        $critical = $context['action_queue']['critical'];
        $this->assertGreaterThanOrEqual($critical[1]['priority'] ?? 0, $critical[0]['priority'] ?? 0);
    }

    public function test_temporary_password_value_is_hidden_on_tenant_show_and_edit(): void
    {
        $password = 'Secret-Only-Once-123';

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withSession(['owner_temporary_password' => $password])
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $this->tenant));

        $show->assertOk();
        $show->assertSee('Geçici giriş bilgisi güvenlik nedeniyle gösterilmez.');
        $show->assertDontSee($password, false);
        $show->assertDontSee('Geçici owner şifresi', false);

        $edit = $this->actingAs($this->platformAdmin, 'web')
            ->withSession(['owner_temporary_password' => $password])
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.edit', $this->tenant));

        $edit->assertOk();
        $edit->assertSee('Geçici giriş bilgisi güvenlik nedeniyle gösterilmez.');
        $edit->assertDontSee($password, false);
        $edit->assertDontSee('Geçici owner şifresi', false);
    }

    public function test_tenant_public_and_tenant_host_access_is_blocked_for_dashboard(): void
    {
        $tenantHostTarget = TenantAccount::query()->create([
            'name' => 'Tenant Host Smoke',
            'legal_name' => 'Tenant Host Smoke Ltd.',
            'slug' => 'tenant-host-smoke',
            'panel_subdomain' => 'tenant-host-smoke',
            'status' => 'active',
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Dashboard User',
            'email' => 'tenant-dashboard-user@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'))
            ->assertRedirect(route('login'));

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin, 'web')
            ->get('http://' . $this->tenantHost($tenantHostTarget) . '/admin/super-admin/dashboard')
            ->assertForbidden();
    }

    private function seedSignupQueue(Package $package): void
    {
        TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Yeni Basvuru A.S.',
            'contact_name' => 'Yeni Yetkili',
            'phone' => '05550000001',
            'email' => 'yeni-basvuru@example.test',
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'status' => TenantSignupRequest::STATUS_NEW,
            'source' => 'public_landing',
            'note' => '<script>alert("xss")</script>',
        ]);

        $contacted = TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Gorusuldu Firma',
            'contact_name' => 'Gorusme Yetkili',
            'phone' => '05550000002',
            'email' => 'gorusuldu@example.test',
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'status' => TenantSignupRequest::STATUS_CONTACTED,
            'source' => 'public_landing',
        ]);

        AuditLog::log([
            'user_id' => $this->platformAdmin->id,
            'action' => 'signup_request_conversion_preview_opened',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $contacted->id,
            'new_values' => ['note_preview' => 'preview'],
            'notes' => 'Önizleme açıldı.',
        ]);

        $convertedTenant = TenantAccount::query()->create([
            'name' => 'Onboarding Eksik Firma',
            'legal_name' => 'Onboarding Eksik Firma Ltd.',
            'slug' => 'onboarding-eksik-firma',
            'panel_subdomain' => 'onboarding-eksik-firma',
            'status' => 'active',
            'package_key' => $package->key,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_DEMO,
            'company_name' => 'Donusen Demo',
            'contact_name' => 'Demo Yetkili',
            'phone' => '05550000003',
            'email' => 'donusen-demo@example.test',
            'requested_package_id' => $package->id,
            'requested_package_key' => $package->key,
            'status' => TenantSignupRequest::STATUS_CONVERTED,
            'source' => 'public_landing',
            'converted_tenant_account_id' => $convertedTenant->id,
        ]);
    }

    private function seedUpgradeQueue(): void
    {
        TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'current_package_key' => $this->tenant->package_key,
            'requested_package_key' => 'business',
            'requested_note' => 'Paket büyüsün',
        ]);

        $approved = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'requested_module_key' => 'customer_portal',
            'requested_note' => 'Müşteri portalı açılsın',
        ]);

        $blocked = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'requested_limit_key' => 'users',
            'current_limit_value' => 5,
            'requested_limit_value' => 12,
            'requested_note' => 'Limit artırılsın',
        ]);

        $failed = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'requested_service_key' => 'custom_integration',
            'requested_note' => 'Entegrasyon ihtiyacı',
        ]);

        TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_FEATURE_ADDON,
            'status' => TenantUpgradeRequest::STATUS_APPLIED,
            'requested_feature_key' => 'quote_approval',
            'requested_note' => 'Özellik açıldı',
            'applied_at' => now()->subHour(),
            'applied_by_user_id' => $this->platformAdmin->id,
        ]);

        AuditLog::log([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->platformAdmin->id,
            'action' => 'tenant_upgrade_request_apply_blocked',
            'entity_type' => 'tenant_upgrade_request',
            'entity_id' => $blocked->id,
            'new_values' => ['reason' => 'already active'],
            'notes' => 'Uygulama güvenlik kontrolüne takıldı.',
        ]);

        AuditLog::log([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->platformAdmin->id,
            'action' => 'tenant_upgrade_request_apply_failed',
            'entity_type' => 'tenant_upgrade_request',
            'entity_id' => $failed->id,
            'new_values' => ['reason' => 'temporary password token secret'],
            'notes' => 'Uygulama sırasında hata alındı.',
        ]);
    }

    private function seedProductHubRows(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Akis Tedarik',
            'code' => 'AKIS',
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Akis XML',
            'url' => 'https://example.test/akis.xml',
            'status' => 'active',
            'last_sync_at' => now()->subHours(4),
            'config' => ['profile_key' => 'AKIS-XML'],
        ]);

        SupplierCategoryMapping::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'supplier_category_code' => 'PROMO-001',
            'source_category' => 'Promosyon',
            'target_category' => 'Bekleyen Kategori',
            'mapping_status' => 'needs_review',
            'is_active' => true,
            'product_count' => 12,
        ]);
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return ($tenant->panel_subdomain ?: $tenant->slug) . '.' . self::CENTRAL_HOST;
    }
}
