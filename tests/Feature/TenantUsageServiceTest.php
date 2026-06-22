<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tenant_usage_service_builds_usage_snapshot_limits_and_warnings(): void
    {
        $service = app(TenantUsageService::class);
        $tenant = TenantAccount::query()->firstOrFail();

        $user = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $role = Role::query()->firstOrFail();
        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_account_id' => $tenant->id,
        ]);

        Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Usage Company',
            'status' => 'active',
        ]);

        CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => 'Usage Cari',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'USAGE-SKU-001',
            'name' => 'Usage Product',
            'currency' => 'TL',
            'is_active' => true,
        ]);

        Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'USAGE-001',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fis',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $user->id,
        ]);

        Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'USAGE-002',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fis',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $user->id,
        ]);

        TenantSetting::setValue($tenant->id, 'limit_users', 1, 'integer');
        TenantSetting::setValue($tenant->id, 'limit_orders', 1, 'integer');
        TenantSetting::setValue($tenant->id, 'limit_companies', 10, 'integer');
        TenantSetting::setValue($tenant->id, 'storage_used_mb', 256, 'integer');

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'supplier_feed',
            'is_enabled' => true,
            'limit_value' => 0,
        ]);

        $usersUsage = $service->getUsageForKey($tenant->fresh(), 'users');
        $ordersUsage = $service->getUsageForKey($tenant->fresh(), 'orders');
        $productsUsage = $service->getUsageForKey($tenant->fresh(), 'products');
        $storageUsage = $service->getUsageForKey($tenant->fresh(), 'storage_mb');
        $domainsUsage = $service->getUsageForKey($tenant->fresh(), 'custom_domains');

        $this->assertSame('users', $usersUsage['key']);
        $this->assertSame(1, $usersUsage['limit']);
        $this->assertSame('warning', $usersUsage['status']);

        $this->assertSame('exceeded', $ordersUsage['status']);
        $this->assertSame(1, $ordersUsage['limit']);
        $this->assertSame('unlimited', $productsUsage['status']);
        $this->assertSame(256, $storageUsage['current']);
        $this->assertSame(0, $domainsUsage['current']);

        $snapshot = $service->getUsageSnapshot($tenant->fresh());
        $this->assertArrayHasKey('users', $snapshot);
        $this->assertArrayHasKey('current_accounts', $snapshot);
        $this->assertArrayHasKey('orders', $snapshot);

        $this->assertTrue($service->isExceeded($tenant->fresh(), 'orders'));
        $this->assertFalse($service->isExceeded($tenant->fresh(), 'companies'));

        $warnings = $service->warningItems($tenant->fresh());
        $this->assertNotEmpty($warnings);
        $this->assertContains('users', array_column($warnings, 'key'));
        $this->assertContains('orders', array_column($warnings, 'key'));
    }
}
