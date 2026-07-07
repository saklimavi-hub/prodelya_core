<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchiveBlockedWhenOperationalLinksExistTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_duplicate_archive_is_blocked_when_operational_links_exist(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeTenantOwner($tenant, 'duplicate-operational-owner@example.test');
        $fixture = $this->createSupplierDuplicateFixture($tenant);

        $this->createFinancialHistory($fixture['canonical_account'], 300);

        Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'DUP-OP-001',
            'customer_company_id' => $fixture['duplicate_company']->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TRY',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->get($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '?tab=benzer-cari'));

        $response->assertOk()
            ->assertSee('Sipariş bağlantısı')
            ->assertSee('Sipariş bağlantısı var.')
            ->assertDontSee('Boş Benzer Cariyi Arşivle');
    }
}
