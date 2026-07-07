<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountTerminologyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_current_account_index_uses_bakiye_language_and_not_identity_management_language(): void
    {
        $linked = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Terminology Linked',
            'legal_name' => 'Terminology Linked Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($linked, [CurrentAccountRole::ROLE_CUSTOMER]);
        app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($linked, [CurrentAccountRole::ROLE_CUSTOMER]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index', ['tab' => 'tumu']));

        $response->assertOk()
            ->assertSee('Cari Bakiyeler')
            ->assertSee('Cari kimlik ve iletişim bilgileri Cari Kartlar ekranından yönetilir.')
            ->assertSee('Cari Kartı Aç')
            ->assertSee('Ekstre')
            ->assertDontSee('Current Account')
            ->assertDontSee('Müşteriler / Cari Kartlar')
            ->assertDontSee('Düzenle');
    }
}
