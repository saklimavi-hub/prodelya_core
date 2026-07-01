<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantOpsCenterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $tenant;
    private Role $tenantAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_super_admin_show_and_edit_screens_show_consolidated_ops_center_blocks(): void
    {
        $showResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $this->tenant));

        $showResponse->assertOk();
        $showResponse->assertSee('Abone Firma Cari / Fatura Bilgileri');
        $showResponse->assertSee('SaaS Cari Özet');
        $showResponse->assertSee('Hızlı İşlemler');
        $showResponse->assertSee('Domain / Abonelik Geçmişi');
        $showResponse->assertSee('Domain Yaşam Döngüsü / DNS-SSL Görünürlüğü');
        $showResponse->assertSee('tenant tarafında ise modül olarak açılacaktır');

        $editResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.edit', $this->tenant));

        $editResponse->assertOk();
        $editResponse->assertSee('Cari / Fatura Bilgileri');
        $editResponse->assertSee('Panel ve Domain');
        $editResponse->assertSee('Abonelik Durumu');
        $editResponse->assertSee('SaaS Cari');
        $editResponse->assertSee('Son Lifecycle / Domain Geçmişi');
        $editResponse->assertSee('Domain Operasyon Notu');
        $editResponse->assertSee('Özel Domain SSL');
        $editResponse->assertSee(route('admin.super.tenants.billing.index', $this->tenant), false);
    }

    public function test_tenant_admin_cannot_access_super_admin_ops_center(): void
    {
        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-ops-center-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.edit', $this->tenant))
            ->assertForbidden();
    }
}
