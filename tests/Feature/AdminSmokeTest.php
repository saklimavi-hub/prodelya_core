<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductImage;
use App\Models\StandardProductVariant;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierProductRaw;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductImage;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ProductFieldDictionaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSmokeTest extends TestCase
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
        $this->tenantUser = $this->makeTenantOwner($this->tenant, 'catalog-smoke-owner@example.test');
    }

    public static function tenantAdminUrls(): array
    {
        return [
            'admin dashboard' => ['/admin/dashboard', 200],
            'companies index' => ['/admin/companies', 200],
            'companies create' => ['/admin/companies/create', 200],
            'companies import' => ['/admin/companies/import', 200],
            'promotion quotes index' => ['/admin/promotion-quotes', 200],
            'promotion quotes create' => ['/admin/promotion-quotes/create', 200],
            'print service quotes index' => ['/admin/print-service-quotes', 403],
            'print service quotes create' => ['/admin/print-service-quotes/create', 403],
            'orders index' => ['/admin/orders', 200],
            'orders create' => ['/admin/orders/create', 302],
            'product data hub index' => ['/admin/product-data-hub', 403],
            'product data hub sources' => ['/admin/product-data-hub/sources', 403],
            'product data hub product mappings' => ['/admin/product-data-hub/product-mappings', 403],
            'catalog index' => ['/admin/catalog', 200],
            'product data hub tenant access' => ['/admin/product-data-hub/tenant-access', 403],
            'product data hub exports' => ['/admin/product-data-hub/exports', 403],
            'product data hub logs' => ['/admin/product-data-hub/logs', 403],
        ];
    }

    public static function superAdminUrls(): array
    {
        return [
            'super admin dashboard' => ['/admin/super-admin/dashboard'],
            'super admin tenants' => ['/admin/super-admin/tenants'],
            'super admin modules' => ['/admin/super-admin/modules'],
            'super admin settings' => ['/admin/super-admin/settings'],
            'super admin product data hub' => ['/admin/super-admin/product-data-hub'],
            'super admin product data hub pipeline' => ['/admin/super-admin/product-data-hub/pipeline'],
            'super admin product data hub profile comparison' => ['/admin/super-admin/product-data-hub/profile-comparison'],
            'super admin product data hub sources' => ['/admin/super-admin/product-data-hub/sources'],
            'super admin product data hub sources create' => ['/admin/super-admin/product-data-hub/sources/create'],
            'super admin product data hub sources edit' => ['/admin/super-admin/product-data-hub/sources/1/edit'],
            'super admin product data hub sources preview' => ['/admin/super-admin/product-data-hub/sources/1/preview'],
            'super admin product data hub field mappings' => ['/admin/super-admin/product-data-hub/field-mappings'],
            'super admin product data hub source field mappings' => ['/admin/super-admin/product-data-hub/field-mappings/source/1'],
            'super admin product data hub category mappings' => ['/admin/super-admin/product-data-hub/category-mappings'],
            'super admin product data hub raw products' => ['/admin/super-admin/product-data-hub/raw-products'],
            'super admin product data hub supplier products' => ['/admin/super-admin/product-data-hub/supplier-products'],
            'super admin product data hub standard products' => ['/admin/super-admin/product-data-hub/standard-products'],
            'super admin tenant supplier access' => ['/admin/super-admin/tenant-supplier-access'],
            'super admin standard categories' => ['/admin/super-admin/standard-categories'],
            'super admin standard categories create' => ['/admin/super-admin/standard-categories/create'],
            'super admin standard categories bulk paste' => ['/admin/super-admin/standard-categories/bulk-paste'],
            'super admin standard categories import' => ['/admin/super-admin/standard-categories/import'],
        ];
    }

    public function test_login_page_is_publicly_accessible(): void
    {
        $this->getOnCentralHost('/login')
            ->assertOk();
    }

    #[DataProvider('tenantAdminUrls')]
    public function test_tenant_admin_pages_open_for_seeded_admin_with_local_fallback(string $url, int $expectedStatus): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getOnCentralHost($url);

        if ($expectedStatus === 302) {
            $response->assertRedirect('/admin/orders');
            return;
        }

        $response->assertStatus($expectedStatus);
    }

    #[DataProvider('superAdminUrls')]
    public function test_super_admin_pages_open_for_seeded_admin_in_central_context(string $url): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getOnCentralHost($url);
        $response->assertOk();
    }

    public function test_super_admin_standard_category_toggle_active_and_catalog_routes_work(): void
    {
        $this->actingAs($this->adminUser);

        $category = StandardCategory::query()->create([
            'code' => 'TEST-CAT-001',
            'name' => 'Test Kategori',
            'slug' => 'test-kategori',
            'product_family' => 'promotion',
            'sort_order' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $this->postOnCentralHost("/admin/super-admin/standard-categories/{$category->id}/toggle-active", [])
            ->assertRedirect('/admin/super-admin/standard-categories');

        $category->refresh();
        $this->assertFalse($category->is_active);

        $this->postOnCentralHost("/admin/super-admin/standard-categories/{$category->id}/toggle-catalog", [])
            ->assertRedirect('/admin/super-admin/standard-categories');

        $category->refresh();
        $this->assertFalse($category->visible_in_catalog);
    }

    public function test_super_admin_standard_category_store_and_update_normalize_code_and_flags(): void
    {
        $this->actingAs($this->adminUser);

        $storeResponse = $this->postOnCentralHost('/admin/super-admin/standard-categories', [
            'code' => 'promo kalemler plastik',
            'name' => 'Plastik Kalemler',
            'parent_id' => null,
            'product_family' => 'promotion',
            'sort_order' => 15,
            'slug' => '',
            'description' => 'Test açıklaması',
            'is_active' => '1',
            'visible_in_catalog' => '1',
            'requires_mapping' => '1',
        ]);

        $category = StandardCategory::query()->where('code', 'PROMO-KALEMLER-PLASTIK')->firstOrFail();

        $storeResponse->assertRedirect("/admin/super-admin/standard-categories/{$category->id}/edit");
        $this->assertSame('promotion', $category->product_family);
        $this->assertTrue($category->requires_mapping);

        $updateResponse = $this->putOnCentralHost("/admin/super-admin/standard-categories/{$category->id}", [
            'code' => 'PROMO-KALEMLER-PLASTIK',
            'name' => 'Plastik Kalemler Güncel',
            'parent_id' => null,
            'product_family' => 'promotion',
            'sort_order' => 25,
            'slug' => '',
            'description' => 'Güncel açıklama',
            'visible_in_catalog' => '1',
            // is_active gönderilmezse false olur
            // requires_mapping gönderilmezse false olur
        ]);

        $updateResponse->assertRedirect("/admin/super-admin/standard-categories/{$category->id}/edit");

        $category->refresh();
        $this->assertSame('Plastik Kalemler Güncel', $category->name);
        $this->assertSame(25, $category->sort_order);
        $this->assertFalse($category->is_active);
        $this->assertFalse($category->requires_mapping);
    }

    public function test_super_admin_standard_category_bulk_actions_and_order_update_work(): void
    {
        $this->actingAs($this->adminUser);

        $first = StandardCategory::query()->create([
            'code' => 'TEST-BULK-001',
            'name' => 'Toplu Test 1',
            'slug' => 'toplu-test-1',
            'product_family' => 'promotion',
            'sort_order' => 10,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $second = StandardCategory::query()->create([
            'code' => 'TEST-BULK-002',
            'name' => 'Toplu Test 2',
            'slug' => 'toplu-test-2',
            'product_family' => 'promotion',
            'sort_order' => 20,
            'is_active' => false,
            'visible_in_catalog' => true,
        ]);

        $this->postOnCentralHost('/admin/super-admin/standard-categories/bulk-action', [
            'category_ids' => [$first->id, $second->id],
            'bulk_action' => 'deactivate',
        ])->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseHas('standard_categories', ['id' => $first->id, 'is_active' => false]);
        $this->assertDatabaseHas('standard_categories', ['id' => $second->id, 'is_active' => false]);

        $this->postOnCentralHost('/admin/super-admin/standard-categories/bulk-action', [
            'category_ids' => [$first->id, $second->id],
            'bulk_action' => 'activate',
        ])->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseHas('standard_categories', ['id' => $first->id, 'is_active' => true]);
        $this->assertDatabaseHas('standard_categories', ['id' => $second->id, 'is_active' => true]);

        $this->postOnCentralHost('/admin/super-admin/standard-categories/bulk-action', [
            'category_ids' => [$first->id],
            'bulk_action' => 'hide_catalog',
        ])->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseHas('standard_categories', ['id' => $first->id, 'visible_in_catalog' => false]);

        $this->postOnCentralHost('/admin/super-admin/standard-categories/bulk-action', [
            'category_ids' => [$first->id],
            'bulk_action' => 'show_catalog',
        ])->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseHas('standard_categories', ['id' => $first->id, 'visible_in_catalog' => true]);

        $this->postOnCentralHost('/admin/super-admin/standard-categories/update-order', [
            'orders' => [
                $first->id => 99,
                $second->id => 5,
            ],
        ])->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseHas('standard_categories', ['id' => $first->id, 'sort_order' => 99]);
        $this->assertDatabaseHas('standard_categories', ['id' => $second->id, 'sort_order' => 5]);
    }

    public function test_super_admin_standard_category_safe_delete_and_cleanup_unused_work(): void
    {
        $this->actingAs($this->adminUser);

        $parent = StandardCategory::query()->create([
            'code' => 'TEST-SAFE-PARENT',
            'name' => 'Güvenli Sil Parent',
            'slug' => 'guvenli-sil-parent',
            'product_family' => 'promotion',
            'sort_order' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $child = StandardCategory::query()->create([
            'parent_id' => $parent->id,
            'code' => 'TEST-SAFE-CHILD',
            'name' => 'Güvenli Sil Child',
            'slug' => 'guvenli-sil-child',
            'product_family' => 'promotion',
            'sort_order' => 2,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $loose = StandardCategory::query()->create([
            'code' => 'TEST-SAFE-LOOSE',
            'name' => 'Bağlantısız Pasif',
            'slug' => 'baglantisiz-pasif',
            'product_family' => 'promotion',
            'sort_order' => 3,
            'is_active' => false,
            'visible_in_catalog' => false,
        ]);

        $this->postOnCentralHost('/admin/super-admin/standard-categories/bulk-action', [
            'category_ids' => [$parent->id, $loose->id],
            'bulk_action' => 'safe_delete',
        ])->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseHas('standard_categories', ['id' => $parent->id]);
        $this->assertDatabaseMissing('standard_categories', ['id' => $loose->id]);

        $cleanup = StandardCategory::query()->create([
            'code' => 'TEST-CLEANUP-001',
            'name' => 'Temizlenecek Pasif',
            'slug' => 'temizlenecek-pasif',
            'product_family' => 'promotion',
            'sort_order' => 4,
            'is_active' => false,
            'visible_in_catalog' => false,
        ]);

        $this->postOnCentralHost('/admin/super-admin/standard-categories/cleanup-unused', [])
            ->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseMissing('standard_categories', ['id' => $cleanup->id]);
        $this->assertDatabaseHas('standard_categories', ['id' => $child->id]);
    }

    public function test_super_admin_standard_category_bulk_paste_updates_existing_code_without_duplicate(): void
    {
        $this->actingAs($this->adminUser);

        $existing = StandardCategory::query()->create([
            'code' => 'PROMO-KALEMLER',
            'name' => 'Kalemler Eski',
            'slug' => 'kalemler-eski',
            'product_family' => 'promotion',
            'sort_order' => 10,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => true,
        ]);

        $payload = implode("\n", [
            'code;name;parent_code;product_family;sort_order',
            'PROMO;Promosyon Ürünleri;;promotion;1',
            'PROMO-KALEMLER;Kalemler Güncel;PROMO;promotion;50',
        ]);

        $previewResponse = $this->postOnCentralHost('/admin/super-admin/standard-categories/bulk-paste/preview', [
            'bulk_text' => $payload,
        ]);

        $previewResponse->assertOk();

        preg_match('/name="rows_payload" value="([^"]+)"/', $previewResponse->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->postOnCentralHost('/admin/super-admin/standard-categories/bulk-paste/store', [
            'rows_payload' => $matches[1],
        ])->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertSame(
            1,
            StandardCategory::query()->where('code', 'PROMO-KALEMLER')->count()
        );

        $existing->refresh();
        $this->assertSame('Kalemler Güncel', $existing->name);
        $this->assertSame(50, $existing->sort_order);
        $this->assertNotNull(StandardCategory::query()->where('code', 'PROMO')->first());
    }

    public function test_super_admin_standard_category_cannot_be_hard_deleted_when_child_exists(): void
    {
        $this->actingAs($this->adminUser);

        $parent = StandardCategory::query()->create([
            'code' => 'TEST-PARENT-001',
            'name' => 'Test Ana Kategori',
            'slug' => 'test-ana-kategori',
            'product_family' => 'promotion',
            'sort_order' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $child = StandardCategory::query()->create([
            'parent_id' => $parent->id,
            'code' => 'TEST-CHILD-001',
            'name' => 'Test Alt Kategori',
            'slug' => 'test-alt-kategori',
            'product_family' => 'promotion',
            'sort_order' => 2,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $parent->updatePath();
        $child->updatePath();

        $this->deleteOnCentralHost("/admin/super-admin/standard-categories/{$parent->id}")
            ->assertRedirect('/admin/super-admin/standard-categories');

        $this->assertDatabaseHas('standard_categories', [
            'id' => $parent->id,
            'code' => 'TEST-PARENT-001',
        ]);
    }

    public function test_tenant_cannot_open_global_source_create_or_edit_pages(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/product-data-hub/sources/create')
            ->assertForbidden();

        $this->getOnCentralHost('/admin/product-data-hub/sources/1/edit')
            ->assertForbidden();

        $this->getOnCentralHost('/admin/product-data-hub/sources/1/preview')
            ->assertForbidden();

        $this->getOnCentralHost('/admin/product-data-hub/field-mappings')
            ->assertForbidden();

        $this->getOnCentralHost('/admin/product-data-hub/field-mappings/source/1')
            ->assertForbidden();

        $this->getOnCentralHost('/admin/product-data-hub/category-mappings')
            ->assertForbidden();

        $this->getOnCentralHost('/admin/product-data-hub/raw-products')
            ->assertForbidden();

        $this->getOnCentralHost('/admin/product-data-hub/standard-products')
            ->assertForbidden();
    }

    public function test_super_admin_field_mapping_post_updates_existing_source_field_without_duplicate_insert(): void
    {
        $this->actingAs($this->adminUser);

        $source = SupplierSource::query()->findOrFail(1);
        $sourceField = 'test_unique_mapping_field';

        $firstResponse = $this->postOnCentralHost(
            "/admin/super-admin/product-data-hub/field-mappings/source/{$source->id}",
            [
                'mappings' => [
                    $sourceField => [
                        'standard_field_key' => 'supplier_product_code',
                        'mapping_status' => 'mapped',
                        'transform_rule' => 'trim',
                        'note' => 'İlk kayıt',
                    ],
                ],
            ]
        );

        $firstResponse->assertRedirect("/admin/super-admin/product-data-hub/field-mappings/source/{$source->id}");

        $this->assertSame(
            1,
            SupplierFieldMapping::query()
                ->where('supplier_source_id', $source->id)
                ->where('source_field', $sourceField)
                ->count()
        );

        $secondResponse = $this->postOnCentralHost(
            "/admin/super-admin/product-data-hub/field-mappings/source/{$source->id}",
            [
                'mappings' => [
                    $sourceField => [
                        'standard_field_key' => 'product_name',
                        'mapping_status' => 'mapped',
                        'transform_rule' => 'upper',
                        'note' => 'Güncel kayıt',
                    ],
                ],
            ]
        );

        $secondResponse->assertRedirect("/admin/super-admin/product-data-hub/field-mappings/source/{$source->id}");

        $this->assertSame(
            1,
            SupplierFieldMapping::query()
                ->where('supplier_source_id', $source->id)
                ->where('source_field', $sourceField)
                ->count()
        );

        $mapping = SupplierFieldMapping::query()
            ->where('supplier_source_id', $source->id)
            ->where('source_field', $sourceField)
            ->firstOrFail();

        $this->assertSame('product_name', $mapping->target_field);
        $this->assertSame('upper', $mapping->transform_rule);
        $this->assertSame('Güncel kayıt', $mapping->note);
    }

    public function test_super_admin_field_mapping_can_store_pending_row_with_null_target_field(): void
    {
        $this->actingAs($this->adminUser);

        $source = SupplierSource::query()->findOrFail(1);

        $response = $this->postOnCentralHost(
            "/admin/super-admin/product-data-hub/field-mappings/source/{$source->id}",
            [
                'mappings' => [
                    'kid' => [
                        'standard_field_key' => '',
                        'mapping_status' => 'pending',
                        'transform_rule' => null,
                        'note' => 'Boş hedef alan testi',
                    ],
                    'isim' => [
                        'standard_field_key' => 'product_name',
                        'mapping_status' => 'mapped',
                        'transform_rule' => null,
                        'note' => 'Ürün adı eşlemesi',
                    ],
                    'fiyat' => [
                        'standard_field_key' => 'purchase_price',
                        'mapping_status' => 'mapped',
                        'transform_rule' => 'price',
                        'note' => 'Fiyat eşlemesi',
                    ],
                    'kod' => [
                        'standard_field_key' => 'supplier_product_code',
                        'mapping_status' => 'mapped',
                        'transform_rule' => 'trim',
                        'note' => 'Kod eşlemesi',
                    ],
                ],
            ]
        );

        $response->assertRedirect("/admin/super-admin/product-data-hub/field-mappings/source/{$source->id}");

        $nullTargetMapping = SupplierFieldMapping::query()
            ->where('supplier_source_id', $source->id)
            ->where('source_field', 'kid')
            ->firstOrFail();

        $namedMapping = SupplierFieldMapping::query()
            ->where('supplier_source_id', $source->id)
            ->where('source_field', 'isim')
            ->firstOrFail();

        $this->assertNull($nullTargetMapping->target_field);
        $this->assertSame('pending', $nullTargetMapping->mapping_status);
        $this->assertSame('product_name', $namedMapping->target_field);
    }

    public function test_product_field_dictionary_accepts_display_product_name_for_required_name_mapping(): void
    {
        $service = app(ProductFieldDictionaryService::class);

        $errors = $service->validateRequiredMappings([
            'baslik' => ['standard_field_key' => 'display_product_name'],
            'kod' => ['standard_field_key' => 'supplier_product_code'],
            'fiyat' => ['standard_field_key' => 'purchase_price'],
        ]);

        $this->assertNotContains('product_name veya base_product_name eşlemesi eksik.', $errors);
        $this->assertNotContains('product_name veya base_product_name veya display_product_name eşlemesi eksik.', $errors);
    }

    public function test_super_admin_yeni_nesil_field_mapping_page_shows_extended_fields_and_turkish_labels(): void
    {
        $this->actingAs($this->adminUser);

        $supplier = Supplier::query()->where('code', 'YENI-NESIL')->firstOrFail();
        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Yeni Nesil Test Kaynağı',
            'status' => 'active',
        ]);

        $response = $this->getOnCentralHost("/admin/super-admin/product-data-hub/field-mappings/source/{$source->id}");

        $response->assertOk();
        $response->assertSeeText('isim');
        $response->assertSeeText('baslik');
        $response->assertSeeText('aciklama');
        $response->assertSeeText('Ürün Adı (product_name)');
        $response->assertSeeText('Görünen Ürün Adı (display_product_name)');
        $response->assertSeeText('Özel Uyarı (warning_flag)');
        $response->assertSeeText('Ürün Kodu (supplier_product_code)');
        $response->assertSeeText('Alış Fiyatı (purchase_price)');
        $response->assertSeeText('Bekliyor');
        $response->assertSeeText('Önerildi');
        $response->assertSeeText('Eşlendi');
        $response->assertSeeText('Metin');
        $response->assertSeeText('Önerilenleri Doldur');
        $response->assertDontSeeText('product_name veya base_product_name eşlemesi eksik.');
        $response->assertDontSeeText('product_name veya base_product_name veya display_product_name eşlemesi eksik.');
    }

    public function test_promotion_quote_can_be_created_with_basic_payload(): void
    {
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $response = $this->actingAs($this->tenantUser)
            ->postOnCentralHost('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'valid_until' => now()->addWeek()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'vat_rate' => 20,
                'delivery_type' => 'Kargo',
                'notes' => 'Smoke test quote',
                'items' => [
                    [
                        'product_name' => 'Smoke Test Ürünü',
                        'product_code' => 'SMK-001',
                        'quantity' => '10',
                        'unit' => 'Adet',
                        'list_price' => '120',
                        'discount_rate' => '10',
                        'unit_price' => '108',
                        'has_print' => '0',
                    ],
                ],
            ]);

        $order = Order::query()
            ->where('document_type', 'quote')
            ->where('customer_company_id', $customer->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($order);

        $response->assertRedirectToRoute('admin.promotion-quotes.show', $order);

        $this->assertMatchesRegularExpression('/^TK-\d{4}-\d{4}$/', $order->document_number);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'document_type' => 'quote',
            'order_family' => 'promotion',
            'currency' => 'TL',
        ]);
    }

    public function test_preview_can_be_staged_to_raw_products(): void
    {
        $source = SupplierSource::query()->where('source_name', 'Etkin Promosyon XML')->firstOrFail();
        $this->prepareLiveSourceFixture($source);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables([
                'HTTP_HOST' => self::CENTRAL_HOST,
            ])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                'confirm_stage' => '1',
            ]);

        $response->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");

        $this->assertDatabaseCount('supplier_products_raw', 1);
        $this->assertGreaterThanOrEqual(1, SupplierProductRaw::query()->count());
        $this->assertDatabaseHas('supplier_products_raw', [
            'supplier_source_id' => $source->id,
            'sync_status' => 'staged',
        ]);
    }

    public function test_raw_product_can_be_built_into_standard_product(): void
    {
        $source = SupplierSource::query()->where('source_name', 'Akdeniz Promosyon API')->firstOrFail();
        $this->prepareLiveSourceFixture($source);

        $this->actingAs($this->adminUser)
            ->withServerVariables([
                'HTTP_HOST' => self::CENTRAL_HOST,
            ])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                'confirm_stage' => '1',
            ])
            ->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");

        $rawProduct = SupplierProductRaw::query()->where('supplier_source_id', $source->id)->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables([
                'HTTP_HOST' => self::CENTRAL_HOST,
            ])
            ->post("/admin/super-admin/product-data-hub/raw-products/{$rawProduct->id}/build-standard");

        $response->assertRedirect('/admin/super-admin/product-data-hub/raw-products');

        $standardProduct = StandardProduct::query()->first();
        $this->assertNotNull($standardProduct);
        $this->assertNotEmpty($standardProduct->standard_product_code);
        $this->assertGreaterThanOrEqual(1, StandardProductVariant::query()->count());

        $this->assertDatabaseHas('supplier_products_raw', [
            'id' => $rawProduct->id,
            'standard_product_id' => $standardProduct->id,
            'sync_status' => 'processed',
        ]);
    }

    public function test_standard_products_can_be_projected_to_tenant_catalog(): void
    {
        $this->projectCatalogFromSource('Etkin Promosyon XML');

        $response = $this->actingAs($this->tenantUser)
            ->post($this->tenantUrl('/admin/catalog/project'));

        $response->assertRedirect('/admin/catalog');

        $this->assertGreaterThanOrEqual(1, TenantCatalogProduct::query()->count());

        $catalogProduct = TenantCatalogProduct::query()->first();
        $this->assertNotNull($catalogProduct);
        $this->assertNotNull($catalogProduct->standard_product_id);
        $this->assertNotEmpty($catalogProduct->product_name);
    }

    public function test_product_media_urls_are_carried_to_standard_and_tenant_catalog_layers(): void
    {
        $source = SupplierSource::query()->where('source_name', 'Akdeniz Promosyon API')->firstOrFail();
        $this->prepareLiveSourceFixture($source);

        $this->actingAs($this->adminUser)
            ->withServerVariables([
                'HTTP_HOST' => self::CENTRAL_HOST,
            ])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                'confirm_stage' => '1',
            ])
            ->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");

        $rawProduct = SupplierProductRaw::query()->where('supplier_source_id', $source->id)->firstOrFail();
        $rawProduct->forceFill([
            'normalized_payload' => array_merge($rawProduct->normalized_payload ?? [], [
                'image_url' => 'https://example.test/ak-parent.jpg',
                'parent_image_url' => 'https://example.test/ak-parent.jpg',
                'product_url' => 'https://example.test/product-page',
                'detail_url' => 'https://example.test/product-detail',
                'gallery_images' => [
                    'https://example.test/ak-gallery-1.jpg',
                    'https://example.test/ak-gallery-2.jpg',
                    'https://example.test/ak-gallery-1.jpg',
                ],
            ]),
            'image_url' => 'https://example.test/ak-parent.jpg',
            'product_url' => 'https://example.test/product-page',
            'detail_url' => 'https://example.test/product-detail',
        ])->save();

        $rawVariant = $rawProduct->variants()->firstOrFail();
        $rawVariant->forceFill([
            'variant_image_url' => 'https://example.test/ak-variant.jpg',
            'image_fallback_used' => false,
            'normalized_payload' => array_merge($rawVariant->normalized_payload ?? [], [
                'variant_image_url' => 'https://example.test/ak-variant.jpg',
                'parent_image_url' => 'https://example.test/ak-parent.jpg',
                'image_fallback_used' => false,
            ]),
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables([
                'HTTP_HOST' => self::CENTRAL_HOST,
            ])
            ->post("/admin/super-admin/product-data-hub/raw-products/{$rawProduct->id}/build-standard")
            ->assertRedirect('/admin/super-admin/product-data-hub/raw-products');

        $standardProduct = StandardProduct::query()->latest('id')->firstOrFail();
        $standardVariant = StandardProductVariant::query()->latest('id')->firstOrFail();

        $this->assertGreaterThanOrEqual(3, StandardProductImage::query()->count());
        $this->assertDatabaseHas('standard_product_images', [
            'standard_product_id' => $standardProduct->id,
            'standard_product_variant_id' => null,
            'image_url' => 'https://example.test/ak-parent.jpg',
            'image_type' => 'main',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('standard_product_images', [
            'standard_product_id' => $standardProduct->id,
            'standard_product_variant_id' => null,
            'image_url' => 'https://example.test/ak-gallery-1.jpg',
            'image_type' => 'gallery',
        ]);
        $this->assertSame('https://example.test/product-page', $standardProduct->product_url);
        $this->assertSame('https://example.test/product-detail', $standardProduct->detail_url);
        $this->assertDatabaseHas('standard_product_images', [
            'standard_product_id' => $standardProduct->id,
            'standard_product_variant_id' => $standardVariant->id,
            'image_url' => 'https://example.test/ak-variant.jpg',
            'image_type' => 'variant',
        ]);

        $this->assertSame(
            1,
            StandardProductImage::query()
                ->where('standard_product_id', $standardProduct->id)
                ->where('image_url', 'https://example.test/ak-gallery-1.jpg')
                ->where('image_type', 'gallery')
                ->count()
        );

        $this->actingAs($this->tenantUser)
            ->post($this->tenantUrl('/admin/catalog/project'))
            ->assertRedirect('/admin/catalog');

        $catalogProduct = TenantCatalogProduct::query()->where('standard_product_id', $standardProduct->id)->firstOrFail();

        $this->assertGreaterThanOrEqual(3, TenantCatalogProductImage::query()->count());
        $this->assertDatabaseHas('tenant_catalog_product_images', [
            'tenant_account_id' => $catalogProduct->tenant_account_id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'image_url' => 'https://example.test/ak-parent.jpg',
            'image_type' => 'main',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('tenant_catalog_product_images', [
            'tenant_account_id' => $catalogProduct->tenant_account_id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'image_url' => 'https://example.test/ak-gallery-1.jpg',
            'image_type' => 'gallery',
        ]);
        $this->assertSame('https://example.test/product-page', $catalogProduct->product_url);
        $this->assertSame('https://example.test/product-detail', $catalogProduct->detail_url);
        $this->assertSame(
            2,
            TenantCatalogProductImage::query()
                ->where('tenant_catalog_product_id', $catalogProduct->id)
                ->where('image_type', 'gallery')
                ->count()
        );
    }

    public function test_catalog_search_returns_tenant_scoped_json_results(): void
    {
        $catalogProduct = $this->projectCatalogFromSource('Etkin Promosyon XML');
        $otherTenant = TenantAccount::query()->create([
            'name' => 'İkinci Tenant',
            'legal_name' => 'İkinci Tenant A.Ş.',
            'slug' => 'ikinci-tenant',
            'panel_subdomain' => 'ikinci',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'standard_product_id' => $catalogProduct->standard_product_id,
            'tenant_sku' => 'OTHER-001',
            'name' => 'Yabancı Ürün',
            'product_code' => 'OTHER-001',
            'product_name' => 'Yabancı Ürün',
            'currency' => 'TL',
            'sale_price' => 10,
            'display_price' => 10,
            'stock_quantity' => 5,
            'is_active' => true,
            'visible_in_catalog' => true,
        ]);

        $response = $this->actingAs($this->tenantUser)
            ->getJson($this->tenantUrl('/admin/catalog/search?q=Kalem'));

        $response->assertOk();
        $response->assertJsonFragment([
            'tenant_catalog_product_id' => $catalogProduct->id,
        ]);
        $response->assertJsonMissing([
            'group_product_code' => $catalogProduct->display_code,
        ]);
        $this->assertSame(
            'ET-0506-L',
            collect($response->json())->pluck('product_code')->first()
        );
        $response->assertJsonMissing([
            'id' => $foreignProduct->id,
            'product_code' => 'OTHER-001',
        ]);
    }

    public function test_promotion_quote_can_store_catalog_item_snapshots(): void
    {
        $catalogProduct = $this->projectCatalogFromSource('Etkin Promosyon XML');
        $customer = Company::where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->postOnCentralHost('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => now()->format('Y-m-d'),
                'valid_until' => now()->addWeek()->format('Y-m-d'),
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'vat_rate' => 20,
                'delivery_type' => 'Kargo',
                'notes' => 'Katalog snapshot testi',
                'items' => [
                    [
                        'product_name' => $catalogProduct->display_name,
                        'product_code' => $catalogProduct->display_code,
                        'quantity' => '5',
                        'unit' => 'Adet',
                        'list_price' => (string) ($catalogProduct->display_price ?? 0),
                        'discount_rate' => '0',
                        'unit_price' => (string) ($catalogProduct->display_price ?? 0),
                        'has_print' => '0',
                        'tenant_catalog_product_id' => $catalogProduct->id,
                        'standard_product_id' => $catalogProduct->standard_product_id,
                        'supplier_source_id' => data_get($catalogProduct->source_summary, '0.supplier_source_id'),
                        'catalog_source' => 'tenant_catalog',
                        'product_snapshot' => json_encode([
                            'tenant_catalog_product_id' => $catalogProduct->id,
                            'standard_product_id' => $catalogProduct->standard_product_id,
                            'product_code' => $catalogProduct->display_code,
                            'product_name' => $catalogProduct->display_name,
                        ], JSON_UNESCAPED_UNICODE),
                        'price_snapshot' => json_encode([
                            'display_price' => (float) ($catalogProduct->display_price ?? 0),
                            'currency' => $catalogProduct->currency ?? 'TL',
                        ], JSON_UNESCAPED_UNICODE),
                        'stock_snapshot' => json_encode([
                            'visible_stock_quantity' => (float) ($catalogProduct->total_stock_quantity ?? 0),
                            'local_stock_quantity' => (float) ($catalogProduct->local_stock_quantity ?? 0),
                            'supplier_stock_quantity' => (float) ($catalogProduct->supplier_stock_quantity ?? 0),
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ]);

        $order = Order::query()->latest('id')->firstOrFail();
        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

        $response->assertRedirectToRoute('admin.promotion-quotes.show', $order);
        $this->assertSame('tenant_catalog', $item->product_source);
        $this->assertSame($catalogProduct->id, $item->tenant_catalog_product_id);
        $this->assertNotNull($item->product_snapshot);
        $this->assertNotNull($item->price_snapshot);
        $this->assertNotNull($item->stock_snapshot);
    }

    private function projectCatalogFromSource(string $sourceName): TenantCatalogProduct
    {
        $source = SupplierSource::query()->where('source_name', $sourceName)->firstOrFail();
        $this->prepareLiveSourceFixture($source);

        $this->actingAs($this->adminUser)
            ->withServerVariables([
                'HTTP_HOST' => self::CENTRAL_HOST,
            ])
            ->post("/admin/super-admin/product-data-hub/sources/{$source->id}/stage-preview", [
                'confirm_stage' => '1',
            ])
            ->assertRedirect("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");

        $rawProduct = SupplierProductRaw::query()->where('supplier_source_id', $source->id)->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables([
                'HTTP_HOST' => self::CENTRAL_HOST,
            ])
            ->post("/admin/super-admin/product-data-hub/raw-products/{$rawProduct->id}/build-standard")
            ->assertRedirect('/admin/super-admin/product-data-hub/raw-products');

        $this->actingAs($this->tenantUser)
            ->post($this->tenantUrl('/admin/catalog/project'))
            ->assertRedirect('/admin/catalog');

        return TenantCatalogProduct::query()->latest('id')->firstOrFail();
    }

    private function prepareLiveSourceFixture(SupplierSource $source): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        [$content, $nodePath] = match ($source->source_name) {
            'Akdeniz Promosyon API' => [$this->akdenizFixtureXml(), 'RECORD'],
            default => [$this->etkinFixtureXml(), 'urun'],
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
        <urun_fiyat>9.20</urun_fiyat>
        <urun_kirmizi>0</urun_kirmizi>
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
        <urun_id>9001</urun_id>
        <urunkodu>509-BK Siyah</urunkodu>
        <urunattr_id>A1</urunattr_id>
        <urunattrgr>509-BK</urunattrgr>
        <urunattradi>Siyah</urunattradi>
        <urunadi>Kurumsal Mug</urunadi>
        <pure_prodname>Kurumsal Mug</pure_prodname>
        <listefiyati>110,00</listefiyati>
        <iskonto>5</iskonto>
        <netfiyat>85,00</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <stokmiktar>320</stokmiktar>
        <stokresim></stokresim>
        <urunresim>https://example.test/ak-parent.jpg</urunresim>
        <urunresim1>https://example.test/ak-image.jpg</urunresim1>
        <kategori>Kurumsal Setler</kategori>
        <discat_name>Kurumsal Setler</discat_name>
        <urunaciklamasi>Kurumsal kullanım için seramik mug.</urunaciklamasi>
        <urunbaskifiyatlari>&lt;p&gt;Tek renk baskı dahil değildir.&lt;/p&gt;</urunbaskifiyatlari>
        <stoktag>mug,seramik</stoktag>
    </RECORD>
</ROOT>
XML;
    }

    private function getOnCentralHost(string $url)
    {
        return $this->withServerVariables([
            'HTTP_HOST' => self::CENTRAL_HOST,
        ])->get($url);
    }

    private function postOnCentralHost(string $url, array $payload)
    {
        return $this->withServerVariables([
            'HTTP_HOST' => self::CENTRAL_HOST,
        ])->post($url, $payload);
    }

    private function putOnCentralHost(string $url, array $payload)
    {
        return $this->withServerVariables([
            'HTTP_HOST' => self::CENTRAL_HOST,
        ])->put($url, $payload);
    }

    private function deleteOnCentralHost(string $url)
    {
        return $this->withServerVariables([
            'HTTP_HOST' => self::CENTRAL_HOST,
        ])->delete($url);
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
                'name' => $tenant->name . ' Catalog Owner',
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
}
