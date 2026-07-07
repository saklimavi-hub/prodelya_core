<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
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

class ProcurementSupplierCariMatchedAfterSyncTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_procurement_screen_shows_matched_company_after_supplier_sync_repair(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->ownerFor($tenant);
        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Etkin Promosyon',
            'code' => 'ETKIN-HOTFIX-REAL',
            'status' => 'active',
        ]);
        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Etkin Kaynağı',
            'url' => 'https://example.test/etkin',
            'status' => 'active',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        app(CurrentAccountSyncService::class)->ensureForSupplier($supplier, $tenant);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'ETKİN PROMOSYON VE SOS. HİZ. KIRT. MAT. HED. EŞYA İNŞ. SAN. TİC. LTD. ŞTİ.',
            'short_name' => 'Etkin Promosyon',
            'status' => 'active',
        ]);
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);
        app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));

        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'PROC-HOTFIX-001',
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
            'product_name' => 'Etkin Ürün',
            'product_code' => 'ETKIN-001',
            'supplier_source_id' => $source->id,
            'quantity' => 5,
            'unit' => 'Adet',
            'product_snapshot' => ['supplier_name' => $supplier->name],
            'price_snapshot' => ['unit_price' => 20, 'line_total' => 100],
            'stock_snapshot' => ['supplier_stock_quantity' => 5],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 20,
            'unit_price' => 20,
            'line_total' => 100,
            'status' => 'pending',
        ]);
        app(WorkFormCreationService::class)->createForOrder($order, $owner);
        $this->assertNotNull($item->fresh('procurement')->procurement);

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/procurements'))
            ->assertOk()
            ->assertSee('Eşleşen cari: Yok');

        app(TenantSupplierCurrentAccountSyncService::class)->repairActiveAccesses($tenant, true);

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/procurements'))
            ->assertOk()
            ->assertSee('Eşleşen cari: ' . $company->short_name)
            ->assertDontSee('Eşleşen cari: Yok');
    }

    private function ownerFor(TenantAccount $tenant): User
    {
        $owner = User::query()->create([
            'name' => 'Procurement Hotfix Owner',
            'email' => 'procurement-hotfix-owner@example.test',
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

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
