<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Services\TenantAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPackageAccessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tenant_access_service_reads_package_defaults_and_tenant_overrides(): void
    {
        $service = app(TenantAccessService::class);

        $starterTenant = TenantAccount::query()->create([
            'name' => 'Starter Tenant',
            'legal_name' => 'Starter Tenant Ltd.',
            'slug' => 'starter-tenant',
            'panel_subdomain' => 'starter-tenant',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $promotionTenant = TenantAccount::query()->create([
            'name' => 'Promotion Tenant',
            'legal_name' => 'Promotion Tenant Ltd.',
            'slug' => 'promotion-tenant',
            'panel_subdomain' => 'promotion-tenant',
            'status' => 'active',
            'package_key' => 'promotion',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->assertSame('starter', $service->moduleStatus($starterTenant, 'product_data_hub')['package_key']);
        $this->assertFalse($service->canAccessModule($starterTenant, 'product_data_hub'));
        $this->assertTrue($service->canAccessModule($promotionTenant, 'product_data_hub'));
        $this->assertFalse($service->canAccessModule($promotionTenant, 'xml_export'));

        TenantModule::query()->create([
            'tenant_account_id' => $promotionTenant->id,
            'module_key' => 'product_data_hub',
            'is_enabled' => false,
        ]);

        $this->assertFalse($service->canAccessModule($promotionTenant->fresh(), 'product_data_hub'));

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $promotionTenant->id, 'module_key' => 'customer_portal'],
            ['is_enabled' => true]
        );

        $this->assertTrue($service->canAccessModule($promotionTenant->fresh(), 'customer_portal'));
        $this->assertTrue($service->canAccessModule($promotionTenant->fresh(), 'core'));
        $this->assertTrue($service->canAccessFeature($promotionTenant->fresh(), 'customer_quote_approval', 'customer_quote_approval'));

        TenantModule::query()->create([
            'tenant_account_id' => $promotionTenant->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => false,
        ]);

        $this->assertFalse($service->canAccessFeature($promotionTenant->fresh(), 'customer_quote_approval', 'customer_quote_approval'));

        $fallbackTenant = TenantAccount::query()->create([
            'name' => 'Fallback Tenant',
            'legal_name' => 'Fallback Tenant Ltd.',
            'slug' => 'fallback-tenant',
            'panel_subdomain' => 'fallback-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        TenantSetting::setValue($fallbackTenant->id, 'enable_customer_portal', true, 'boolean');
        $this->assertTrue($service->canAccessModule($fallbackTenant->fresh(), 'customer_portal'));

        $supplier = Supplier::query()->create([
            'name' => 'Package Bridge Supplier',
            'code' => 'PKG-BRIDGE-001',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $fallbackTenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
        ]);

        $this->assertTrue($service->canAccessModule($fallbackTenant->fresh(), 'product_data_hub'));
        $this->assertTrue($service->canAccessModule($fallbackTenant->fresh(), 'supplier_feed'));
    }
}
