<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\OrderItemPrintProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionCanonicalRouteResolverTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::PRODUCTION_SHOW_HOST]);
        $this->setUpProductionShowFixtures();
    }

    public function test_production_canonical_routes_keep_internal_assignment_and_outsourced_tracking_separate(): void
    {
        $internal = $this->createInternalProductionForShow([
            'assigned_to' => null,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
        ]);
        $partner = $this->createRoleCompany('M13C3 Canonical Fason', 'print_fason');
        $outsourcedUnsent = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'production_company_id' => $partner->id,
            'sent_to_subcontractor_at' => null,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
        ]);
        $outsourcedSent = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
            'production_company_id' => $partner->id,
            'sent_to_subcontractor_at' => now(),
        ]);

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $internal->id . '?tab=islemler'))
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $internal->id . '/operator'));

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $outsourcedUnsent->id . '?tab=islemler'))
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $outsourcedUnsent->id . '/subcontract-assignment'));

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $outsourcedSent->id . '?tab=islemler'))
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $outsourcedSent->id . '/subcontract-tracking'));
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . self::PRODUCTION_SHOW_HOST . $path;
    }

    private function createRoleCompany(string $name, string $role): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => $name,
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => $role,
        ]);

        return $company;
    }
}
