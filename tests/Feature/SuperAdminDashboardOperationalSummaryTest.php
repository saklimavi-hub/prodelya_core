<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SuperAdminDashboardSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminDashboardOperationalSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_super_admin_dashboard_uses_real_operational_summary_data(): void
    {
        $warningTenant = $this->createTenant('uyari-tenant', 'active');
        $this->assignTenantUser($warningTenant, 'warning-owner@example.test', $this->tenantOwnerRole);
        $this->assignTenantUser($warningTenant, 'warning-admin@example.test', $this->adminRole);
        TenantSetting::setValue($warningTenant->id, 'limit_users', 1, 'integer');

        $missingOwnerTenant = $this->createTenant('owner-eksik', 'active');

        $suspendedTenant = $this->createTenant('suspended-tenant', 'suspended');

        $summary = app(SuperAdminDashboardSummaryService::class)->build();

        $this->assertGreaterThanOrEqual(1, collect($summary['summaryCards'])->firstWhere('label', 'Toplam Abone Firma')['value']);
        $this->assertSame(
            1,
            collect($summary['summaryCards'])->firstWhere('label', 'Süresi Dolmuş / Askıda')['value']
        );

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Super Admin Operasyon Merkezi');
        $response->assertSee('Toplam Abone Firma');
        $response->assertSee('Deneme Sürecinde');
        $response->assertSee('Aksiyon Gerektirenler');
        $response->assertSee('Panel Yetkilisi eksik');
        $response->assertSee('Paket / Limit Uyarısı');
        $response->assertSee('Süresi Dolmuş / Askıda');
        $response->assertSee('Canlıya Hazırlık');
        $response->assertSee('Başvuru ve Satış Akışı');
        $response->assertSee('Abone Firma Talepleri');
        $response->assertSee($missingOwnerTenant->name);
        $response->assertSee($warningTenant->name);
        $response->assertSee($suspendedTenant->name);
        $response->assertSee('Son 7 Gün Açılan Abone Firmalar');
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
