<?php

namespace Tests\Feature;

use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCompanyFinanceListFixtures;
use Tests\TestCase;

class CariMoneyDisplayTerminologyTest extends TestCase
{
    use BuildsCompanyFinanceListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_money_display_surfaces_keep_turkish_labels_and_hide_technical_terms(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $financeUser = $this->makeCompanyFinanceUser($tenant, 'money-terminology@example.test');

        [$account] = $this->createCompanyLinkedAccount($tenant, 'Terminoloji Para Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $this->createCompanyListTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1500,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $response = $this->actingAs($financeUser, 'web')
            ->get($this->companyTenantUrl($tenant, '/admin/companies'));

        $response->assertOk()
            ->assertSee('Güncel Bakiye')
            ->assertSee('Bakiye Durumu')
            ->assertSee('Açık Hareket')
            ->assertSee('Son Hareket')
            ->assertDontSee('Musteri')
            ->assertDontSee('Borc')
            ->assertDontSee('Guncel')
            ->assertDontSee('Current Account')
            ->assertDontSee('Company')
            ->assertDontSee('canonical')
            ->assertDontSee('duplicate')
            ->assertDontSee('+1.500,00 TL');
    }
}
