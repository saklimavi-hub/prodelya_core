<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUpgradeRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $tenantAdmin;
    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->create([
            'name' => 'Upgrade Tenant',
            'legal_name' => 'Upgrade Tenant Ltd.',
            'slug' => 'upgrade-tenant',
            'panel_subdomain' => 'upgrade-tenant',
            'status' => 'active',
            'package_key' => Package::query()->where('status', 'active')->value('key') ?? 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'upgrade-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->tenantAdmin->id,
            'role_id' => Role::query()->where('key', 'admin')->value('id'),
        ]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_tenant_admin_can_create_package_request_and_super_admin_sees_it(): void
    {
        $requestedPackage = Package::query()
            ->where('status', 'active')
            ->where('key', '!=', $this->tenant->package_key)
            ->firstOrFail();

        $this->actingAs($this->tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post(route('admin.package-requests.store'), [
                'requested_package_key' => $requestedPackage->key,
                'request_note' => 'Kullanıcı ve modül ihtiyacı arttı.',
            ])
            ->assertRedirect(route('admin.package-requests.index'))
            ->assertSessionHas('success');

        $request = TenantPackageUpgradeRequest::query()->firstOrFail();
        $this->assertSame($this->tenant->id, $request->tenant_account_id);
        $this->assertSame($requestedPackage->key, $request->requested_package_key);
        $this->assertSame(TenantPackageUpgradeRequest::STATUS_NEW, $request->status);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.package-requests.index'))
            ->assertOk()
            ->assertSee('Paket Talepleri')
            ->assertSee('Upgrade Tenant')
            ->assertSee($requestedPackage->name);
    }

    public function test_same_package_request_is_rejected(): void
    {
        $this->actingAs($this->tenantAdmin, 'web')
            ->from(route('admin.package-requests.index'))
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post(route('admin.package-requests.store'), [
                'requested_package_key' => $this->tenant->package_key,
            ])
            ->assertRedirect(route('admin.package-requests.index'))
            ->assertSessionHasErrors(['requested_package_key']);
    }

    public function test_super_admin_can_approve_and_apply_request(): void
    {
        $requestedPackage = Package::query()
            ->where('status', 'active')
            ->where('key', '!=', $this->tenant->package_key)
            ->firstOrFail();

        $request = TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'requested_by_user_id' => $this->tenantAdmin->id,
            'current_package_key' => $this->tenant->package_key,
            'requested_package_key' => $requestedPackage->key,
            'status' => TenantPackageUpgradeRequest::STATUS_NEW,
            'request_note' => 'Business pakete geçmek istiyoruz.',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.super.package-requests.status.update', $request), [
                'status' => TenantPackageUpgradeRequest::STATUS_APPROVED,
                'admin_note' => 'Uygun bulundu.',
            ])
            ->assertRedirect(route('admin.super.package-requests.show', $request))
            ->assertSessionHas('success');

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.package-requests.apply', $request))
            ->assertRedirect(route('admin.super.package-requests.show', $request))
            ->assertSessionHas('success');

        $this->assertSame($requestedPackage->key, $this->tenant->fresh()->package_key);
        $this->assertSame(TenantPackageUpgradeRequest::STATUS_COMPLETED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->applied_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'package_request_status_updated',
            'entity_type' => 'tenant_package_upgrade_request',
            'entity_id' => $request->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'package_request_applied',
            'entity_type' => 'tenant_package_upgrade_request',
            'entity_id' => $request->id,
        ]);

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.package-requests.show', $request));

        $show->assertOk();
        $show->assertSee('Zaman Çizgisi / Log');
        $show->assertSee('Paket tenant üzerine uygulandı');
    }

    public function test_tenant_admin_cannot_access_super_admin_package_request_screens(): void
    {
        $this->actingAs($this->tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.package-requests.index'))
            ->assertForbidden();
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
