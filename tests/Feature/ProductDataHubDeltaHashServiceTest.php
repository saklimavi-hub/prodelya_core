<?php

namespace Tests\Feature;

use App\Services\ProductDataHub\DeltaSyncHashService;
use Tests\TestCase;

class ProductDataHubDeltaHashServiceTest extends TestCase
{
    private DeltaSyncHashService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DeltaSyncHashService::class);
    }

    public function test_same_price_data_produces_same_price_hash_and_turkish_characters_are_stable(): void
    {
        $first = $this->service->buildProductHashes([
            'product_name' => 'Çelik Şişe',
            'list_price' => '10,50',
            'currency' => 'USD',
            'vat_rate' => '20',
            'pricing_policy_type' => 'list_price',
        ]);
        $second = $this->service->buildProductHashes([
            'product_name' => 'Çelik Şişe',
            'list_price' => '10.50',
            'currency' => 'USD',
            'vat_rate' => 20,
            'pricing_policy_type' => 'list_price',
        ]);

        $this->assertSame($first['price_hash'], $second['price_hash']);
        $this->assertSame($first['content_hash'], $second['content_hash']);
    }

    public function test_price_stock_image_category_and_content_hashes_change_when_related_payload_changes(): void
    {
        $base = $this->service->buildProductHashes([
            'product_name' => 'Matara',
            'description' => 'Açıklama',
            'supplier_category_name' => 'Termos',
            'image_url' => 'https://example.test/a.jpg',
            'gallery_images' => ['https://example.test/b.jpg'],
            'list_price' => 10,
            'currency' => 'USD',
            'vat_rate' => 20,
            'pricing_policy_type' => 'list_price',
            'stock_quantity' => 5,
        ]);

        $priceChanged = $this->service->buildProductHashes([
            'product_name' => 'Matara',
            'description' => 'Açıklama',
            'supplier_category_name' => 'Termos',
            'image_url' => 'https://example.test/a.jpg',
            'gallery_images' => ['https://example.test/b.jpg'],
            'list_price' => 11,
            'currency' => 'USD',
            'vat_rate' => 20,
            'pricing_policy_type' => 'list_price',
            'stock_quantity' => 5,
        ]);
        $stockChanged = $this->service->buildProductHashes([
            'product_name' => 'Matara',
            'description' => 'Açıklama',
            'supplier_category_name' => 'Termos',
            'image_url' => 'https://example.test/a.jpg',
            'gallery_images' => ['https://example.test/b.jpg'],
            'list_price' => 10,
            'currency' => 'USD',
            'vat_rate' => 20,
            'pricing_policy_type' => 'list_price',
            'stock_quantity' => 7,
        ]);
        $imageChanged = $this->service->buildProductHashes([
            'product_name' => 'Matara',
            'description' => 'Açıklama',
            'supplier_category_name' => 'Termos',
            'image_url' => 'https://example.test/c.jpg',
            'gallery_images' => ['https://example.test/b.jpg'],
            'list_price' => 10,
            'currency' => 'USD',
            'vat_rate' => 20,
            'pricing_policy_type' => 'list_price',
            'stock_quantity' => 5,
        ]);
        $categoryChanged = $this->service->buildProductHashes([
            'product_name' => 'Matara',
            'description' => 'Açıklama',
            'supplier_category_name' => 'Outdoor',
            'image_url' => 'https://example.test/a.jpg',
            'gallery_images' => ['https://example.test/b.jpg'],
            'list_price' => 10,
            'currency' => 'USD',
            'vat_rate' => 20,
            'pricing_policy_type' => 'list_price',
            'stock_quantity' => 5,
        ]);
        $contentChanged = $this->service->buildProductHashes([
            'product_name' => 'Matara Pro',
            'description' => 'Yeni açıklama',
            'supplier_category_name' => 'Termos',
            'image_url' => 'https://example.test/a.jpg',
            'gallery_images' => ['https://example.test/b.jpg'],
            'list_price' => 10,
            'currency' => 'USD',
            'vat_rate' => 20,
            'pricing_policy_type' => 'list_price',
            'stock_quantity' => 5,
        ]);

        $this->assertNotSame($base['price_hash'], $priceChanged['price_hash']);
        $this->assertNotSame($base['stock_hash'], $stockChanged['stock_hash']);
        $this->assertNotSame($base['image_hash'], $imageChanged['image_hash']);
        $this->assertNotSame($base['category_hash'], $categoryChanged['category_hash']);
        $this->assertNotSame($base['content_hash'], $contentChanged['content_hash']);
    }

    public function test_variant_structure_hash_is_order_independent(): void
    {
        $first = $this->service->buildVariantStructureHash([
            ['variant_stock_code' => 'A', 'variant_color' => 'Kırmızı', 'variant_size' => 'S'],
            ['variant_stock_code' => 'B', 'variant_color' => 'Mavi', 'variant_size' => 'M'],
        ]);
        $second = $this->service->buildVariantStructureHash([
            ['variant_stock_code' => 'B', 'variant_color' => 'Mavi', 'variant_size' => 'M'],
            ['variant_stock_code' => 'A', 'variant_color' => 'Kırmızı', 'variant_size' => 'S'],
        ]);

        $this->assertSame($first, $second);
    }
}
