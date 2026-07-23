<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierProcurementRequestPriceReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const FX_RATE_DATE = '2026-07-14';

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

    public function test_edit_screen_shows_try_supplier_source_without_identity_rate_for_authorized_user(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-TRY');

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'TRY Kaynak Ürün',
            'supplier_product_code' => 'RAW-SPR-TRY-001',
            'product_name' => 'TRY Tedarik Ürün',
            'purchase_price' => '9.2000',
            'currency' => 'TRY',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-TRY-001', false, 20.00, 2000.00, [
            'product_name' => 'TRY Tedarik Ürün',
            'product_code' => 'SPR-TRY-001',
            'supplier_product_raw_id' => $raw->id,
        ]);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('Tedarikçi liste:');
        $response->assertSee('9,20 TL');
        $response->assertSee('Hesaplanan: <span data-calculated-display>9,20 TL</span>', false);
        $response->assertDontSee('Kur: 1 TRY');
        $response->assertDontSee('Satış Ref');
        $response->assertDontSee('Satış Toplam');
    }

    public function test_edit_screen_shows_usd_source_try_equivalent_and_rate_from_canonical_snapshot(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-USD');
        $this->createRate('USD', '46.89280000', self::FX_RATE_DATE);

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'USD Kaynak Ürün',
            'supplier_product_code' => 'RAW-SPR-USD-001',
            'product_name' => 'USD Tedarik Ürün',
            'purchase_price' => '12.5000',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-USD-001', false, 586.16, 58616.00, [
            'product_name' => 'USD Tedarik Ürün',
            'product_code' => 'SPR-USD-001',
            'supplier_product_raw_id' => $raw->id,
        ]);
        $this->pinProcurementQuoteDate($procurement);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('12,50 USD');
        $response->assertSee('TL karşılığı: 586,16 TL');
        $response->assertSee('Kur: 1 USD = 46,8928 TL');
        $response->assertSee('Kur tarihi: 14.07.2026');
        $response->assertSee('Hesaplanan: <span data-calculated-display>586,16 TL</span>', false);
        $response->assertDontSee('Satış Liste');
        $response->assertDontSee('Satış Toplam');
    }

    public function test_missing_supplier_price_shows_explicit_warning_without_sales_fallback(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-MISSING');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-MISSING-001', false, 20.00, 2000.00, [
            'product_name' => 'Kaynak Eksik Ürün',
            'product_code' => 'SPR-MISSING-001',
        ]);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('Tedarikçi liste fiyatı bulunamadı');
        $response->assertDontSee('Satış fiyatı referansı bulunamadı');
        $response->assertDontSee('Satış Ref');
        $response->assertDontSee('Tedarikçi liste: 0,00');
    }

    public function test_manual_override_persists_and_renders_calculated_restore_helper(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-OVERRIDE');
        $this->createRate('USD', '46.89280000', self::FX_RATE_DATE);

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'USD Override Kaynak',
            'supplier_product_code' => 'RAW-SPR-OVERRIDE-001',
            'product_name' => 'Override Tedarik Ürün',
            'purchase_price' => '12.5000',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-OVERRIDE-001', false, 700.00, 70000.00, [
            'product_name' => 'Override Tedarik Ürün',
            'product_code' => 'SPR-OVERRIDE-001',
            'supplier_product_raw_id' => $raw->id,
        ]);
        $this->pinProcurementQuoteDate($procurement);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $item = $requestRecord->fresh('items')->items->first();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.procurements.supplier-requests.update', $requestRecord), [
                'submit_action' => 'draft',
                'items' => [
                    [
                        'id' => $item->id,
                        'requested_quantity' => '100',
                        'purchase_list_price' => '586,16',
                        'discount_rate' => '50,00',
                        'purchase_unit_price' => '300,00',
                        'use_calculated_price' => '0',
                        'note' => 'Manuel override',
                    ],
                ],
            ]);

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord->fresh()));

        $edit->assertOk();
        $edit->assertSee('Hesaplanan: <span data-calculated-display>293,08 TL</span>', false);
        $edit->assertSee('Hesaplananı kullan');
        $edit->assertSee('Manuel override aktif.');
        $edit->assertSee('value="300.00"', false);
    }

    public function test_unauthorized_user_does_not_see_purchase_price_fields_and_print_stays_priceless(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE-E');
        $procurement = $this->createProcurement($supplier, $source, 'SP-SPR-PRICE-005', true, 20.00, 2000.00);
        $requestRecord = $this->createDraftRequest($supplier->id, [$procurement->id]);
        $limitedUser = $this->createProductionUser();

        $response = $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertDontSee('Tedarikçi Liste');
        $response->assertDontSee('Alış Birim Fiyatı');
        $response->assertDontSee('Satış Ref');
        $response->assertDontSee('Satış Toplam');

        $print = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $requestRecord));

        $print->assertOk();
        $print->assertDontSee('Tedarikçi Liste');
        $print->assertDontSee('Alış Toplamı');
        $print->assertDontSee('Satış Ref');
        $print->assertDontSee('Satış Toplam');
        $print->assertDontSee('Kur:');
        $print->assertDontSee('TL karşılığı');
        $print->assertDontSee('group_code', false);
        $print->assertDontSee('raw_mapping', false);
    }

    private function createDraftRequest(int $supplierId, array $procurementIds): SupplierProcurementRequest
    {
        return app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplierId,
            $procurementIds,
            $this->adminUser
        );
    }

    private function createSupplierWithAccess(string $code): array
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'can_request_purchase' => true,
                'can_use_in_quotes' => true,
                'visible_in_catalog' => true,
                'export_allowed' => false,
                'granted_at' => now(),
            ]
        );

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);

        return [$supplier, $source];
    }

    private function createProcurement(
        Supplier $supplier,
        SupplierSource $source,
        string $orderNumber,
        bool $withPurchaseListPrice,
        ?float $salesUnitPrice,
        ?float $salesTotal,
        array $productSnapshotOverrides = [],
        array $itemPriceSnapshotOverrides = []
    ): OrderItemProcurement {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $orderNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $productSnapshot = [
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_name' => $supplier->name,
            'warning_badges' => [],
            'group_code' => 'HIDDEN-GROUP',
            'raw_mapping' => ['secret' => 'hidden'],
        ];

        if ($withPurchaseListPrice) {
            $productSnapshot['price_snapshot'] = ['list_price' => 9.20];
        }

        $productSnapshot = array_merge($productSnapshot, $productSnapshotOverrides);

        $priceSnapshot = [];

        if ($salesUnitPrice !== null) {
            $priceSnapshot['unit_price'] = $salesUnitPrice;
        }

        if ($salesTotal !== null) {
            $priceSnapshot['line_total'] = $salesTotal;
        }

        $priceSnapshot = array_merge($priceSnapshot, $itemPriceSnapshotOverrides);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_id' => null,
            'supplier_source_id' => $source->id,
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => $productSnapshot,
            'price_snapshot' => $priceSnapshot,
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 40,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => $salesUnitPrice,
            'line_total' => $salesTotal,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order', 'procurement.orderItem'])->procurement;
    }

    private function pinProcurementQuoteDate(OrderItemProcurement $procurement): void
    {
        $procurement->order?->forceFill([
            'quote_date' => self::FX_RATE_DATE,
        ])->save();

        $procurement->unsetRelation('order');
    }



    private function createRate(string $sourceCurrency, string $rate, string $date): void
    {
        ExchangeRate::query()
            ->where('provider', 'tcmb')
            ->where('rate_type', 'forex_selling')
            ->where('source_currency', $sourceCurrency)
            ->where('target_currency', 'TRY')
            ->whereDate('rate_date', $date)
            ->delete();

        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => $sourceCurrency,
            'target_currency' => 'TRY',
            'rate_date' => $date,
            'source_unit' => 1,
            'rate' => $rate,
            'fetched_at' => now(),
            'payload_hash' => (string) Str::uuid(),
            'meta_json' => [],
        ]);
    }

    private function createProductionUser(): User
    {
        $user = User::query()->create([
            'name' => 'Production User',
            'email' => 'production-price-user-' . uniqid() . '@prodelya.local',
            'password' => 'password',
        ]);

        $roleId = Role::query()->where('key', 'production')->value('id');

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }
}
