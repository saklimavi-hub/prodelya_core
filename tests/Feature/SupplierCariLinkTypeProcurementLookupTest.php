<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCariLinkTypeProcurementLookupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_procurement_lookup_reads_tenant_supplier_access_link_type(): void
    {
        [$tenant, $owner, $customer] = $this->tenantContext();

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Link Type Tedarikçi',
            'code' => 'LINK-TYPE-001',
            'status' => 'active',
        ]);
        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Link Type Kaynağı',
            'url' => 'https://example.test/link-type',
            'status' => 'active',
        ]);
        $access = TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Link Type Tedarikçi',
            'status' => 'active',
        ]);
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        $account = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
            'is_primary' => true,
            'meta_json' => ['supplier_id' => $supplier->id],
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'PROC-LINK-001',
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $owner->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => 'Link Type Ürün',
            'product_code' => 'LT-001',
            'supplier_source_id' => $source->id,
            'quantity' => 10,
            'unit' => 'Adet',
            'product_snapshot' => ['supplier_name' => $supplier->name],
            'price_snapshot' => ['unit_price' => 10, 'line_total' => 100],
            'stock_snapshot' => ['supplier_stock_quantity' => 10],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 10,
            'unit_price' => 10,
            'line_total' => 100,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $owner);
        $this->assertNotNull($item->fresh('procurement')->procurement);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
        ]);

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/procurements'))
            ->assertOk()
            ->assertSee('Talep Hazırlanacak Tedarikçiler')
            ->assertSee('Fiyat, stok ve talep görünümü tek panelde özetlenir.')
            ->assertDontSee('Eşleşen cari: ' . $company->legal_name)
            ->assertDontSee('Eşleşen cari: Yok');
    }

    private function tenantContext(): array
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = User::query()->create([
            'name' => 'Procurement Link Type Owner',
            'email' => 'procurement-link-type-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        return [$tenant, $owner, $customer];
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
