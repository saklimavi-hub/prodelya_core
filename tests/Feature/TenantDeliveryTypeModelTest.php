<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantDeliveryType;
use App\Services\TenantDeliveryTypeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDeliveryTypeModelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_defaults_active_default_and_tenant_isolation_work(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Delivery Type Other Tenant',
            'legal_name' => 'Delivery Type Other Tenant Ltd.',
            'slug' => 'delivery-type-other-tenant',
            'panel_subdomain' => 'delivery-type-other-tenant',
            'status' => 'active',
        ]);

        $service = app(TenantDeliveryTypeService::class);

        $tenantTypes = $service->ensureDefaultsForTenant($tenant);
        $otherTenantTypes = $service->ensureDefaultsForTenant($otherTenant);

        $this->assertCount(7, $tenantTypes);
        $this->assertCount(7, $otherTenantTypes);
        $this->assertSame('Ofis Teslim', $tenantTypes->firstWhere('is_default', true)?->name);
        $this->assertSame(7, $tenantTypes->where('is_active', true)->count());
        $this->assertSame(0, TenantDeliveryType::query()->forTenant($tenant->id)->where('tenant_account_id', $otherTenant->id)->count());

        $kurye = TenantDeliveryType::query()
            ->forTenant($tenant->id)
            ->where('name', 'Kurye')
            ->firstOrFail();

        $service->setDefault($kurye);

        $this->assertSame($kurye->id, TenantDeliveryType::query()->forTenant($tenant->id)->where('is_default', true)->value('id'));
        $this->assertSame('Kurye', TenantDeliveryType::query()->forTenant($tenant->id)->where('is_default', true)->value('name'));
    }
}
