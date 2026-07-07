<?php

namespace Tests\Feature;

use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountTransaction;
use App\Models\Supplier;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyDuplicateFixtures;
use Tests\TestCase;

class CompanyDuplicateArchiveDoesNotMoveOrDeleteHistoryTest extends TestCase
{
    use BuildsCompanyDuplicateFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_archiving_duplicate_does_not_move_or_delete_history(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeTenantOwner($tenant, 'duplicate-history-owner@example.test');
        $fixture = $this->createSupplierDuplicateFixture($tenant);
        $this->createFinancialHistory($fixture['canonical_account'], 275);

        $this->actingAs($owner)
            ->post($this->tenantUrl($tenant, '/admin/companies/' . $fixture['duplicate_company']->id . '/archive-duplicate'))
            ->assertRedirect();

        $this->assertSame(1, CurrentAccountTransaction::query()->count());
        $this->assertDatabaseHas('current_account_transactions', [
            'current_account_id' => $fixture['canonical_account']->id,
        ]);
        $this->assertDatabaseMissing('current_account_transactions', [
            'current_account_id' => $fixture['duplicate_account']->id,
        ]);
        $this->assertDatabaseHas('suppliers', [
            'id' => $fixture['supplier']->id,
            'name' => $fixture['supplier']->name,
        ]);
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $fixture['canonical_account']->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $fixture['supplier']->id,
        ]);
    }
}
