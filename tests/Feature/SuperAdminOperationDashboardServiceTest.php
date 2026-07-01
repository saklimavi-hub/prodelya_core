<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\TenantSignupRequest;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ProductDataHub\ProductHubOperationFlowService;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SuperAdminOperationDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private Role $tenantAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_dashboard_context_builds_expected_sections_and_summaries(): void
    {
        $readyTenant = $this->createTenant('hazir-tenant', 'active');
        $this->assignTenantUser($readyTenant, 'ready-owner@example.test', $this->tenantOwnerRole);
        $this->assignTenantUser($readyTenant, 'ready-admin@example.test', $this->tenantAdminRole);
        TenantSetting::setValue($readyTenant->id, 'company_display_name', 'Hazır Tenant A.Ş.', 'string');
        TenantSetting::setValue($readyTenant->id, 'company_email', 'hello@ready.test', 'string');
        TenantSetting::setValue($readyTenant->id, 'smtp_host', 'smtp.ready.test', 'string');
        TenantSetting::setValue($readyTenant->id, 'smtp_from_email', 'notify@ready.test', 'string');
        TenantSetting::setValue($readyTenant->id, 'whatsapp_test_phone', '905551112233', 'string');
        TenantSetting::setValue($readyTenant->id, 'work_folder_root_name', 'ISLER', 'string');

        $blockedTenant = $this->createTenant('bloklu-tenant', 'suspended');

        $signupRequest = TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Nova Plastik',
            'contact_name' => 'Ayşe Demo',
            'phone' => '05551234567',
            'email' => 'nova@example.test',
            'requested_package_key' => Package::query()->where('status', 'active')->value('key'),
            'status' => TenantSignupRequest::STATUS_CONTACTED,
            'source' => 'public_landing',
        ]);

        AuditLog::log([
            'user_id' => $this->platformAdmin->id,
            'action' => 'signup_request_conversion_preview_opened',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $signupRequest->id,
            'new_values' => ['note_preview' => '<script>alert(1)</script> password'],
            'notes' => 'Önizleme açıldı',
        ]);

        AuditLog::log([
            'tenant_account_id' => $readyTenant->id,
            'user_id' => $this->platformAdmin->id,
            'action' => 'signup_request_note_added',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => $signupRequest->id,
            'new_values' => ['note_preview' => '<script>alert("xss")</script> gizli token'],
            'notes' => 'Operasyon notu eklendi.',
        ]);

        TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $readyTenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'current_package_key' => $readyTenant->package_key,
            'requested_package_key' => Package::query()->where('key', '!=', $readyTenant->package_key)->value('key') ?? $readyTenant->package_key,
            'requested_note' => 'Daha yüksek limite ihtiyaç var',
        ]);

        $blockedUpgrade = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $readyTenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'requested_limit_key' => 'users',
            'current_limit_value' => 5,
            'requested_limit_value' => 10,
            'requested_note' => 'Kullanıcı limiti artsın',
        ]);

        AuditLog::log([
            'tenant_account_id' => $readyTenant->id,
            'user_id' => $this->platformAdmin->id,
            'action' => 'tenant_upgrade_request_apply_blocked',
            'entity_type' => 'tenant_upgrade_request',
            'entity_id' => $blockedUpgrade->id,
            'new_values' => ['reason' => 'already active'],
            'notes' => 'Uygulama güvenlik kontrolüne takıldı.',
        ]);

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();

        $this->assertArrayHasKey('kpis', $context);
        $this->assertArrayHasKey('action_queue', $context);
        $this->assertArrayHasKey('tenant_readiness', $context);
        $this->assertArrayHasKey('signup_funnel', $context);
        $this->assertArrayHasKey('upgrade_requests', $context);
        $this->assertArrayHasKey('product_data_hub', $context);
        $this->assertArrayHasKey('system_health', $context);
        $this->assertArrayHasKey('recent_operations', $context);
        $this->assertArrayHasKey('security_warnings', $context);

        $this->assertGreaterThanOrEqual(2, $context['tenant_readiness']['counts']['total']);
        $this->assertGreaterThanOrEqual(1, $context['tenant_readiness']['counts']['blocked']);
        $this->assertSame(1, $context['signup_funnel']['counts']['contacted']);
        $this->assertSame(1, $context['signup_funnel']['counts']['preview_opened']);
        $this->assertSame(2, $context['upgrade_requests']['counts']['approved']);
        $this->assertSame(2, $context['upgrade_requests']['counts']['approved_but_unapplied']);
        $this->assertSame(1, $context['upgrade_requests']['counts']['apply_blocked']);
        $this->assertNotEmpty($context['action_queue']['critical']);
        $firstCritical = $context['action_queue']['critical'][0];
        $this->assertArrayHasKey('source', $firstCritical);
        $this->assertArrayHasKey('priority', $firstCritical);
        $this->assertArrayHasKey('action_label', $firstCritical);
        $this->assertArrayHasKey('severity_label', $firstCritical);
        $this->assertArrayHasKey('is_actionable', $firstCritical);
        $this->assertTrue(collect($context['action_queue']['critical'])->contains(
            fn (array $item): bool => $item['source'] === 'upgrade_request' && str_contains($item['title'], 'uygulanmamış')
        ));
        $this->assertTrue(collect($context['action_queue']['critical'])->contains(
            fn (array $item): bool => $item['source'] === 'upgrade_request' && str_contains($item['title'], 'Güvenlik kontrolüne takılan')
        ));
        $this->assertTrue(collect($context['action_queue']['today'])->contains(
            fn (array $item): bool => $item['source'] === 'signup' && str_contains($item['title'], 'Dönüşüm bekleyen')
        ));
        $this->assertTrue(collect($context['security_warnings'])->contains(fn (array $item) => $item['key'] === 'temporary_password_visibility'));
        $this->assertTrue(collect($context['security_warnings'])->contains(
            fn (array $item): bool => str_starts_with((string) ($item['key'] ?? ''), 'production_env_')
        ));
        $this->assertArrayHasKey('checked_at', $context['system_health']['queue_worker']);
        $this->assertContains($context['system_health']['queue_worker']['status'], ['healthy', 'warning', 'critical', 'unknown']);
        $this->assertStringNotContainsString('<script>', json_encode($context['recent_operations'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('password', strtolower((string) json_encode($context['recent_operations'], JSON_UNESCAPED_UNICODE)));
    }

    public function test_product_data_hub_summary_returns_safe_warning_when_operation_service_fails(): void
    {
        $mock = Mockery::mock(ProductHubOperationFlowService::class);
        $mock->shouldReceive('buildOverview')->once()->andThrow(new \RuntimeException('smtp_password cannot be read'));
        $this->app->instance(ProductHubOperationFlowService::class, $mock);

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();

        $this->assertNotEmpty($context['product_data_hub']['warnings']);
        $this->assertStringContainsString('Product Data Hub özeti hazırlanamadı', $context['product_data_hub']['warnings'][0]);
        $this->assertStringNotContainsString('smtp_password', strtolower($context['product_data_hub']['warnings'][0]));
    }

    public function test_controller_passes_operation_dashboard_context_to_view(): void
    {
        AuditLog::log([
            'user_id' => $this->platformAdmin->id,
            'action' => 'signup_request_note_added',
            'entity_type' => 'tenant_signup_request',
            'entity_id' => 999,
            'new_values' => ['note_preview' => '<script>alert("xss")</script> owner_temporary_password smtp_password'],
            'notes' => 'temporary password ve owner_temporary_password görünmemeli',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertViewHas('operationDashboard', function (array $context): bool {
            return isset($context['kpis'], $context['action_queue'], $context['tenant_readiness'], $context['system_health']);
        });
        $response->assertSee('Operasyon Özeti');
        $response->assertSee('Aksiyon Gerektirenler');
        $response->assertSee('Canlıya Hazırlık');
        $response->assertSee('Başvuru ve Satış Akışı');
        $response->assertSee('Abone Firma Talepleri');
        $response->assertSee('Product Data Hub Durumu');
        $response->assertSee('Sistem Sağlığı');
        $response->assertSee('Son Operasyonlar');
        $response->assertSee('Canlı Güvenlik Uyarıları');
        $response->assertDontSee('owner_temporary_password', false);
        $response->assertDontSee('temporary password', false);
        $response->assertDontSee('smtp_password', false);
        $response->assertDontSee('<script>', false);
    }

    public function test_dashboard_renders_safe_empty_states_without_action_buttons_for_empty_lists(): void
    {
        $mock = Mockery::mock(SuperAdminOperationDashboardService::class);
        $mock->shouldReceive('buildDashboardContext')->once()->andReturn([
            'kpis' => ['cards' => []],
            'action_queue' => ['critical' => [], 'today' => [], 'technical' => []],
            'tenant_readiness' => ['counts' => [], 'rows' => []],
            'signup_funnel' => ['counts' => [], 'rows' => []],
            'upgrade_requests' => ['counts' => [], 'rows' => []],
            'product_data_hub' => ['counts' => [], 'rows' => [], 'warnings' => []],
            'system_health' => [
                'queue_worker' => [
                    'label' => 'Kuyruk Çalışanı',
                    'status' => 'unknown',
                    'status_label' => 'Bilinmiyor',
                    'description' => 'Veri hazırlanıyor.',
                    'checked_at' => now()->format('d.m.Y H:i'),
                    'route' => null,
                    'is_placeholder' => true,
                    'details' => [],
                ],
            ],
            'recent_operations' => [],
            'security_warnings' => [],
        ]);
        $this->app->instance(SuperAdminOperationDashboardService::class, $mock);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Şu an aksiyon gerektiren kayıt yok.');
        $response->assertDontSee('href=""', false);
    }

    public function test_dashboard_renders_action_labels_for_signup_and_upgrade_queues(): void
    {
        $tenant = $this->createTenant('aksiyon-tenant', 'active');

        $contactedRequest = TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => 'Aksiyon Başvuru',
            'contact_name' => 'Aksiyon Yetkili',
            'phone' => '05550111223',
            'email' => 'aksiyon-basvuru@example.test',
            'requested_package_key' => Package::query()->where('status', 'active')->value('key'),
            'status' => TenantSignupRequest::STATUS_CONTACTED,
            'source' => 'public_landing',
        ]);

        $approvedRequest = TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $tenant->id,
            'requested_by_user_id' => $this->platformAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
            'status' => TenantUpgradeRequest::STATUS_APPROVED,
            'requested_module_key' => 'customer_portal',
            'requested_note' => 'Ek modül istiyoruz',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee($contactedRequest->company_name);
        $response->assertSee('Dönüşüm Önizlemesi');
        $response->assertSee($approvedRequest->tenantAccount?->name ?? $tenant->name);
        $response->assertSee('Uygulama Bekliyor');
        $response->assertSee('Detaya Git');
    }

    public function test_dashboard_renders_product_data_hub_fallback_as_kontrol_gerekir(): void
    {
        $mock = Mockery::mock(ProductHubOperationFlowService::class);
        $mock->shouldReceive('buildOverview')->once()->andThrow(new \RuntimeException('api key missing'));
        $this->app->instance(ProductHubOperationFlowService::class, $mock);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Product Data Hub Durumu');
        $response->assertSee('Kontrol Gerekir');
        $response->assertSee('Product Data Hub özeti hazırlanamadı');
        $response->assertDontSee('api key', false);
    }

    public function test_dashboard_context_survives_missing_system_heartbeats_table(): void
    {
        Schema::drop('system_heartbeats');

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();

        $this->assertArrayHasKey('security_warnings', $context);
        $this->assertIsArray($context['security_warnings']);
        $this->assertNotEmpty($context['security_warnings']);
    }

    public function test_product_data_hub_warnings_are_added_to_action_queue(): void
    {
        $mock = Mockery::mock(ProductHubOperationFlowService::class);
        $mock->shouldReceive('buildOverview')->once()->andReturn([
            'counts' => [
                'auto_updated' => 2,
                'review_required' => 4,
                'projection_issues' => 3,
            ],
        ]);
        $this->app->instance(ProductHubOperationFlowService::class, $mock);

        $context = app(SuperAdminOperationDashboardService::class)->buildDashboardContext();

        $this->assertTrue(collect($context['action_queue']['critical'])->contains(
            fn (array $item): bool => $item['source'] === 'product_data_hub' && str_contains($item['title'], 'kataloğa yansıtma')
        ));
        $this->assertTrue(collect($context['action_queue']['today'])->contains(
            fn (array $item): bool => $item['source'] === 'product_data_hub' && str_contains($item['title'], 'İnceleme bekleyen')
        ));
    }

    public function test_tenant_and_public_users_cannot_access_super_admin_dashboard(): void
    {
        $tenant = $this->createTenant('tenant-admin', 'active');
        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'))
            ->assertRedirect(route('login'));

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'))
            ->assertForbidden();
    }

    private function createTenant(string $subdomain, string $status): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $subdomain)),
            'legal_name' => ucfirst(str_replace('-', ' ', $subdomain)) . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => $status,
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function assignTenantUser(TenantAccount $tenant, string $email, Role $role): void
    {
        $user = User::query()->create([
            'name' => strtok($email, '@'),
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
    }
}
