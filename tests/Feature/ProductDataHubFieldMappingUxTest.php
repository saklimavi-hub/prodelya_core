<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubFieldMappingUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private TenantAccount $demoTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
    }

    public function test_list_and_detail_use_consistent_required_missing_summary(): void
    {
        $source = $this->createCustomXmlSource('ELMA-SOYLU-CONSISTENCY');

        $listResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.index'));

        $detailResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.source', $source));

        $listResponse->assertOk();
        $detailResponse->assertOk();

        foreach ([
            'Ürün Kodu / Varyasyon Stok Kodu',
            'Ürün Adı',
            'Liste Fiyatı / Ham Alış Fiyatı',
            'Güncelleme Anahtarı',
        ] as $label) {
            $listResponse->assertSeeText($label);
            $detailResponse->assertSeeText($label);
        }
    }

    public function test_detail_shows_missing_required_labels_and_source_sample_values(): void
    {
        $source = $this->createCustomXmlSource('ELMA-SOYLU-SAMPLES');

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.source', $source));

        $response->assertOk();
        $response->assertSeeText('Bu kaynak import için hazır değil. Zorunlu alan eşlemeleri eksik.');
        $response->assertSeeText('urun_kodu');
        $response->assertSeeText('AK-2420-GRI');
        $response->assertSeeText('kategori_adi');
        $response->assertSeeText('Defterler');
        $response->assertSeeText('resim');
        $response->assertSeeText('https://example.test/defter.jpg');
        $response->assertSeeText('urun');
    }

    public function test_custom_profile_uses_real_xml_fields_and_required_missing_filter(): void
    {
        $source = $this->createCustomXmlSource('ELMA-SOYLU-FILTER');

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.source', ['source' => $source->id, 'filter' => 'required_missing']));

        $response->assertOk();
        $response->assertSeeText('urun_kodu');
        $response->assertSeeText('urun_adi');
        $response->assertSeeText('alis_fiyati');
        $response->assertDontSeeText('kategori_adi');
        $response->assertDontSeeText('resim');
    }

    public function test_saved_mappings_make_list_and_detail_consistent_and_complete(): void
    {
        $source = $this->createCustomXmlSource('ELMA-SOYLU-SAVED');

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.field-mappings.source.update', $source), [
                'mappings' => [
                    'urun_kodu' => [
                        'standard_field_key' => 'supplier_product_code',
                        'mapping_status' => 'mapped',
                        'transform_rule' => 'trim',
                        'note' => 'Kod',
                    ],
                    'urun_adi' => [
                        'standard_field_key' => 'product_name',
                        'mapping_status' => 'mapped',
                        'transform_rule' => 'trim',
                        'note' => 'Ad',
                    ],
                    'alis_fiyati' => [
                        'standard_field_key' => 'purchase_price',
                        'mapping_status' => 'mapped',
                        'transform_rule' => 'price',
                        'note' => 'Fiyat',
                    ],
                    'kategori_adi' => [
                        'standard_field_key' => 'supplier_category_name',
                        'mapping_status' => 'mapped',
                        'transform_rule' => '',
                        'note' => 'Kategori',
                    ],
                ],
            ])->assertRedirect(route('admin.super.product-data-hub.field-mappings.source', $source));

        $listResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.index'));

        $detailResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.source', $source));

        $listResponse->assertOk();
        $detailResponse->assertOk();
        $listResponse->assertSeeText('Zorunlu alanlar tamam');
        $detailResponse->assertSeeText('Zorunlu alanlar tamam.');
        $detailResponse->assertSeeText('Ürün Kodu (supplier_product_code)');
        $detailResponse->assertSeeText('Ürün Adı (product_name)');
        $detailResponse->assertSeeText('Ham Alış Fiyatı (purchase_price)');
    }

    public function test_tenant_owner_cannot_open_global_field_mapping_screens(): void
    {
        $tenantOwner = User::query()->create([
            'name' => 'Mapping Tenant Owner',
            'email' => 'mapping-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantOwner->id,
            'tenant_account_id' => $this->demoTenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $source = $this->createCustomXmlSource('ELMA-SOYLU-FORBIDDEN');

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.index'))
            ->assertForbidden();

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.field-mappings.source', $source))
            ->assertForbidden();
    }

    private function createCustomXmlSource(string $supplierCode): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'ELMA-SOYLU',
            'code' => $supplierCode,
            'status' => 'active',
        ]);

        $fixturePath = storage_path('app/testing/' . strtolower($supplierCode) . '.xml');
        if (!is_dir(dirname($fixturePath))) {
            mkdir(dirname($fixturePath), 0777, true);
        }

        file_put_contents($fixturePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_kodu>AK-2420-GRI</urun_kodu>
        <urun_adi>Elma Soylu Spiralli Defter</urun_adi>
        <kategori_adi>Defterler</kategori_adi>
        <resim>https://example.test/defter.jpg</resim>
        <alis_fiyati>12.50</alis_fiyati>
    </urun>
</urunler>
XML);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'ELMA-SOYLU XML',
            'status' => 'active',
            'config' => [
                'profile_key' => 'CUSTOM',
                'source_file_path' => $fixturePath,
                'product_node_path' => 'urun',
                'format' => 'xml',
            ],
        ]);
    }
}
