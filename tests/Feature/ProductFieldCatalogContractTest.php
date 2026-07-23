<?php

namespace Tests\Feature;

use App\Services\TenantCatalog\LocalProductFieldCatalogService;
use Tests\TestCase;

class ProductFieldCatalogContractTest extends TestCase
{
    public function test_local_product_field_catalog_exposes_minimum_canonical_fields_and_excludes_system_inputs(): void
    {
        $service = app(LocalProductFieldCatalogService::class);
        $fields = $service->all();
        $csvHeaders = $service->csvTemplateHeaders();

        foreach (['urun_id', 'product_code', 'product_name', 'image_url', 'product_url', 'category', 'initial_stock', 'list_price', 'color', 'description', 'vat_rate', 'detail_url', 'supplier_name', 'measure', 'dimensions'] as $key) {
            $this->assertArrayHasKey($key, $fields);
        }

        $this->assertTrue($fields['urun_id']['system_generated']);
        $this->assertTrue($fields['detail_url']['system_generated']);
        $this->assertTrue($fields['supplier_name']['supplier_only']);

        $this->assertContains('urun_kodu', $csvHeaders);
        $this->assertContains('urun_adi', $csvHeaders);
        $this->assertContains('urun_url', $csvHeaders);
        $this->assertContains('urun_olcu', $csvHeaders);
        $this->assertContains('urun_ebat', $csvHeaders);
        $this->assertNotContains('urun_id', $csvHeaders);
        $this->assertNotContains('urun_detay_url', $csvHeaders);
        $this->assertNotContains('urun_tedarikci', $csvHeaders);
    }
}
