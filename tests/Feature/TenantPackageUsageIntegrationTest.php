<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\TenantUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPackageUsageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tenant_usage_service_reads_package_limits_and_keeps_fallbacks(): void
    {
        $service = app(TenantUsageService::class);
        $admin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $suiteTenant = TenantAccount::query()->create([
            'name' => 'Suite Tenant',
            'legal_name' => 'Suite Tenant Ltd.',
            'slug' => 'suite-tenant',
            'panel_subdomain' => 'suite-tenant',
            'status' => 'active',
            'package_key' => 'suite',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        for ($index = 1; $index <= 18; $index++) {
            Order::query()->create([
                'tenant_account_id' => $suiteTenant->id,
                'order_family' => 'promotion',
                'order_mode' => 'product_sale_print',
                'document_type' => 'order',
                'document_number' => 'SUITE-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'status' => 'pending',
                'workflow_status' => 'order_created',
                'invoice_status' => 'fis',
                'delivery_type' => 'Kargo',
                'currency' => 'TL',
                'created_by' => $admin->id,
            ]);
        }

        TenantSetting::setValue($suiteTenant->id, 'storage_used_mb', 500, 'integer');

        $users = $service->getUsageForKey($suiteTenant, 'users');
        $orders = $service->getUsageForKey($suiteTenant, 'orders');

        $this->assertSame(20, $users['limit']);
        $this->assertSame(5000, $orders['limit']);
        $this->assertSame('ok', $orders['status']);

        TenantSetting::setValue($suiteTenant->id, 'limit_orders', 10, 'integer');
        $overriddenOrders = $service->getUsageForKey($suiteTenant->fresh(), 'orders');
        $this->assertSame(10, $overriddenOrders['limit']);
        $this->assertSame('exceeded', $overriddenOrders['status']);

        $enterpriseTenant = TenantAccount::query()->create([
            'name' => 'Enterprise Tenant',
            'legal_name' => 'Enterprise Tenant Ltd.',
            'slug' => 'enterprise-tenant',
            'panel_subdomain' => 'enterprise-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $enterpriseUsers = $service->getUsageForKey($enterpriseTenant, 'users');
        $this->assertSame('unlimited', $enterpriseUsers['status']);

        $fallbackTenant = TenantAccount::query()->create([
            'name' => 'Legacy Usage Tenant',
            'legal_name' => 'Legacy Usage Tenant Ltd.',
            'slug' => 'legacy-usage-tenant',
            'panel_subdomain' => 'legacy-usage-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $fallback = $service->getUsageForKey($fallbackTenant, 'orders');
        $this->assertSame('unlimited', $fallback['status']);

        $snapshot = $service->getUsageSnapshot($suiteTenant->fresh());
        $this->assertArrayHasKey('orders', $snapshot);
        $this->assertNotEmpty($service->warningItems($suiteTenant->fresh()));
    }
}
