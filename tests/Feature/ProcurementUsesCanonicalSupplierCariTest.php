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
use App\Services\TenantSupplierCurrentAccountSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementUsesCanonicalSupplierCariTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_procurement_screen_shows_canonical_company_after_duplicate_repair(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->makeOwner($tenant);
        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Canonical Görünüm Tedarikçi',
            'code' => 'CANONICAL-PROC-001',
            'status' => 'active',
        ]);
        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Canonical Görünüm Kaynağı',
            'url' => 'https://example.test/canonical-proc',
            'status' => 'active',
        ]);
        $access = TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $canonical = $this->createSupplierCompany($tenant, 'Canonical Görünüm Tedarikçi', 'Ana Cari');
        $duplicate = $this->createSupplierCompany($tenant, 'Canonical Görünüm Tedarikçi', 'Duplicate Cari');
        $canonicalAccount = app(CurrentAccountSyncService::class)->ensureForCompany($canonical->fresh('companyRoles'));
        $duplicateAccount = app(CurrentAccountSyncService::class)->ensureForCompany($duplicate->fresh('companyRoles'));

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'test'],
        ]);
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
            'is_primary' => false,
            'meta_json' => ['supplier_id' => $supplier->id],
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'PROC-CAN-001',
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
            'product_name' => 'Canonical Ürün',
            'product_code' => 'CP-001',
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

        app(TenantSupplierCurrentAccountSyncService::class)
            ->repairDuplicateSupplierCariLinks($tenant, $supplier, $canonical);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $canonicalAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        $this->assertDatabaseMissing('current_account_links', [
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/procurements'))
            ->assertOk()
            ->assertSee('Talep Hazırlanacak Tedarikçiler')
            ->assertSee('Fiyat, stok ve talep görünümü tek panelde özetlenir.')
            ->assertDontSee('Eşleşen cari: ' . $canonical->short_name)
            ->assertDontSee('Eşleşen cari: ' . $duplicate->short_name)
            ->assertDontSee('Eşleşen cari: Yok');
    }

    private function makeOwner(TenantAccount $tenant): User
    {
        $owner = User::query()->create([
            'name' => 'Canonical Procurement Owner',
            'email' => 'canonical-procurement-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);
        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        return $owner;
    }

    private function createSupplierCompany(TenantAccount $tenant, string $legalName, string $shortName): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => $legalName,
            'short_name' => $shortName,
            'status' => 'active',
        ]);
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        return $company;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
