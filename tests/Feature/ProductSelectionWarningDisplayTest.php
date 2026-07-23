<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSelectionWarningDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private User $tenantUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()
            ->where('panel_subdomain', 'demo')
            ->first()
            ?? TenantAccount::query()->orderBy('id')->firstOrFail();
        $this->tenantUser = $this->makeTenantOwner($this->tenant, 'product-warning-owner@example.test');
    }

    public function test_catalog_search_returns_net_price_warning_for_akdeniz_net_priced_product(): void
    {
        $this->projectFixtureSourceToCatalog(3);

        $response = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=F-112-32'));

        $response->assertOk();

        $results = collect($response->json());
        $product = $results->first(fn (array $row) => str_contains((string) ($row['product_name'] ?? ''), 'F-112-32'));

        $this->assertNotNull($product);
        $this->assertEquals(280.55, $product['list_price']);
        $this->assertContains('Net fiyat uyarısı', $product['warning_badges']);
        $this->assertTrue(collect($product['warning_messages'])->contains(fn (string $message) => str_contains($message, 'standart iskonto uygulanmamalı')));
        $this->assertArrayNotHasKey('purchase_price', $product);
    }

    public function test_catalog_search_returns_supplier_warning_for_special_priced_product(): void
    {
        $this->projectFixtureSourceToCatalog(2);

        $response = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=0506-L'));

        $response->assertOk();

        $product = collect($response->json())->first(fn (array $row) => ($row['product_code'] ?? null) === 'ET-0506-L');

        $this->assertNotNull($product);
        $this->assertStringStartsWith('ET-0506-L', $product['product_name']);
        $this->assertEquals(19.9, $product['list_price']);
        $this->assertContains('Kırmızı Ürün', $product['warning_badges']);
        $this->assertTrue(collect($product['warning_messages'])->contains(fn (string $message) => str_contains($message, 'özel fiyat/iskonto uyarılı')));
    }

    public function test_warning_product_can_be_saved_without_has_print_and_keeps_safe_warning_snapshots(): void
    {
        $this->projectFixtureSourceToCatalog(2);

        $response = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=0506-L'));

        $response->assertOk();

        $product = collect($response->json())->first(fn (array $row) => ($row['product_code'] ?? null) === 'ET-0506-L');
        $this->assertNotNull($product);

        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $createResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'valid_until' => now()->addWeek()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Warning create smoke',
                'items' => [[
                    'product_name' => $product['product_name'],
                    'product_code' => $product['product_code'],
                    'quantity' => '12',
                    'unit' => 'Adet',
                    'list_price' => (string) $product['list_price'],
                    'discount_rate' => '0',
                    'unit_price' => (string) $product['list_price'],
                    'tenant_catalog_product_id' => $product['tenant_catalog_product_id'] ?? $product['id'],
                    'tenant_catalog_product_variant_id' => $product['tenant_catalog_product_variant_id'],
                    'standard_product_id' => data_get($product, 'product_snapshot.standard_product_id'),
                    'standard_product_variant_id' => data_get($product, 'product_snapshot.standard_product_variant_id'),
                    'supplier_source_id' => data_get($product, 'source_summary.0.supplier_source_id'),
                    'catalog_source' => $product['catalog_source'] ?? 'tenant_catalog',
                    'selected_catalog_identity' => json_encode([
                        'catalog_source' => $product['catalog_source'] ?? 'tenant_catalog',
                        'tenant_catalog_product_id' => $product['tenant_catalog_product_id'] ?? $product['id'],
                        'tenant_catalog_product_variant_id' => $product['tenant_catalog_product_variant_id'],
                        'standard_product_id' => data_get($product, 'product_snapshot.standard_product_id'),
                        'standard_product_variant_id' => data_get($product, 'product_snapshot.standard_product_variant_id'),
                        'product_code' => $product['product_code'],
                        'product_name' => $product['product_name'],
                        'is_warning_sellable' => true,
                        'warning_tone' => 'red',
                        'warning_summary' => 'Kırmızı Ürün',
                    ], JSON_UNESCAPED_UNICODE),
                    'product_snapshot' => json_encode($product['product_snapshot'], JSON_UNESCAPED_UNICODE),
                    'price_snapshot' => json_encode($product['price_snapshot'], JSON_UNESCAPED_UNICODE),
                    'stock_snapshot' => json_encode($product['stock_snapshot'], JSON_UNESCAPED_UNICODE),
                ]],
            ]);

        $quote = Order::query()->latest('id')->firstOrFail();
        $createResponse->assertRedirectToRoute('admin.promotion-quotes.show', $quote);

        $item = $quote->items()->firstOrFail();

        $this->assertFalse((bool) $item->has_print);
        $this->assertTrue((bool) data_get($item->product_snapshot, 'is_warning_sellable'));
        $this->assertSame('red', data_get($item->product_snapshot, 'warning_tone'));
        $this->assertContains('Kırmızı Ürün', data_get($item->price_snapshot, 'warning_badges', []));
        $this->assertArrayNotHasKey('warning_badges', $item->product_snapshot ?? []);
        $this->assertArrayNotHasKey('warning_messages', $item->product_snapshot ?? []);
    }

    public function test_broken_warning_product_snapshot_returns_validation_error_instead_of_500(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from('/admin/promotion-quotes/create')
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'valid_until' => now()->addWeek()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Bozuk Warning Ürünü',
                    'product_code' => 'WRN-VAL-01',
                    'quantity' => '3',
                    'unit' => 'Adet',
                    'list_price' => '10',
                    'discount_rate' => '0',
                    'unit_price' => '10',
                    'selected_catalog_identity' => json_encode([
                        'catalog_source' => 'tenant_catalog',
                        'tenant_catalog_product_id' => 999999,
                        'product_code' => 'WRN-VAL-01',
                        'product_name' => 'Bozuk Warning Ürünü',
                        'is_warning_sellable' => true,
                    ], JSON_UNESCAPED_UNICODE),
                    'product_snapshot' => '{"broken":',
                    'price_snapshot' => '{"list_price":10}',
                ]],
            ]);

        $response->assertRedirect('/admin/promotion-quotes/create');
        $response->assertSessionHasErrors([
            'error' => 'Teklif kaydedilemedi. Hatalı satırları kontrol edip tekrar deneyin.',
            'items.0.product_snapshot' => 'Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.',
        ]);
    }

    public function test_catalog_search_hides_parent_group_product_and_returns_sellable_variants_for_group_code(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $source = SupplierSource::query()->findOrFail(2);
        $supplier = Supplier::query()->findOrFail($source->supplier_id);

        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'visible_in_catalog' => true,
                'can_use_in_quotes' => true,
                'can_request_purchase' => true,
                'price_multiplier' => 1,
                'safe_stock_quantity' => 0,
                'export_allowed' => false,
            ]
        );

        $parent = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_sku' => 'ET-0506',
            'name' => 'ET-0506 Plastik Kalem',
            'product_code' => 'ET-0506',
            'product_name' => 'ET-0506 Plastik Kalem',
            'slug' => 'et-0506-plastik-kalem',
            'display_price' => 19.90,
            'currency' => 'TL',
            'total_stock_quantity' => 1250,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 1250,
            'safe_stock_quantity' => 0,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_group_code' => '0506',
                'supplier_product_code' => '0506',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => false,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'is_active' => true,
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'supplier_group_code' => '0506',
            ],
        ]);

        foreach ([
            ['code' => 'ET-0506-L', 'name' => 'Lacivert'],
            ['code' => 'ET-0506-S', 'name' => 'Siyah'],
            ['code' => 'ET-0506-MV', 'name' => 'Mavi'],
        ] as $variant) {
            TenantCatalogProductVariant::query()->create([
                'tenant_account_id' => $tenant->id,
                'tenant_catalog_product_id' => $parent->id,
                'variant_code' => $variant['code'],
                'variant_name' => $variant['name'],
                'variant_color' => $variant['name'],
                'image_url' => 'https://example.test/' . strtolower(str_replace(['ET-', ' '], ['', '-'], $variant['code'])) . '.jpg',
                'display_price' => 19.90,
                'currency' => 'TL',
                'stock_quantity' => 1250,
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 1250,
                'safe_stock_quantity' => 0,
                'visible_in_catalog' => true,
                'is_active' => true,
                'source_summary' => [
                    'supplier_id' => $supplier->id,
                    'supplier_source_id' => $source->id,
                    'supplier_group_code' => '0506',
                    'variant_stock_code' => str_replace('ET-', '', $variant['code']),
                ],
                'meta' => [
                    'is_variant' => true,
                    'is_sellable' => true,
                    'quote_search_visible' => true,
                    'parent_product_code' => 'ET-0506',
                    'supplier_group_code' => '0506',
                ],
            ]);
        }

        $response = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=0506'));

        $response->assertOk();
        $response->assertJsonMissing(['product_code' => 'ET-0506']);
        $response->assertJsonFragment(['product_code' => 'ET-0506-L']);
        $response->assertJsonFragment(['product_code' => 'ET-0506-S']);
        $response->assertJsonFragment(['product_code' => 'ET-0506-MV']);

        $results = collect($response->json());
        $variantRow = $results->firstWhere('product_code', 'ET-0506-L');
        $this->assertNotNull($variantRow);
        $this->assertSame('ET-0506-L Plastik Kalem Lacivert', $variantRow['product_name']);
        $this->assertArrayNotHasKey('group_product_code', $variantRow);
        $this->assertCount(3, $results->whereIn('product_code', ['ET-0506-L', 'ET-0506-S', 'ET-0506-MV']));
    }

    public function test_variant_display_names_are_sade_and_sales_friendly(): void
    {
        $akdenizVariant = new TenantCatalogProductVariant([
            'variant_code' => 'AK-1020',
            'variant_name' => 'AK-1020 Metal Tükenmez Rubber Gövde Kalem',
            'variant_color' => 'Lacivert',
            'meta' => ['parent_product_name' => 'Metal Tükenmez Rubber Gövde Kalem'],
        ]);
        $akdenizVariant->setRelation('catalogProduct', TenantCatalogProduct::make([
            'product_code' => 'AK-1020',
            'product_name' => 'Metal Tükenmez Rubber Gövde Kalem',
        ]));

        $yeniNesilVariant = new TenantCatalogProductVariant([
            'variant_code' => 'YN-209025',
            'variant_name' => 'YN-209025 AKDERE TABA TARİHLİ AJANDA 15X21 CM',
            'variant_color' => 'Taba',
            'meta' => ['parent_product_name' => 'AKDERE TABA TARİHLİ AJANDA 15X21 CM'],
        ]);
        $yeniNesilVariant->setRelation('catalogProduct', TenantCatalogProduct::make([
            'product_code' => 'YN-209025',
            'product_name' => 'AKDERE TABA TARİHLİ AJANDA 15X21 CM',
        ]));

        $ilpenVariant = new TenantCatalogProductVariant([
            'variant_code' => 'IL-1852',
            'variant_name' => 'IL-1852 Kutulu VIP Set',
            'variant_color' => 'Siyah',
            'meta' => ['parent_product_name' => 'Kutulu VIP Set'],
        ]);
        $ilpenVariant->setRelation('catalogProduct', TenantCatalogProduct::make([
            'product_code' => 'IL-1852',
            'product_name' => 'Kutulu VIP Set',
        ]));

        $etkinVariant = new TenantCatalogProductVariant([
            'variant_code' => 'ET-0506-K',
            'variant_name' => '0506-K Plastik Kalem',
            'variant_color' => 'Kırmızı',
            'meta' => ['parent_product_name' => 'Plastik Kalem'],
        ]);
        $etkinVariant->setRelation('catalogProduct', TenantCatalogProduct::make([
            'product_code' => 'ET-0506',
            'product_name' => 'Plastik Kalem',
        ]));

        $this->assertSame('AK-1020 Lacivert Metal Tükenmez Rubber Gövde Kalem', $akdenizVariant->display_name);
        $this->assertSame('YN-209025 Akdere Taba Tarihli Ajanda 15x21 cm', $yeniNesilVariant->display_name);
        $this->assertSame('IL-1852 Kutulu VIP Set Siyah', $ilpenVariant->display_name);
        $this->assertSame('ET-0506-K Plastik Kalem Kırmızı', $etkinVariant->display_name);
    }

    public function test_catalog_search_returns_normal_product_without_warning_badges(): void
    {
        $this->projectFixtureSourceToCatalog(3);

        $response = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=PB-4007'));

        $response->assertOk();

        $product = collect($response->json())->first(fn (array $row) => str_contains((string) ($row['product_name'] ?? ''), 'PB-4007'));

        $this->assertNotNull($product);
        $this->assertEquals(986.0, $product['list_price']);
        $this->assertNotContains('Net fiyat uyarısı', $product['warning_badges']);
    }

    public function test_quote_create_page_uses_single_line_layout_and_top_level_invoice_status(): void
    {
        $response = $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/promotion-quotes/create');

        $response->assertOk();
        $response->assertSee('Teklif Özeti');
        $response->assertSee('Teklif Durumu');
        $response->assertSee('Belge Türü');
        $response->assertSee('Fiş');
        $response->assertSee('Fatura');
        $response->assertSee('Kaydet');
        $response->assertSee('PDF, gönderim ve portal adımları kayıt sonrası teklif detay ekranında yönetilir.');
        $response->assertSee('No');
        $response->assertSee('Ürün');
        $response->assertSee('Liste');
        $response->assertSee('İskonto %');
        $response->assertSee('Birim Fiyat');
        $response->assertSee('Toplam');
        $response->assertSee('Baskı');
        $response->assertSee('Toplamlar teklif kalemleri değiştikçe anında güncellenir.');
        $response->assertSee('Ürün Toplamı');
        $response->assertSee('Baskı Toplamı');
        $response->assertSee('Ara Toplam');
        $response->assertSee('Genel toplam');
        $response->assertSee('Hızlı Aksiyon');
        $response->assertSee('class="pd-summary-stack-row pd-summary-stack-row-vat hidden" id="summary-vat-total-row"', false);
        $response->assertSee('id="summary-vat-breakdown" class="space-y-2 hidden"', false);
        $response->assertDontSee('Sade teklif akışı');
        $response->assertDontSee('Sipariş benzeri satır düzeni');
        $response->assertDontSee('Listeye Dön');
        $response->assertDontSee('Faturalı');
        $response->assertDontSee('Faturasız');
        $response->assertDontSee('Teklif Toplamı');
        $response->assertDontSee('Hızlı İşlemler');
        $response->assertDontSee('Aktif Tenant');
        $response->assertDontSee('Sistem Notu');
        $response->assertDontSee('KDV ürün satırında yönetilir');
        $response->assertSee('Ara Eleman Ayarla');
        $response->assertSee('Ara Eleman Gerekli');
        $response->assertSee('Ara Eleman Hesaplama');
        $response->assertDontSee('>Oto<', false);
        $response->assertDontSee('Hesaplanan:');
        $response->assertDontSee('Kopyala');
    }

    public function test_quote_create_markup_and_css_support_overlay_catalog_dropdown(): void
    {
        $response = $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/promotion-quotes/create');

        $response->assertOk();
        $response->assertSee('pd-catalog-search', false);
        $response->assertSee('pd-catalog-results hidden', false);

        $css = file_get_contents(public_path('css/prodelya-admin.css'));
        $this->assertStringContainsString('z-index: 1205;', $css);
        $this->assertStringContainsString('max-height: 320px;', $css);
        $this->assertStringContainsString('.pd-quote-workspace .pd-card {', $css);
        $this->assertStringContainsString('.pd-quote-workspace .pd-card-body', $css);
        $this->assertStringContainsString('overflow: visible;', $css);
    }

    public function test_quote_create_page_hides_legacy_right_summary_and_bottom_summary_duplicates(): void
    {
        $response = $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/promotion-quotes/create');

        $response->assertOk();
        $response->assertSee('Teklif Özeti');
        $response->assertDontSee('Aktif Tenant');
        $response->assertDontSee('Prodelya Core v1.0 aktif');
        $response->assertDontSee('Sistem Notu');
        $response->assertDontSee('Teklif Toplamı');
    }

    public function test_quote_create_page_contains_compact_print_block_without_legacy_fields(): void
    {
        $response = $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/promotion-quotes/create');

        $response->assertOk();
        $response->assertSee('Baskı türü');
        $response->assertSee('Baskı seçeneği');
        $response->assertSee('Baskı miktarı');
        $response->assertSee('Birim baskı fiyatı');
        $response->assertSee('Baskı adı');
        $response->assertSee('İşlem');
        $response->assertSee('Baskı toplamı');
        $response->assertDontSee('Baskı firması');
        $response->assertDontSee('Firma: İç Üretim');
        $response->assertDontSee('Baskı yeri');
        $response->assertDontSee('Baskı rengi');
        $response->assertDontSee('Baskı ölçüsü');
        $response->assertDontSee('Operasyon notu');
        $response->assertDontSee('Termin / üretim notu');
    }

    public function test_quote_create_page_contains_print_operation_model_options(): void
    {
        $response = $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/promotion-quotes/create');

        $response->assertOk();
        $response->assertSee('Tam yüzey UV');
        $response->assertSee('Logo lazer');
        $response->assertSee('Klişeli sıcak baskı');
        $response->assertSee('Klişe / kalıp seç');
        $response->assertSee('data-cliche-wrap', false);
        $response->assertDontSee('Baskı firması');
        $response->assertDontSee('Fason firma seç');
        $response->assertDontSee('İç üretim');
        $response->assertDontSee('Dış üretim / Fason');
        $response->assertDontSee('Baskı yeri');
        $response->assertDontSee('Baskı rengi');
        $response->assertDontSee('Baskı ölçüsü / alanı');
        $response->assertDontSee('Operasyon notu');
        $response->assertDontSee('Termin / üretim notu');
        $response->assertDontSee('TODO:');
    }

    public function test_quote_create_returns_field_validation_errors_without_generic_exception_message(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $response = $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from('/admin/promotion-quotes/create')
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Validation Test Ürünü',
                    'product_code' => 'VAL-001',
                    'quantity' => '1',
                    'unit' => 'Adet',
                    'list_price' => '10',
                    'discount_rate' => '0',
                    'unit_price' => '10',
                ]],
            ]);

        $response->assertRedirect('/admin/promotion-quotes/create');
        $response->assertSessionHasErrors(['invoice_status']);
        $this->assertSame('', (string) session('errors')?->getBag('default')->first('error'));
    }

    public function test_quote_show_displays_warning_badges_and_list_price_from_selected_catalog_product(): void
    {
        $this->projectFixtureSourceToCatalog(3);

        $searchResponse = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=F-112-32'));

        $product = collect($searchResponse->json())->first(fn (array $row) => str_contains((string) ($row['product_name'] ?? ''), 'F-112-32'));
        $this->assertNotNull($product);

        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $createResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'valid_until' => now()->addWeek()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'vat_rate' => 20,
                'delivery_type' => 'Kargo',
                'notes' => 'Uyarı görünüm testi',
                'items' => [[
                    'product_name' => $product['product_name'],
                    'product_code' => $product['product_code'],
                    'quantity' => '5',
                    'unit' => 'Adet',
                    'list_price' => '280.55',
                    'discount_rate' => '0',
                    'unit_price' => '280.55',
                    'tenant_catalog_product_id' => $product['id'],
                    'standard_product_id' => data_get($product, 'product_snapshot.standard_product_id'),
                    'supplier_source_id' => data_get($product, 'source_summary.0.supplier_source_id'),
                    'catalog_source' => 'tenant_catalog',
                    'product_snapshot' => json_encode($product['product_snapshot'], JSON_UNESCAPED_UNICODE),
                    'price_snapshot' => json_encode([
                        'display_price' => $product['display_price'],
                        'list_price' => $product['list_price'],
                        'currency' => $product['currency'],
                        'vat_rate' => $product['vat_rate'],
                        'warning_badges' => $product['warning_badges'],
                        'warning_messages' => $product['warning_messages'],
                        'net_price_warning' => $product['net_price_warning'],
                        'price_policy_warning' => $product['price_policy_warning'],
                        'pricing_policy_type' => $product['pricing_policy_type'],
                    ], JSON_UNESCAPED_UNICODE),
                    'stock_snapshot' => json_encode([
                        'visible_stock_quantity' => $product['visible_stock_quantity'],
                        'warning_flag' => $product['warning_flag'],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ]);

        $quote = Order::query()->latest('id')->firstOrFail();
        $createResponse->assertRedirectToRoute('admin.promotion-quotes.show', $quote);
        $item = $quote->items()->firstOrFail();

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $this->assertContains('Net fiyat uyarısı', data_get($item->price_snapshot, 'warning_badges', []));
        $this->assertTrue(collect(data_get($item->price_snapshot, 'warning_messages', []))->contains(
            fn (string $message) => str_contains($message, 'standart iskonto uygulanmamalı')
        ));

        $showResponse->assertOk();
        $showResponse->assertSee($product['product_name']);
        $showResponse->assertSee('Teklif');
        $showResponse->assertSee('Genel Toplam');
        $showResponse->assertDontSee('Net fiyat:');
        $showResponse->assertDontSee('Aktif Tenant');
        $showResponse->assertDontSee('Sistem Notu');
    }

    public function test_quote_show_displays_local_stock_priority_and_supplier_stock_snapshot(): void
    {
        $this->projectFixtureSourceToCatalog(3);

        $catalogProduct = TenantCatalogProduct::query()
            ->where('product_name', 'like', '%PB-4007%')
            ->firstOrFail();

        $catalogProduct->forceFill([
            'local_stock_quantity' => 12,
            'supplier_stock_quantity' => 2531,
            'total_stock_quantity' => 2531,
            'local_stock_priority' => true,
            'visible_in_quote' => true,
        ])->save();

        $catalogVariant = TenantCatalogProductVariant::query()
            ->where('tenant_catalog_product_id', $catalogProduct->id)
            ->firstOrFail();

        TenantLocalStock::query()->create([
            'tenant_account_id' => $catalogProduct->tenant_account_id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'tenant_catalog_product_variant_id' => $catalogVariant->id,
            'stock_scope' => 'variant',
            'warehouse_code' => 'TEST-LOCAL',
            'quantity_on_hand' => 12,
            'quantity_reserved' => 0,
            'quantity_available' => 12,
            'reorder_level' => 0,
            'legacy_assignment_status' => null,
        ]);

        $searchResponse = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=PB-4007'))
            ->assertOk();

        $product = collect($searchResponse->json())
            ->first(fn (array $row) => str_contains((string) ($row['product_name'] ?? ''), 'PB-4007'));

        $this->assertNotNull($product);
        $this->assertSame(12.0, (float) $product['visible_stock_quantity']);

        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'vat_rate' => 20,
                'items' => [[
                    'product_name' => $product['product_name'],
                    'product_code' => $product['product_code'],
                    'quantity' => '5',
                    'unit' => 'Adet',
                    'list_price' => (string) $product['list_price'],
                    'discount_rate' => '0',
                    'unit_price' => (string) $product['list_price'],
                    'tenant_catalog_product_id' => $product['id'],
                    'standard_product_id' => data_get($product, 'product_snapshot.standard_product_id'),
                    'supplier_source_id' => data_get($product, 'source_summary.0.supplier_source_id'),
                    'catalog_source' => 'tenant_catalog',
                    'product_snapshot' => json_encode($product['product_snapshot'], JSON_UNESCAPED_UNICODE),
                    'price_snapshot' => json_encode([
                        'display_price' => $product['display_price'],
                        'list_price' => $product['list_price'],
                        'currency' => $product['currency'],
                        'vat_rate' => $product['vat_rate'],
                        'warning_badges' => $product['warning_badges'],
                        'warning_messages' => $product['warning_messages'],
                    ], JSON_UNESCAPED_UNICODE),
                    'stock_snapshot' => json_encode([
                        'local_stock_quantity' => $product['local_stock_quantity'],
                        'supplier_stock_quantity' => $product['supplier_stock_quantity'],
                        'visible_stock_quantity' => $product['visible_stock_quantity'],
                        'local_stock_priority' => $product['local_stock_priority'],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = $quote->items()->firstOrFail();

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $this->assertSame(12.0, (float) data_get($item->stock_snapshot, 'local_stock_quantity'));
        $this->assertSame(2531.0, (float) data_get($item->stock_snapshot, 'supplier_stock_quantity'));
        $this->assertSame(12.0, (float) data_get($item->stock_snapshot, 'visible_stock_quantity'));

        $showResponse->assertOk();
        $showResponse->assertSee($product['product_name']);
        $showResponse->assertSee('Teklif');
        $showResponse->assertDontSee('Local Stok');
        $showResponse->assertDontSee('Kopyala');
    }

    public function test_invoice_status_controls_all_quote_line_vat_modes(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [
                    [
                        'product_name' => 'Uyumluluk Ürünü 1',
                        'product_code' => 'UYM-1',
                        'quantity' => '2',
                        'unit' => 'Adet',
                        'list_price' => '100',
                        'discount_rate' => '0',
                        'unit_price' => '100',
                        'price_snapshot' => json_encode(['vat_rate' => 20], JSON_UNESCAPED_UNICODE),
                    ],
                    [
                        'product_name' => 'Uyumluluk Ürünü 2',
                        'product_code' => 'UYM-2',
                        'quantity' => '1',
                        'unit' => 'Adet',
                        'list_price' => '100',
                        'discount_rate' => '0',
                        'unit_price' => '100',
                        'price_snapshot' => json_encode(['vat_rate' => 20], JSON_UNESCAPED_UNICODE),
                    ],
                    [
                        'product_name' => 'Uyumluluk Ürünü 3',
                        'product_code' => 'UYM-3',
                        'quantity' => '1',
                        'unit' => 'Adet',
                        'list_price' => '100',
                        'discount_rate' => '0',
                        'unit_price' => '100',
                        'price_snapshot' => json_encode(['vat_rate' => 20], JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();
        $items = $quote->items()->orderBy('id')->get();

        $this->assertSame('taxable', data_get($items[0]->price_snapshot, 'vat_mode'));
        $this->assertSame('taxable', data_get($items[1]->price_snapshot, 'vat_mode'));
        $this->assertSame('taxable', data_get($items[2]->price_snapshot, 'vat_mode'));
    }

    public function test_taxable_quote_item_and_print_rows_keep_net_totals_and_store_vat_separately(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'KDV Var Test',
                    'product_code' => 'KDV-VAR',
                    'quantity' => '2',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'price_snapshot' => json_encode(['vat_rate' => 20], JSON_UNESCAPED_UNICODE),
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'Sıcak Baskı',
                        'print_option' => 'Klişeli sıcak baskı',
                        'production_type' => 'Dış üretim / Fason',
                        'print_quantity' => '2',
                        'print_unit_price' => '25',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = $quote->items()->latest('id')->firstOrFail();

        $this->assertSame(50.0, (float) $item->print_total);
        $this->assertSame(200.0, (float) data_get($item->price_snapshot, 'product_line_total'));
        $this->assertSame(50.0, (float) data_get($item->price_snapshot, 'print_line_total'));
        $this->assertSame(200.0, (float) data_get($item->price_snapshot, 'product_total'));
        $this->assertSame(50.0, (float) data_get($item->price_snapshot, 'print_total'));
        $this->assertSame(250.0, (float) data_get($item->price_snapshot, 'line_net_total'));
        $this->assertSame(50.0, (float) data_get($item->price_snapshot, 'line_vat_total'));
        $this->assertSame(300.0, (float) data_get($item->price_snapshot, 'line_gross_total'));
        $this->assertSame(200.0, (float) $item->line_total);
        $this->assertSame(20.0, (float) data_get($item->price_snapshot, 'print_vat_rate'));
    }

    public function test_product_vat_rate_change_does_not_change_default_print_vat_rate(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Ayrik KDV Test',
                    'product_code' => 'AYR-KDV',
                    'quantity' => '1',
                    'unit' => 'Adet',
                    'list_price' => '1000',
                    'discount_rate' => '0',
                    'unit_price' => '1000',
                    'vat_rate' => '10',
                    'price_snapshot' => json_encode(['vat_rate' => 10], JSON_UNESCAPED_UNICODE),
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf baskılı',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '1',
                        'print_unit_price' => '500',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = $quote->items()->latest('id')->firstOrFail();

        $this->assertSame(10.0, (float) data_get($item->price_snapshot, 'vat_rate'));
        $this->assertSame(20.0, (float) data_get($item->price_snapshot, 'print_vat_rate'));
        $this->assertSame(1500.0, (float) data_get($item->price_snapshot, 'line_net_total'));
        $this->assertSame(200.0, (float) data_get($item->price_snapshot, 'line_vat_total'));
        $this->assertSame(1700.0, (float) data_get($item->price_snapshot, 'line_gross_total'));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $response->assertOk();
        $response->assertSee('Genel Toplam');
        $response->assertSee('1.700,00 TL');
    }

    public function test_quote_edit_and_show_render_two_decimal_values_and_keep_product_and_print_totals_separate(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Format Ayrim Test',
                    'product_code' => 'FMT-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '16',
                    'discount_rate' => '0',
                    'unit_price' => '16',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf baskılı',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '50',
                            'print_unit_price' => '5',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Tek taraf lazer',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = $quote->items()->with('prints')->firstOrFail();

        $this->assertSame(1600.0, (float) $item->line_total);
        $this->assertSame(1250.0, (float) $item->prints()->sum('print_total'));
        $this->assertSame(1600.0, (float) data_get($item->price_snapshot, 'product_total'));
        $this->assertSame(1250.0, (float) data_get($item->price_snapshot, 'print_total'));
        $this->assertSame(2850.0, (float) $quote->subtotal);

        $editResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}/edit");

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $editResponse->assertOk();
        $editResponse->assertSee('100.00');
        $editResponse->assertSee('16.00');
        $editResponse->assertDontSee('100.0000');
        $editResponse->assertDontSee('16.0000');

        $showResponse->assertOk();
        $showResponse->assertSee('100,00');
        $showResponse->assertSee('1.600,00 TL');
        $showResponse->assertSee('Format Ayrim Test');
        $showResponse->assertDontSee('100,0000');
        $showResponse->assertDontSee('5,0000');
    }

    public function test_quote_uses_automatic_calculated_unit_price_when_not_manual(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Otomatik Birim Fiyat',
                    'product_code' => 'OTO-1',
                    'quantity' => '1',
                    'unit' => 'Adet',
                    'list_price' => '9.20',
                    'discount_rate' => '45',
                    'unit_price' => '9.20',
                    'manual_unit_price' => '0',
                    'price_snapshot' => json_encode(['vat_rate' => 20], JSON_UNESCAPED_UNICODE),
                ]],
            ])
            ->assertRedirect();

        $item = Order::query()->latest('id')->firstOrFail()->items()->firstOrFail();

        $this->assertSame(5.06, round((float) $item->unit_price, 2));
        $this->assertFalse((bool) data_get($item->price_snapshot, 'manual_unit_price'));
        $this->assertSame(5.06, round((float) data_get($item->price_snapshot, 'calculated_unit_price'), 2));
    }

    public function test_quote_keeps_manual_unit_price_when_user_overrides_it(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Manuel Birim Fiyat',
                    'product_code' => 'MAN-1',
                    'quantity' => '1',
                    'unit' => 'Adet',
                    'list_price' => '9.20',
                    'discount_rate' => '45',
                    'unit_price' => '5.00',
                    'manual_unit_price' => '1',
                    'price_snapshot' => json_encode(['vat_rate' => 20], JSON_UNESCAPED_UNICODE),
                ]],
            ])
            ->assertRedirect();

        $item = Order::query()->latest('id')->firstOrFail()->items()->firstOrFail();

        $this->assertSame(5.00, round((float) $item->unit_price, 2));
        $this->assertTrue((bool) data_get($item->price_snapshot, 'manual_unit_price'));
        $this->assertSame(5.06, round((float) data_get($item->price_snapshot, 'calculated_unit_price'), 2));
    }

    public function test_no_vat_quote_item_and_print_rows_do_not_add_vat(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'KDV Yok Test',
                    'product_code' => 'KDV-YOK',
                    'quantity' => '2',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'price_snapshot' => json_encode(['vat_rate' => 20], JSON_UNESCAPED_UNICODE),
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tam yüzey UV',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '2',
                        'print_unit_price' => '25',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = $quote->items()->latest('id')->firstOrFail();

        $this->assertSame(0.0, (float) data_get($item->price_snapshot, 'line_vat_total'));
        $this->assertSame(250.0, (float) data_get($item->price_snapshot, 'line_net_total'));
        $this->assertSame(250.0, (float) data_get($item->price_snapshot, 'line_gross_total'));
    }

    public function test_quote_show_displays_vat_breakdown_for_multiple_rates(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [
                    [
                        'product_name' => 'KDV 20 Test',
                        'product_code' => 'KDV20',
                        'quantity' => '2',
                        'unit' => 'Adet',
                        'list_price' => '100',
                        'discount_rate' => '0',
                        'unit_price' => '100',
                        'vat_rate' => '20',
                        'price_snapshot' => json_encode(['vat_rate' => 20], JSON_UNESCAPED_UNICODE),
                    ],
                    [
                        'product_name' => 'KDV 10 Test',
                        'product_code' => 'KDV10',
                        'quantity' => '1',
                        'unit' => 'Adet',
                        'list_price' => '100',
                        'discount_rate' => '0',
                        'unit_price' => '100',
                        'vat_rate' => '10',
                        'price_snapshot' => json_encode(['vat_rate' => 10], JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $response->assertOk();
        $response->assertSee('Genel Toplam');
        $response->assertSee('KDV 20 Test');
        $response->assertSee('KDV 10 Test');
    }

    public function test_quote_can_store_multiple_print_operations_and_show_displays_them(): void
    {
        $this->projectFixtureSourceToCatalog(2);

        $searchResponse = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=0506-L'))
            ->assertOk();

        $product = collect($searchResponse->json())
            ->first(fn (array $row) => ($row['product_code'] ?? null) === 'ET-0506-L');

        $this->assertNotNull($product);

        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'vat_rate' => 20,
                'items' => [[
                    'product_name' => $product['product_name'],
                    'product_code' => $product['product_code'],
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => (string) $product['list_price'],
                    'discount_rate' => '0',
                    'unit_price' => (string) $product['list_price'],
                    'tenant_catalog_product_id' => $product['id'],
                    'catalog_source' => 'tenant_catalog',
                    'product_snapshot' => json_encode($product['product_snapshot'], JSON_UNESCAPED_UNICODE),
                    'price_snapshot' => json_encode([
                        'display_price' => $product['display_price'],
                        'list_price' => $product['list_price'],
                        'currency' => $product['currency'],
                        'vat_rate' => $product['vat_rate'],
                    ], JSON_UNESCAPED_UNICODE),
                    'stock_snapshot' => json_encode([
                        'visible_stock_quantity' => $product['visible_stock_quantity'],
                    ], JSON_UNESCAPED_UNICODE),
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek renk',
                            'print_location' => 'Ön yüz',
                            'production_type' => 'İç üretim',
                            'print_color' => 'Siyah',
                            'print_size' => '80x40 mm',
                            'print_quantity' => '100',
                            'print_unit_price' => '3.50',
                            'note' => 'Logo uygulaması',
                            'production_note' => 'İç üretim hattında hazırlanacak',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Ek operasyon',
                            'print_location' => 'Arka yüz',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $customer->id,
                            'print_color' => '',
                            'print_size' => '60x20 mm',
                            'print_quantity' => '100',
                            'print_unit_price' => '2.25',
                            'note' => 'Seri numarası',
                            'production_note' => 'Fason operasyon ile tamamlanacak',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = $quote->items()->with('prints')->firstOrFail();

        $this->assertCount(2, $item->prints);

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $showResponse->assertOk();
        $showResponse->assertSee('UV Baskı');
        $showResponse->assertSee('Lazer');
        $showResponse->assertSee('Tek renk');
        $showResponse->assertSee('Ek operasyon');
        $showResponse->assertSee('Birim baskı fiyatı');
        $showResponse->assertSee('Baskı toplamı');
        $showResponse->assertSee('Logo uygulaması');
        $showResponse->assertSee('Seri numarası');
        $showResponse->assertDontSee('Baskı firması');
        $showResponse->assertDontSee('İç üretim');
        $showResponse->assertDontSee('Baskı yeri');
        $showResponse->assertDontSee('Baskı rengi');
        $showResponse->assertDontSee('Baskı alanı');
        $showResponse->assertDontSee('Operasyon notu');
        $showResponse->assertDontSee('Termin / üretim notu');
        $showResponse->assertDontSee('Aktif Tenant');
        $showResponse->assertDontSee('Sistem Notu');
    }

    public function test_quote_show_displays_short_red_warning_badge_for_special_priced_product(): void
    {
        $this->projectFixtureSourceToCatalog(2);

        $searchResponse = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=0506-L'))
            ->assertOk();

        $product = collect($searchResponse->json())
            ->first(fn (array $row) => ($row['product_code'] ?? null) === 'ET-0506-L');

        $this->assertNotNull($product);

        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => $product['product_name'],
                    'product_code' => $product['product_code'],
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'list_price' => (string) $product['list_price'],
                    'discount_rate' => '0',
                    'unit_price' => (string) $product['list_price'],
                    'tenant_catalog_product_id' => $product['id'],
                    'catalog_source' => 'tenant_catalog',
                    'product_snapshot' => json_encode($product['product_snapshot'], JSON_UNESCAPED_UNICODE),
                    'price_snapshot' => json_encode([
                        'display_price' => $product['display_price'],
                        'list_price' => $product['list_price'],
                        'currency' => $product['currency'],
                        'vat_rate' => $product['vat_rate'],
                        'warning_badges' => $product['warning_badges'],
                        'warning_messages' => $product['warning_messages'],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = $quote->items()->firstOrFail();

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $this->assertContains('Kırmızı Ürün', data_get($item->price_snapshot, 'warning_badges', []));

        $showResponse->assertOk();
        $showResponse->assertSee($product['product_name']);
        $showResponse->assertSee('Teklif');
    }

    public function test_quote_workspace_source_contains_readonly_product_code_and_sku_dropdown_meta(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertStringContainsString('name="items[${item._index}][product_code]"', $contents);
        $this->assertStringNotContainsString('<label class="pd-label">Ürün kodu</label>', $contents);
        $this->assertStringContainsString('SKU: ${code}', $contents);
    }

    public function test_quote_edit_and_show_keep_same_sade_print_language(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Tutarlılık Ürünü',
                    'product_code' => 'TUT-1',
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'list_price' => '15',
                    'discount_rate' => '0',
                    'unit_price' => '15',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'Sıcak Baskı',
                        'print_option' => 'Klişeli sıcak baskı',
                        'production_type' => 'Dış üretim / Fason',
                        'print_quantity' => '10',
                        'print_unit_price' => '2',
                        'cliche_status' => 'Var',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->latest('id')->firstOrFail();

        $editResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}/edit");

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $editResponse->assertOk()
            ->assertSee('Baskı adı')
            ->assertDontSee('Baskı firması')
            ->assertDontSee('Firma: İç Üretim')
            ->assertDontSee('Baskı yeri')
            ->assertDontSee('Baskı rengi')
            ->assertDontSee('Baskı ölçüsü');

        $showResponse->assertOk()
            ->assertSee('Birim baskı fiyatı')
            ->assertSee('Baskı toplamı')
            ->assertDontSee('Baskı firması')
            ->assertDontSee('Firma: İç Üretim')
            ->assertDontSee('Baskı yeri')
            ->assertDontSee('Baskı rengi')
            ->assertDontSee('Baskı ölçüsü');
    }

    public function test_catalog_index_uses_small_thumbnail_class(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();

        TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_sku' => 'TEST-THUMB',
            'name' => 'TEST-THUMB Satılabilir Ürün',
            'product_code' => 'TEST-THUMB',
            'product_name' => 'TEST-THUMB Satılabilir Ürün',
            'image_url' => 'https://example.test/thumb.jpg',
            'currency' => 'TL',
            'display_price' => 10,
            'sale_price' => 10,
            'total_stock_quantity' => 5,
            'supplier_stock_quantity' => 5,
            'local_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'is_active' => true,
            'meta' => [
                'is_parent' => false,
                'is_variant' => false,
                'is_sellable' => true,
            ],
        ]);

        $response = $this->actingAs($this->tenantUser)
            ->get($this->tenantUrl('/admin/catalog'));

        $response->assertOk();
        $response->assertSee('catalog-product-thumb', false);
    }

    public function test_catalog_search_handles_empty_meta_without_crashing(): void
    {
        $this->projectFixtureSourceToCatalog(3);

        $catalogProduct = TenantCatalogProduct::query()->where('product_name', 'like', '%PB-4007%')->firstOrFail();
        $catalogProduct->forceFill([
            'meta' => [],
            'source_summary' => [],
        ])->save();


        $response = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=PB-4007'));

        $response->assertOk();
    }

    public function test_promotion_quote_persists_quote_fields_net_totals_and_vat_breakdown_then_can_switch_to_fis(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $createResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Gercek payload smoke',
                'items' => [[
                    'product_name' => 'Smoke Test Kalem',
                    'product_code' => 'SMOKE-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '8.60',
                    'discount_rate' => '45',
                    'unit_price' => '4.70',
                    'manual_unit_price' => '1',
                    'vat_rate' => '10',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf baskılı',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '5',
                            'note' => 'test baskı',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Tek taraf lazer',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                            'note' => 'test ikinci baskı',
                        ],
                    ],
                ]],
            ]);

        $quote = Order::query()->latest('id')->firstOrFail();
        $item = $quote->items()->with('prints')->firstOrFail();

        $createResponse->assertRedirectToRoute('admin.promotion-quotes.show', $quote);
        $this->assertSame('2026-06-12', optional($quote->quote_date)->format('Y-m-d'));
        $this->assertSame('2026-06-19', optional($quote->valid_until)->format('Y-m-d'));
        $this->assertSame('fatura', $quote->invoice_status);
        $this->assertSame('Kargo', $quote->delivery_type);
        $this->assertSame('Gercek payload smoke', $quote->notes);
        $this->assertEquals(470.0, (float) $item->line_total);
        $this->assertEquals(1500.0, (float) $item->prints()->sum('print_total'));
        $this->assertTrue((bool) data_get($item->price_snapshot, 'manual_unit_price'));
        $this->assertEquals(10.0, (float) data_get($item->price_snapshot, 'vat_rate'));
        $this->assertEquals(20.0, (float) data_get($item->price_snapshot, 'print_vat_rate'));
        $this->assertEquals(470.0, (float) data_get($item->price_snapshot, 'product_total'));
        $this->assertEquals(1500.0, (float) data_get($item->price_snapshot, 'print_total'));
        $this->assertEquals(1970.0, (float) data_get($item->price_snapshot, 'subtotal'));
        $this->assertEquals(1970.0, (float) data_get($item->price_snapshot, 'line_net_total'));
        $this->assertEquals(347.0, (float) data_get($item->price_snapshot, 'line_vat_total'));
        $this->assertEqualsCanonicalizing([
            ['rate' => 10.0, 'total' => 47.0, 'scope' => 'product'],
            ['rate' => 20.0, 'total' => 300.0, 'scope' => 'print'],
        ], array_map(static fn (array $slice) => [
            'rate' => (float) $slice['rate'],
            'total' => (float) $slice['total'],
            'scope' => $slice['scope'],
        ], data_get($item->price_snapshot, 'vat_breakdown', [])));
        $this->assertEquals(1970.0, (float) $quote->subtotal);
        $this->assertEquals(347.0, (float) $quote->vat_total);
        $this->assertEquals(2317.0, (float) $quote->grand_total);

        $showResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}");

        $editResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/promotion-quotes/{$quote->id}/edit");

        $showResponse->assertOk()
            ->assertSee('Genel Toplam');
        $editResponse->assertOk()
            ->assertSee('option value="fatura" selected', false);

        $updateResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put("/admin/promotion-quotes/{$quote->id}", [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Gercek payload smoke fis',
                'items' => [[
                    'product_name' => 'Smoke Test Kalem',
                    'product_code' => 'SMOKE-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '8.60',
                    'discount_rate' => '45',
                    'unit_price' => '4.70',
                    'manual_unit_price' => '1',
                    'vat_rate' => '10',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf baskılı',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '5',
                            'note' => 'test baskı',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Tek taraf lazer',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                            'note' => 'test ikinci baskı',
                        ],
                    ],
                ]],
            ]);

        $quote->refresh();
        $item = $quote->items()->with('prints')->firstOrFail();

        $updateResponse->assertRedirectToRoute('admin.promotion-quotes.show', $quote);
        $this->assertSame('fis', $quote->invoice_status);
        $this->assertSame('Gercek payload smoke fis', $quote->notes);
        $this->assertEquals(1970.0, (float) $quote->subtotal);
        $this->assertEquals(0.0, (float) $quote->vat_total);
        $this->assertEquals(1970.0, (float) $quote->grand_total);
        $this->assertEquals(470.0, (float) $item->line_total);
        $this->assertTrue((bool) data_get($item->price_snapshot, 'manual_unit_price'));
        $this->assertEquals(0.0, (float) data_get($item->price_snapshot, 'vat_rate'));
        $this->assertEquals(0.0, (float) data_get($item->price_snapshot, 'print_vat_rate'));
        $this->assertEquals([], data_get($item->price_snapshot, 'vat_breakdown', []));
    }

    public function test_promotion_quote_missing_quote_date_column_message_is_humanized(): void
    {
        $controller = app(\App\Http\Controllers\Admin\PromotionQuoteController::class);
        $reflection = new \ReflectionMethod($controller, 'humanizeQuoteException');
        $reflection->setAccessible(true);

        $message = $reflection->invoke(
            $controller,
            new \RuntimeException('SQLSTATE[HY000]: General error: 1 table orders has no column named quote_date')
        );

        $this->assertSame(
            'Teklif kaydedilemedi. Veritabani semasi guncel degil; orders.quote_date alani eksik. Migration calistirilmali.',
            $message
        );
    }

    private function projectFixtureSourceToCatalog(int $sourceId): void
    {
        $source = SupplierSource::query()->findOrFail($sourceId);
        $this->prepareFixtureForSource($source);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                'confirm_stage' => '1',
            ])
            ->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/build-standard-products")
            ->assertRedirect('/admin/super-admin/product-data-hub/raw-products');

        $this->actingAs($this->tenantUser)
            ->post($this->tenantUrl('/admin/catalog/project'))
            ->assertRedirect('/admin/catalog');
    }

    private function tenantHost(): string
    {
        return $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenantHost() . $path;
    }

    private function makeTenantOwner(TenantAccount $tenant, string $email): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $tenant->name . ' Warning Owner',
                'password' => 'secret-password',
                'is_platform_admin' => false,
            ]
        );

        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();

        UserRole::query()->firstOrCreate([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        return $user;
    }

    private function prepareFixtureForSource(SupplierSource $source): void
    {
        $fixturesDir = storage_path('framework/testing/product-selection-warning-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        [$content, $nodePath] = match ($source->id) {
            2 => [$this->etkinFixtureXml(), 'urun'],
            3 => [$this->akdenizFixtureXml(), 'RECORD'],
            default => throw new \RuntimeException('Bu test için fixture tanımlanmamış source id: ' . $source->id),
        };

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $content);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['source_file_path'] = $filePath;
        $config['product_node_path'] = $nodePath;

        $source->forceFill([
            'source_type' => 'xml',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();

    }

    private function etkinFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_id>ET-100</urun_id>
        <urun_kodu>0506-L</urun_kodu>
        <urun_grupkodu>0506</urun_grupkodu>
        <urun_adi>Plastik Kalem Lacivert</urun_adi>
        <urun_resim>https://example.test/kalem.jpg</urun_resim>
        <urun_kategori>Kalemler</urun_kategori>
        <urun_stok>1250</urun_stok>
        <urun_fiyat>19.90</urun_fiyat>
        <urun_kirmizi>1</urun_kirmizi>
    </urun>
</urunler>
XML;
    }

    private function akdenizFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <RECORD>
        <urun_id>1466</urun_id>
        <urunkodu>PB-4007</urunkodu>
        <urunattr_id>PB4007-A</urunattr_id>
        <urunattrgr>PB-4007</urunattrgr>
        <urunattradi>PB-4007 Siyah</urunattradi>
        <urunadi>PB-4007 Siyah 10.000 mAh Powerbank</urunadi>
        <pure_prodname>10.000 mAh Powerbank</pure_prodname>
        <listefiyati>986.00</listefiyati>
        <iskonto>0.45</iskonto>
        <netfiyat>542.30</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <listefiyatkapali>986.00</listefiyatkapali>
        <stokmiktar>2531</stokmiktar>
        <stokresim>https://example.test/pb-4007-stok.jpg</stokresim>
        <urunresim>https://example.test/pb-4007-1.jpg</urunresim>
        <urunresim2>https://example.test/pb-4007-2.jpg</urunresim2>
        <discat_name>5 - Teknoloji Ürünleri TL</discat_name>
        <kategori>Teknoloji Ürünleri</kategori>
    </RECORD>
    <RECORD>
        <urun_id>830</urun_id>
        <urunkodu>F-112-32</urunkodu>
        <urunattr_id>F11232-A</urunattr_id>
        <urunattrgr>F-112-32</urunattrgr>
        <urunattradi>F-112-32 Metal</urunattradi>
        <urunadi>F-112-32 Metal 32 GB Usb Bellek</urunadi>
        <pure_prodname>32 GB Usb Bellek</pure_prodname>
        <listefiyati>280.55</listefiyati>
        <iskonto>0.00</iskonto>
        <netfiyat>280.55</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <listefiyatkapali>0</listefiyatkapali>
        <stokmiktar>0</stokmiktar>
        <fiyataciklamasi>Fiyat alınız.</fiyataciklamasi>
        <discat_name>1 - Usb Bellekler NET</discat_name>
        <stokresim>https://example.test/f-112-32-stok.jpg</stokresim>
        <urunresim>https://example.test/f-112-32-1.jpg</urunresim>
        <kategori>Usb Bellekler</kategori>
    </RECORD>
</ROOT>
XML;
    }
}
