<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\AdminMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CriticalPlaceholderAndRouteConsistencyTest extends TestCase
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

    public function test_quote_forms_hide_non_working_ctas_and_placeholder_copy(): void
    {
        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $create->assertOk();
        $create->assertDontSee('Müşteri Ekle');
        $create->assertDontSee('Müşteri Portalında Önizle');
        $create->assertDontSee('Sonraki fazda aktif olacak');
        $create->assertSee('Yeni müşteri kaydı gerekiyorsa önce Cari Kart ekranından ekleyin.');

        $quote = $this->createQuote();

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $edit->assertOk();
        $edit->assertDontSee('Müşteri Portalında Önizle');
        $edit->assertDontSee('Sonraki fazda aktif olacak');
        $edit->assertSee('PDF, gönderim ve portal adımları teklif detay ekranından yönetilir.');
    }

    public function test_company_import_screen_is_safe_and_import_actions_do_not_fake_success(): void
    {
        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.import.index'));

        $index->assertOk();
        $index->assertSee('Cari Kart İçe Aktarma');
        $index->assertSee('hazırlık aşamasındadır');
        $index->assertDontSee('Önizle ve Alan Eşle');
        $index->assertDontSee('İçe Aktarı Başlat');

        $preview = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.companies.import.preview'), [
                'file' => UploadedFile::fake()->create('companies.csv', 2, 'text/csv'),
            ]);

        $preview->assertRedirect(route('admin.companies.import.index'));
        $preview->assertSessionHas('info');

        $store = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.companies.import.store'), [
                'confirm' => '1',
            ]);

        $store->assertRedirect(route('admin.companies.import.index'));
        $store->assertSessionHasErrors('import');
    }

    public function test_order_status_route_is_not_fake_success(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.status.update', $order));

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHasErrors('order');
        $response->assertSessionMissing('success');
    }

    public function test_product_data_hub_uses_safe_export_language_and_placeholder_routes_are_blocked(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'product_data_hub',
                'feature_key' => 'tenant_catalog_projection',
            ],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'PDH Visible Supplier',
            'code' => 'PDH-VISIBLE',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => false,
        ]);

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.product-data-hub.index'));

        $index->assertForbidden();
        $index->assertSee('yalnız Super Admin tarafından yönetilir.');
        $index->assertDontSee(route('admin.product-data-hub.exports'), false);

        $sync = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.product-data-hub.sync'));

        $sync->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.product-data-hub.product-mappings'))
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.product-data-hub.logs'))
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.product-data-hub.tenant-access'))
            ->assertForbidden();
    }

    public function test_menu_hides_passive_placeholder_items_and_exposes_only_real_routes(): void
    {
        $items = app(AdminMenuService::class)->tenantMenu($this->tenant, $this->adminUser);
        $flat = $this->flattenMenu($items);

        $keys = array_column($flat, 'key');

        $this->assertNotContains('work-forms', $keys);
        $this->assertNotContains('reporting', $keys);
        $this->assertNotContains('api-access', $keys);
        $this->assertNotContains('print-service-quotes', $keys);

        foreach ($flat as $item) {
            if (!empty($item['route'])) {
                $this->assertTrue(
                    Route::has($item['route']),
                    'Menu item route missing: ' . $item['route']
                );
            }
        }
    }

    private function flattenMenu(array $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            $flat[] = $item;

            if (!empty($item['children']) && is_array($item['children'])) {
                $flat = array_merge($flat, $this->flattenMenu($item['children']));
            }
        }

        return $flat;
    }

    private function createQuote(): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-PH-' . random_int(1000, 9999),
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'subtotal' => 100,
            'vat_total' => 0,
            'grand_total' => 100,
            'product_total' => 100,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Placeholder Test Quote',
            'product_code' => 'PH-Q-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'list_price' => 10,
            'discount_rate' => 0,
            'unit_price' => 10,
            'line_total' => 100,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $quote;
    }

    private function createOrder(): Order
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-PH-' . random_int(1000, 9999),
            'source_quote_number' => 'TK-PH-0001',
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'subtotal' => 250,
            'vat_total' => 0,
            'grand_total' => 250,
            'product_total' => 250,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Placeholder Test Order',
            'product_code' => 'PH-O-001',
            'quantity' => 5,
            'unit' => 'Adet',
            'list_price' => 50,
            'discount_rate' => 0,
            'unit_price' => 50,
            'line_total' => 250,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $order;
    }
}
