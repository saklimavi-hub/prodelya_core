<?php

namespace Tests\Feature;

use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\DeltaChangeDetectorService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProductDataHubDeltaChangeDetectorTest extends TestCase
{
    private DeltaChangeDetectorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DeltaChangeDetectorService::class);
    }

    public function test_detects_price_stock_and_combined_changes(): void
    {
        $source = new SupplierSource(['config' => ['profile_key' => 'ETKIN']]);
        $existing = collect([
            new SupplierProductRaw([
                'supplier_product_code' => 'P-1',
                'product_name' => 'Kalem',
                'supplier_category_name' => 'Kalemler',
                'image_url' => 'https://example.test/a.jpg',
                'normalized_payload' => ['list_price' => 10, 'currency' => 'TL'],
                'stock_quantity' => 5,
            ]),
            new SupplierProductRaw([
                'supplier_product_code' => 'P-2',
                'product_name' => 'Defter',
                'supplier_category_name' => 'Ofis',
                'image_url' => 'https://example.test/b.jpg',
                'normalized_payload' => ['list_price' => 20, 'currency' => 'TL'],
                'stock_quantity' => 3,
            ]),
            new SupplierProductRaw([
                'supplier_product_code' => 'P-3',
                'product_name' => 'Kupa',
                'supplier_category_name' => 'Mutfak',
                'image_url' => 'https://example.test/c.jpg',
                'normalized_payload' => ['list_price' => 30, 'currency' => 'TL'],
                'stock_quantity' => 1,
            ]),
        ]);

        $result = $this->service->detectForSource($source, [
            'products' => [
                ['supplier_product_code' => 'P-1', 'product_name' => 'Kalem', 'supplier_category_name' => 'Kalemler', 'image_url' => 'https://example.test/a.jpg', 'list_price' => 15, 'currency' => 'TL', 'stock_quantity' => 5],
                ['supplier_product_code' => 'P-2', 'product_name' => 'Defter', 'supplier_category_name' => 'Ofis', 'image_url' => 'https://example.test/b.jpg', 'list_price' => 20, 'currency' => 'TL', 'stock_quantity' => 9],
                ['supplier_product_code' => 'P-3', 'product_name' => 'Kupa', 'supplier_category_name' => 'Mutfak', 'image_url' => 'https://example.test/c.jpg', 'list_price' => 40, 'currency' => 'TL', 'stock_quantity' => 7],
            ],
            'variants' => [],
        ], $existing, collect());

        $this->assertSame(1, $result['counts']['price_changed']);
        $this->assertSame(1, $result['counts']['stock_changed']);
        $this->assertSame(1, $result['counts']['price_and_stock_changed']);
    }

    public function test_detects_new_missing_and_variant_structure_changes(): void
    {
        $source = new SupplierSource(['config' => ['profile_key' => 'ETKIN']]);
        $existingProducts = collect([
            new SupplierProductRaw([
                'id' => 10,
                'supplier_product_code' => 'GRUP-1',
                'product_name' => 'Matara',
                'supplier_group_code' => 'GRUP-1',
                'normalized_payload' => ['list_price' => 10],
            ]),
            new SupplierProductRaw([
                'id' => 20,
                'supplier_product_code' => 'OLD-1',
                'product_name' => 'Eski',
                'normalized_payload' => ['list_price' => 8],
            ]),
        ]);
        $existingVariants = collect([
            new SupplierProductVariantRaw([
                'supplier_product_raw_id' => 10,
                'supplier_group_code' => 'GRUP-1',
                'variant_stock_code' => 'VAR-A',
                'variant_name' => 'A',
                'normalized_payload' => ['list_price' => 10],
            ]),
            new SupplierProductVariantRaw([
                'supplier_product_raw_id' => 10,
                'supplier_group_code' => 'GRUP-1',
                'variant_stock_code' => 'VAR-Z',
                'variant_name' => 'Z',
                'normalized_payload' => ['list_price' => 10],
            ]),
        ]);

        $result = $this->service->detectForSource($source, [
            'products' => [
                ['supplier_product_code' => 'GRUP-1', 'supplier_group_code' => 'GRUP-1', 'product_name' => 'Matara', 'list_price' => 10],
                ['supplier_product_code' => 'NEW-1', 'product_name' => 'Yeni Ürün', 'list_price' => 12],
            ],
            'variants' => [
                ['supplier_group_code' => 'GRUP-1', 'parent_supplier_product_id' => 'GRUP-1', 'variant_stock_code' => 'VAR-A', 'variant_name' => 'A', 'list_price' => 10],
                ['supplier_group_code' => 'GRUP-1', 'parent_supplier_product_id' => 'GRUP-1', 'variant_stock_code' => 'VAR-B', 'variant_name' => 'B', 'list_price' => 10],
            ],
        ], $existingProducts, $existingVariants);

        $this->assertSame(1, $result['counts']['new_product']);
        $this->assertSame(1, $result['counts']['new_variant']);
        $this->assertSame(1, $result['counts']['missing_product']);
        $this->assertSame(1, $result['counts']['missing_variant']);
        $this->assertSame(1, $result['counts']['variant_structure_changed']);
    }

    public function test_detects_category_image_content_and_blocked_identity_missing(): void
    {
        $source = new SupplierSource(['config' => ['profile_key' => 'ETKIN']]);
        $existing = new Collection([
            new SupplierProductRaw([
                'supplier_product_code' => 'PRD-1',
                'product_name' => 'Termos',
                'supplier_category_name' => 'Mutfak',
                'image_url' => 'https://example.test/a.jpg',
                'description' => 'İlk açıklama',
                'normalized_payload' => ['list_price' => 10, 'currency' => 'USD'],
                'stock_quantity' => 2,
            ]),
        ]);

        $result = $this->service->detectForSource($source, [
            'products' => [
                ['supplier_product_code' => 'PRD-1', 'product_name' => 'Termos Pro', 'supplier_category_name' => 'Outdoor', 'image_url' => 'https://example.test/b.jpg', 'description' => 'Yeni açıklama', 'list_price' => 10, 'currency' => 'USD', 'stock_quantity' => 2],
                ['product_name' => 'Kodsuz Ürün', 'list_price' => 5, 'stock_quantity' => 1],
            ],
            'variants' => [],
        ], $existing, collect());

        $this->assertSame(1, $result['counts']['category_changed']);
        $this->assertSame(1, $result['counts']['image_changed']);
        $this->assertSame(1, $result['counts']['content_changed']);
        $this->assertSame(1, $result['counts']['blocked_identity_missing']);
        $this->assertFalse($result['apply_candidate']);
    }

    public function test_detects_suspicious_price_jump(): void
    {
        $source = new SupplierSource(['config' => ['profile_key' => 'AKDENIZ']]);
        $existing = new Collection([
            new SupplierProductRaw([
                'supplier_product_code' => 'PRC-1',
                'product_name' => 'Powerbank',
                'normalized_payload' => ['list_price' => 100, 'currency' => 'TL'],
                'stock_quantity' => 2,
            ]),
        ]);

        $result = $this->service->detectForSource($source, [
            'products' => [
                ['supplier_product_code' => 'PRC-1', 'product_name' => 'Powerbank', 'list_price' => 320, 'currency' => 'TL', 'stock_quantity' => 2],
            ],
            'variants' => [],
        ], $existing, collect());

        $this->assertSame(1, $result['counts']['suspicious_price_jump']);
        $this->assertTrue($result['flags']['suspicious_price_jump']);
    }
}
