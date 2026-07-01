<?php

namespace Tests\Feature;

use App\Services\ProductDataHub\ProductAttributeValueNormalizer;
use Tests\TestCase;

class ProductAttributeValueNormalizerTest extends TestCase
{
    public function test_it_normalizes_common_turkish_attribute_values(): void
    {
        $service = app(ProductAttributeValueNormalizer::class);

        $this->assertSame('Siyah', $service->normalize('renk', 'siyah'));
        $this->assertSame('Beyaz', $service->normalize('renk', 'beyaz'));
        $this->assertSame('Kırmızı', $service->normalize('color', 'kirmizi'));
        $this->assertSame('Açık Kırmızı', $service->normalize('variant_color', 'acik_kirmizi'));
        $this->assertSame('Sarı', $service->normalize('renk', 'Sari'));
        $this->assertSame('Siyah', $service->normalize('renk', 'SIYAH'));
        $this->assertSame('Gümüş', $service->normalize('color', 'gumus'));
        $this->assertSame('Koyu Lacivert', $service->normalize('color', 'koyu_lacivert'));
        $this->assertSame('Metalik Gümüş', $service->normalize('color', 'metalik_gumus'));
    }

    public function test_it_does_not_normalize_codes_or_unrelated_fields(): void
    {
        $service = app(ProductAttributeValueNormalizer::class);

        $this->assertSame('PZ-100-KRM', $service->normalize('variant_stock_code', 'PZ-100-KRM'));
        $this->assertSame('ABC-123', $service->normalize('supplier_product_code', 'ABC-123'));
        $this->assertSame('PZ-K500SY', $service->normalizeDisplayValue('PZ-K500SY', 'variant_stock_code'));
        $this->assertSame('PZ-KL25GU', $service->normalizeDisplayValue('PZ-KL25GU', 'supplier_product_code'));
    }

    public function test_it_preserves_turkish_characters_and_cleans_delimiters(): void
    {
        $service = app(ProductAttributeValueNormalizer::class);

        $this->assertSame('Gümüş', $service->normalize('color', 'gumus'));
        $this->assertSame('Açık Kırmızı', $service->normalize('color', 'açık-kırmızı'));
        $this->assertSame('Koyu Lacivert', $service->normalize('color', 'koyu_lacivert'));
    }
}
