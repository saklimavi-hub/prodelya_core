<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierProductVisibilityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;
    private Role $tenantOwnerRole;
    private StandardCategory $category;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $this->tenant = TenantAccount::query()->create([
            'name' => 'Audit Supplier Tenant',
            'legal_name' => 'Audit Supplier Tenant',
            'slug' => 'audit-supplier-tenant',
            'panel_subdomain' => 'audit-supplier-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
        $this->owner = User::query()->create([
            'name' => 'Audit Owner',
            'email' => 'audit-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);
        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);
        $this->supplier = Supplier::query()->create([
            'name' => 'Audit Supplier',
            'code' => 'AUD-SUP',
            'status' => 'active',
        ]);
    }

    public function test_projection_makes_supplier_products_searchable_without_leaking_technical_fields(): void
    {
        StandardProduct::query()->create([
            'supplier_id' => $this->supplier->id,
            'standard_product_code' => 'AUD-001',
            'sku' => 'AUD-001',
            'product_name' => 'Audit Supplier Ürün',
            'base_product_name' => 'Audit Supplier Ürün',
            'name' => 'Audit Supplier Ürün',
            'slug' => 'audit-supplier-urun',
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/aud-001.jpg',
            'currency' => 'TL',
            'min_purchase_price' => 80,
            'max_purchase_price' => 95,
            'total_stock_quantity' => 15,
            'supplier_count' => 1,
            'variant_count' => 0,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $this->supplier->id,
                'supplier_name' => $this->supplier->name,
                'supplier_product_code' => 'AUD-001',
                'supplier_group_code' => 'AUD-GRP',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 80,
                    'vat_rate' => 20,
                ],
            ],
            'is_active' => true,
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
        ])->assertExitCode(0);

        $response = $this->actingAs($this->owner, 'web')
            ->getJson($this->tenantUrl('/admin/catalog/search?q=AUD-001'));

        $response->assertOk();
        $response->assertJsonFragment([
            'product_code' => 'AUD-001',
            'supplier_name' => 'Audit Supplier',
        ]);
        $response->assertJsonMissingPath('0.raw_payload');
        $response->assertJsonMissingPath('0.group_code');
        $response->assertJsonMissingPath('0.file_path');
        $response->assertJsonMissingPath('0.physical_path');
        $this->assertSame('supplier_projection', $response->json('0.catalog_source'));
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
