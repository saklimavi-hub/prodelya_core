<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountTransaction;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\TenantSupplierPurchaseEntry;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogProcurementStartActionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_catalog_procurement_start_link_renders_working_route_and_prefills_exact_variant(): void
    {
        [$supplier, $product, $variant] = $this->createCatalogVariant('AK-2420-GRI', 'AK-2420 Gri');

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog?source_type=supplier');

        $href = route('admin.procurements.supplier-requests.create', [
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => $variant->id,
            'supplier_id' => $supplier->id,
            'requested_quantity' => 1,
            'source' => 'catalog',
        ]);

        $index->assertOk();
        $index->assertSee('admin/procurements/supplier-requests/create?', false);
        $index->assertSee('tenant_catalog_product_id=' . $product->id, false);
        $index->assertSee('tenant_catalog_product_variant_id=' . $variant->id, false);
        $index->assertSee('supplier_id=' . $supplier->id, false);
        $index->assertSee('source=catalog', false);

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($href);

        $create->assertOk();
        $create->assertSeeText('Katalogdan başlatılan tedarik akışı');
        $create->assertSeeText($variant->variant_code);
        $create->assertSeeText($supplier->name);
        $create->assertSeeText('Katalog');
    }

    public function test_catalog_procurement_start_get_creates_no_stock_or_debit(): void
    {
        [$supplier, $product, $variant] = $this->createCatalogVariant('AK-2420-GRI-2', 'AK-2420 Gri 2');

        $entryCount = TenantSupplierPurchaseEntry::query()->count();
        $movementCount = StockMovement::query()->count();
        $transactionCount = CurrentAccountTransaction::query()->count();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.create', [
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant->id,
                'supplier_id' => $supplier->id,
                'requested_quantity' => 1,
                'source' => 'catalog',
            ]))
            ->assertOk();

        $this->assertSame($entryCount, TenantSupplierPurchaseEntry::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($transactionCount, CurrentAccountTransaction::query()->count());
    }

    public function test_catalog_procurement_start_permission_guard(): void
    {
        [$supplier, $product, $variant] = $this->createCatalogVariant('AK-2420-GRI-3', 'AK-2420 Gri 3');
        $limitedUser = $this->createProductionUser();

        $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.create', [
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant->id,
                'supplier_id' => $supplier->id,
                'requested_quantity' => 1,
                'source' => 'catalog',
            ]))
            ->assertForbidden();
    }

    private function createCatalogVariant(string $variantCode, string $variantName): array
    {
        $supplier = Supplier::query()->create([
            'name' => $variantCode . ' Tedarikçi',
            'code' => str_replace('-', '', $variantCode),
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);

        $product = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_sku' => 'SKU-' . $variantCode,
            'name' => 'AK-2420',
            'product_code' => 'AK-2420',
            'product_name' => 'AK-2420',
            'slug' => 'ak-2420-' . strtolower($variantCode),
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/ak2420.jpg',
            'display_price' => 224,
            'sale_price' => 224,
            'currency' => 'TL',
            'total_stock_quantity' => 20,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 20,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => ['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name],
            'visible_in_catalog' => true,
            'visible_in_quote' => false,
            'hidden_reason' => null,
            'is_featured' => false,
            'local_stock_priority' => false,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => ['is_parent' => true, 'is_sellable' => false],
            'is_active' => true,
            'stock_quantity' => 20,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        $variant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'variant_code' => $variantCode,
            'variant_name' => $variantName,
            'display_price' => 224,
            'currency' => 'TL',
            'stock_quantity' => 20,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 20,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => ['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name],
            'meta' => ['is_sellable' => true],
        ]);

        return [$supplier, $product, $variant];
    }

    private function createProductionUser(): User
    {
        $user = User::query()->create([
            'name' => 'Production User',
            'email' => 'catalog-procurement-user-' . uniqid() . '@prodelya.local',
            'password' => 'password',
        ]);

        $roleId = \App\Models\Role::query()->where('key', 'production')->value('id');

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }
}
