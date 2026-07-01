<?php

namespace Tests\Feature;

use App\Models\SupplierSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubSourceProfileSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_create_page_exposes_pozitron_profile_with_json_state_metadata(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources/create');

        $response->assertOk();
        $response->assertSee('POZITRON_JSON', false);
        $response->assertSee('data-profile-key="POZITRON_JSON"', false);
        $response->assertSee('data-profile-identity-key="POZITRON"', false);
        $response->assertSee('data-source-type="json"', false);
        $response->assertSee('data-source-name="Pozitron Promosyon JSON"', false);
        $response->assertSee('data-supplier-name="Pozitron Promosyon"', false);
        $response->assertSee('data-source-url="https://pozitronpromosyon.com/wp-json/public-api/v1/urunler?all=1"', false);
    }

    public function test_store_rejects_pozitron_template_when_profile_identity_and_source_type_are_wrong(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from('/admin/super-admin/product-data-hub/sources/create')
            ->post('/admin/super-admin/product-data-hub/sources', [
                'supplier_name' => 'Pozitron Promosyon',
                'source_name' => 'Pozitron Promosyon JSON',
                'profile_key' => 'ETKIN',
                'source_profile_template' => 'POZITRON_JSON',
                'source_type' => 'xml',
                'url' => 'https://pozitronpromosyon.com/wp-json/public-api/v1/urunler?all=1',
                'sync_frequency' => 'manual',
                'status' => 'active',
                'missing_product_policy' => 'manual_review',
                'report_channel' => 'screen',
                'report_enabled' => '1',
                'update_stock' => '1',
                'update_price' => '1',
                'update_images' => '1',
                'update_categories' => '1',
            ]);

        $response->assertRedirect('/admin/super-admin/product-data-hub/sources/create');
        $response->assertSessionHasErrors(['source_type']);
        $this->assertDatabaseMissing('supplier_sources', [
            'source_name' => 'Pozitron Promosyon JSON',
        ]);
    }

    public function test_store_accepts_valid_pozitron_template_payload_and_persists_json_profile_settings(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/sources', [
                'supplier_name' => 'Pozitron Promosyon',
                'source_name' => 'Pozitron Promosyon JSON',
                'profile_key' => 'POZITRON',
                'source_profile_template' => 'POZITRON_JSON',
                'source_type' => 'json',
                'format' => 'json',
                'url' => 'https://pozitronpromosyon.com/wp-json/public-api/v1/urunler?all=1',
                'sync_frequency' => 'manual',
                'status' => 'active',
                'missing_product_policy' => 'manual_review',
                'report_channel' => 'screen',
                'report_enabled' => '1',
                'update_stock' => '1',
                'update_price' => '1',
                'update_images' => '1',
                'update_categories' => '1',
            ]);

        $response->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $source = SupplierSource::query()->where('source_name', 'Pozitron Promosyon JSON')->firstOrFail();
        $this->assertSame('api', $source->source_type);
        $this->assertSame('POZITRON_JSON', data_get($source->config, 'source_profile_template'));
        $this->assertSame('POZITRON', data_get($source->config, 'profile_key'));
        $this->assertSame('json', data_get($source->config, 'ui_source_type'));
        $this->assertSame('json', data_get($source->config, 'format'));
        $this->assertSame('USD', data_get($source->config, 'currency'));
        $this->assertSame('list_price', data_get($source->config, 'pricing_policy_type'));
        $this->assertFalse((bool) data_get($source->config, 'net_price_warning'));
    }

    public function test_legacy_etkin_profile_submission_still_works_without_source_profile_template_field(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/sources', [
                'supplier_name' => 'Etkin Clone',
                'source_name' => 'Etkin Clone XML',
                'profile_key' => 'ETKIN',
                'source_type' => 'xml',
                'sync_frequency' => 'manual',
                'status' => 'active',
                'missing_product_policy' => 'manual_review',
                'report_channel' => 'screen',
                'report_enabled' => '1',
                'update_stock' => '1',
                'update_price' => '1',
                'update_images' => '1',
                'update_categories' => '1',
            ]);

        $response->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $source = SupplierSource::query()->where('source_name', 'Etkin Clone XML')->firstOrFail();
        $this->assertSame('xml', $source->source_type);
        $this->assertSame('ETKIN', data_get($source->config, 'profile_key'));
    }
}
