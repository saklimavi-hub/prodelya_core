<?php

namespace Tests\Feature;

use App\Services\ProductDataHub\SupplierWarningLabelService;
use App\Services\TenantCatalog\TenantCatalogListRowQueryService;
use ReflectionMethod;
use Tests\TestCase;

class ProductDataHubWarningLabelPolicyTest extends TestCase
{
    public function test_etkin_supplier_warning_is_rendered_as_kirmizi_urun(): void
    {
        $service = app(SupplierWarningLabelService::class);

        $badges = $service->supplierSpecificBadges('Etkin Promosyon', [
            'supplier_warning_flag' => true,
            'net_price_warning' => false,
            'pricing_policy_type' => 'list_price_only',
        ]);

        $this->assertSame(['Kırmızı Ürün'], $badges);
    }

    public function test_yeni_nesil_supplier_warning_is_rendered_as_turuncu_urun(): void
    {
        $service = app(SupplierWarningLabelService::class);

        $badges = $service->supplierSpecificBadges('Yeni Nesil', [
            'supplier_warning_flag' => true,
            'net_price_warning' => false,
            'pricing_policy_type' => 'list_price_only',
        ]);

        $this->assertSame(['Turuncu Ürün'], $badges);
    }

    public function test_akdeniz_net_price_warning_is_rendered_without_red_or_orange_badges(): void
    {
        $service = app(SupplierWarningLabelService::class);

        $badges = $service->supplierSpecificBadges('Akdeniz Promosyon', [
            'warning_flag' => true,
            'supplier_warning_flag' => false,
            'net_price_warning' => true,
            'pricing_policy_type' => 'net_price',
        ]);

        $this->assertSame(['Net fiyat uyarısı'], $badges);
        $this->assertNotContains('Kırmızı Ürün', $badges);
        $this->assertNotContains('Turuncu Ürün', $badges);
    }

    public function test_ilpen_does_not_emit_supplier_specific_warning_badges_without_explicit_policy(): void
    {
        $service = app(SupplierWarningLabelService::class);

        $badges = $service->supplierSpecificBadges('İlpen', [
            'warning_flag' => true,
            'supplier_warning_flag' => false,
            'net_price_warning' => false,
            'pricing_policy_type' => 'list_price_only',
        ]);

        $this->assertSame([], $badges);
    }

    public function test_quality_warnings_stay_separate_from_supplier_specific_badges(): void
    {
        $service = app(TenantCatalogListRowQueryService::class);
        $method = new ReflectionMethod($service, 'warningsForRow');
        $method->setAccessible(true);

        $warnings = $method->invoke($service, (object) [
            'supplier_name' => 'Akdeniz Promosyon',
            'display_price' => 110.0,
            'image_url' => null,
            'standard_category_id' => null,
            'local_stock_quantity' => 0.0,
            'supplier_stock_quantity' => 0.0,
            'local_stock_priority' => 1,
        ], [
            'warning_snapshot' => ['Renk ayrıştırılamadı'],
            'price_snapshot' => [
                'net_price_warning' => true,
                'pricing_policy_type' => 'net_price',
                'supplier_warning_flag' => false,
            ],
        ]);

        $this->assertContains('Net fiyat uyarısı', $warnings);
        $this->assertContains('Kategori eşleşmemiş', $warnings);
        $this->assertContains('Görsel eksik', $warnings);
        $this->assertContains('Stok yok', $warnings);
        $this->assertContains('Renk ayrıştırılamadı', $warnings);
        $this->assertNotContains('Kırmızı Ürün', $warnings);
        $this->assertNotContains('Turuncu Ürün', $warnings);
    }
}
