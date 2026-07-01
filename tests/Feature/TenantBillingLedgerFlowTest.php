<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantBillingEntry;
use App\Models\TenantServiceDefinition;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantBillingLedgerFlowTest extends TestCase
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

    public function test_super_admin_can_view_billing_screen_and_create_entries(): void
    {
        $service = TenantServiceDefinition::query()->where('service_code', 'SUPPORT')->firstOrFail();

        $screen = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.billing.index', $this->tenant));

        $screen->assertOk();
        $screen->assertSee('SaaS Cari Hareketleri');

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.billing.store', $this->tenant), [
                'tenant_service_definition_id' => $service->id,
                'entry_type' => 'service_fee',
                'title' => 'Ek Destek Hizmeti',
                'note' => 'Aylık ek destek kaydı.',
                'reference_no' => 'SUP-001',
                'direction' => 'debit',
                'amount' => 750,
                'currency' => 'TRY',
                'entry_date' => '2026-06-26',
            ])
            ->assertRedirect(route('admin.super.tenants.billing.index', $this->tenant))
            ->assertSessionHas('success');

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.billing.store', $this->tenant), [
                'entry_type' => 'collection',
                'title' => 'Kısmi Tahsilat',
                'note' => 'İlk tahsilat kaydı.',
                'reference_no' => 'COL-001',
                'direction' => 'credit',
                'amount' => 200,
                'currency' => 'TRY',
                'entry_date' => '2026-06-26',
            ])
            ->assertRedirect(route('admin.super.tenants.billing.index', $this->tenant));

        $this->assertDatabaseHas('tenant_billing_entries', [
            'tenant_account_id' => $this->tenant->id,
            'reference_no' => 'SUP-001',
            'direction' => 'debit',
        ]);
        $this->assertDatabaseHas('tenant_billing_entries', [
            'tenant_account_id' => $this->tenant->id,
            'reference_no' => 'COL-001',
            'direction' => 'credit',
        ]);

        $summary = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.billing.index', $this->tenant));

        $summary->assertOk();
        $summary->assertSee('750,00 TL');
        $summary->assertSee('200,00 TL');
    }

    public function test_package_fee_charge_and_exports_work(): void
    {
        $package = Package::query()->where('status', 'active')->firstOrFail();
        $package->update([
            'monthly_price' => 21990,
            'currency' => 'TRY',
        ]);

        $this->tenant->update([
            'package_key' => $package->key,
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.billing.package-fee', $this->tenant))
            ->assertRedirect(route('admin.super.tenants.billing.index', $this->tenant))
            ->assertSessionHas('success');

        $entry = TenantBillingEntry::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('entry_type', 'package_fee')
            ->firstOrFail();

        $this->assertSame('debit', $entry->direction);
        $this->assertSame($package->key, $entry->package_key);

        $csv = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.billing.export.csv', $this->tenant));

        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $pdf = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.billing.export.pdf', $this->tenant));

        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
    }

    public function test_tenant_admin_cannot_access_super_admin_billing_routes(): void
    {
        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-billing-admin@example.test',
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
            ->get(route('admin.super.tenants.billing.index', $this->tenant))
            ->assertForbidden();
    }
}
