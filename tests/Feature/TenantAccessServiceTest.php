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

class TenantAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tenant_access_service_resolves_core_optional_alias_and_bridge_rules(): void
    {
        $service = app(TenantAccessService::class);
        $tenant = TenantAccount::query()->create([
            'name' => 'Access Tenant',
            'legal_name' => 'Access Tenant Ltd.',
            'slug' => 'access-tenant',
            'panel_subdomain' => 'access-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->assertTrue($service->canAccessModule($tenant, 'core'));
        $this->assertTrue($service->canAccessModule($tenant, 'order_flow'));
        $this->assertTrue($service->canAccessModule($tenant, 'promotion_orders'));
        $this->assertFalse($service->canAccessModule($tenant, 'product_data_hub'));
        $this->assertFalse($service->canAccessModule($tenant, 'production_qc'));
        $this->assertFalse($service->canAccessModule($tenant, 'web_quote_widget'));

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'product_data_hub',
            'is_enabled' => true,
        ]);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'xml_export',
            'is_enabled' => false,
        ]);

        $this->assertTrue($service->canAccessModule($tenant->fresh(), 'product_data_hub'));
        $this->assertFalse($service->canAccessModule($tenant->fresh(), 'xml_export'));

        TenantSetting::setValue($tenant->id, 'enable_customer_portal', true, 'boolean');
        $this->assertTrue($service->canAccessModule($tenant->fresh(), 'customer_portal'));

        $supplier = Supplier::query()->create([
            'name' => 'PDH Bridge Supplier',
            'code' => 'PDH-BRIDGE',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
        ]);

        $this->assertTrue($service->canAccessModule($tenant->fresh(), 'supplier_feed'));
        $this->assertSame('order_flow', $service->normalizeModuleKey('promotion_orders'));
        $this->assertSame('quote_customer_approval', $service->normalizeModuleKey('customer_quote_approval'));
        $this->assertSame('production_qc', $service->normalizeModuleKey('quality_control'));
        $this->assertSame('public_quote_approval', $service->normalizeFeatureKey('customer_quote_approval'));

        $featureStatus = $service->featureStatus($tenant->fresh(), 'public_quote_approval', 'quote_customer_approval');
        $this->assertFalse($featureStatus['enabled']);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => true,
        ]);

        $featureStatus = $service->featureStatus($tenant->fresh(), 'customer_quote_approval', 'customer_quote_approval');
        $this->assertTrue($featureStatus['enabled']);

        $expired = new TenantAccount(['name' => 'Expired', 'slug' => 'expired-access', 'status' => 'trial']);
        $expired->setAttribute('trial_ends_at', now()->subDay()->toDateString());
        $this->assertTrue($service->canAccessModule($expired, 'core'));
        $this->assertTrue($service->canAccessModule($expired, 'tenant_settings'));
        $this->assertFalse($service->canAccessModule($expired, 'order_flow'));

        $suspended = new TenantAccount(['name' => 'Suspended', 'slug' => 'suspended-access', 'status' => 'suspended']);
        $this->assertFalse($service->canAccessModule($suspended, 'product_data_hub'));

        $summary = $service->effectiveAccessSummary($tenant->fresh());
        $this->assertArrayHasKey('subscription', $summary);
        $this->assertArrayHasKey('modules', $summary);
        $this->assertArrayHasKey('features', $summary);
        $this->assertArrayHasKey('usage', $summary);
        $this->assertArrayHasKey('warnings', $summary);
    }
}
