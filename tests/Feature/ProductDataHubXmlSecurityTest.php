<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\SourceParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubXmlSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctype_xml_is_rejected(): void
    {
        $source = $this->makeSource();
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE root [
<!ELEMENT root ANY>
]>
<root><urun><urun_id>1</urun_id></urun></root>
XML;

        $result = app(SourceParserService::class)->parse($source, $xml, 10);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DOCTYPE/ENTITY', $result['errors'][0]);
    }

    public function test_entity_xml_is_rejected(): void
    {
        $source = $this->makeSource();
        $xml = <<<'XML'
<?xml version="1.0"?>
<!ENTITY xxe "blocked">
<root><urun><urun_id>1</urun_id></urun></root>
XML;

        $result = app(SourceParserService::class)->parse($source, $xml, 10);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DOCTYPE/ENTITY', $result['errors'][0]);
    }

    public function test_source_parser_uses_libxml_nonet_and_not_noent(): void
    {
        $contents = file_get_contents(app_path('Services/ProductDataHub/SourceParserService.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('LIBXML_NONET', $contents);
        $this->assertStringNotContainsString('LIBXML_NOENT', $contents);
    }

    public function test_safe_xml_parses_successfully(): void
    {
        $source = $this->makeSource();
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<root>
    <urun>
        <urun_id>1</urun_id>
        <urun_adi>Kalem</urun_adi>
    </urun>
</root>
XML;

        $result = app(SourceParserService::class)->parse($source, $xml, 10);

        $this->assertTrue($result['ok']);
        $this->assertSame('1', data_get($result, 'rows.0.urun_id'));
    }

    public function test_malformed_xml_does_not_return_raw_payload(): void
    {
        $source = $this->makeSource();
        $xml = '<root><urun><urun_id>1</urun_id><secret-token>ABC</secret-token>';

        $result = app(SourceParserService::class)->parse($source, $xml, 10);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('<secret-token>', $result['errors'][0]);
        $this->assertStringNotContainsString('ABC', $result['errors'][0]);
    }

    private function makeSource(): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'XML Supplier',
            'code' => 'XML-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'XML Source',
            'url' => null,
            'config' => [
                'format' => 'xml',
                'product_node_path' => 'urun',
                'profile_key' => 'CUSTOM',
            ],
            'status' => 'active',
        ]);
    }
}
