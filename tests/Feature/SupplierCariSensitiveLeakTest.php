<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCariSensitiveLeakTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->owner = User::query()->create([
            'name' => 'Sensitive Leak Owner',
            'email' => 'sensitive-leak-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    public function test_supplier_cari_show_page_does_not_leak_technical_identifiers(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Sızıntı Test Tedarikçisi',
            'code' => 'LEAK-SUP-001',
            'status' => 'active',
            'config' => [
                'token' => 'hidden-token',
                'secret' => 'hidden-secret',
                'api_key' => 'hidden-api-key',
                'file_path' => '/secret/path',
            ],
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $result = app(TenantSupplierCurrentAccountSyncService::class)->syncForTenantSupplierAccess($this->tenant, $supplier);

        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $result['company']->id));

        $response->assertOk()
            ->assertSee('Sızıntı Test Tedarikçisi')
            ->assertDontSee('supplier_id')
            ->assertDontSee('tenant_id')
            ->assertDontSee('current_account_id')
            ->assertDontSee('source_type')
            ->assertDontSee('meta_json')
            ->assertDontSee('hidden-token')
            ->assertDontSee('hidden-secret')
            ->assertDontSee('hidden-api-key')
            ->assertDontSee('/secret/path');
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
