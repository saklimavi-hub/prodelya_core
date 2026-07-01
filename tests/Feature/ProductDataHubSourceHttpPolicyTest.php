<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\User;
use App\Services\ProductDataHub\SourceFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductDataHubSourceHttpPolicyTest extends TestCase
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

    public function test_source_fetch_service_prefers_configured_user_agent_over_headers(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response('<ROOT />', 200, ['Content-Type' => 'application/xml']),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/feed.xml',
            'config' => [
                'format' => 'xml',
                'user_agent' => 'prodelya.com',
                'request_headers' => '{"User-Agent":"header-agent","X-Client":"Prodelya"}',
            ],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertTrue($result['ok']);
        Http::assertSent(function ($request) {
            return $request->hasHeader('User-Agent', 'prodelya.com')
                && !$request->hasHeader('User-Agent', 'header-agent')
                && $request->hasHeader('X-Client', 'Prodelya');
        });
    }

    public function test_source_fetch_service_uses_request_header_user_agent_when_dedicated_value_is_blank(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response('<ROOT />', 200, ['Content-Type' => 'application/xml']),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/feed.xml',
            'config' => [
                'format' => 'xml',
                'request_headers' => '{"User-Agent":"saklimavi.com","X-Client":"Prodelya"}',
            ],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertTrue($result['ok']);
        Http::assertSent(function ($request) {
            return $request->hasHeader('User-Agent', 'saklimavi.com')
                && $request->hasHeader('X-Client', 'Prodelya');
        });
    }

    public function test_source_fetch_service_uses_default_user_agent_when_no_policy_is_defined(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response('<ROOT />', 200, ['Content-Type' => 'application/xml']),
        ]);

        $source = $this->makeSource([
            'url' => 'https://example.com/feed.xml',
            'config' => ['format' => 'xml'],
        ]);

        $result = app(SourceFetchService::class)->fetch($source);

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'Prodelya Product Data Hub'));
    }

    public function test_super_admin_source_store_persists_user_agent_and_masks_sensitive_headers_on_edit(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/sources', [
                'supplier_name' => 'Policy Tedarikcisi',
                'source_name' => 'Policy XML',
                'source_type' => 'xml',
                'profile_key' => 'CUSTOM',
                'url' => 'https://example.com/policy.xml',
                'sync_frequency' => 'manual',
                'status' => 'active',
                'user_agent' => 'prodelya.com',
                'timeout_seconds' => 40,
                'request_headers' => '{"Accept":"application/xml","Authorization":"Bearer super-secret"}',
                'missing_product_policy' => 'manual_review',
                'report_channel' => 'screen',
                'report_enabled' => '1',
                'update_stock' => '1',
                'update_price' => '1',
                'update_images' => '1',
                'update_categories' => '1',
            ]);

        $response->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $source = SupplierSource::query()->where('source_name', 'Policy XML')->firstOrFail();

        $this->assertSame('prodelya.com', data_get($source->config, 'user_agent'));
        $this->assertSame(40, data_get($source->config, 'timeout_seconds'));
        $this->assertStringContainsString('Authorization', (string) data_get($source->config, 'request_headers'));

        $editResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get("/admin/super-admin/product-data-hub/sources/{$source->id}/edit");

        $editResponse->assertOk();
        $editResponse->assertSee('HTTP User-Agent');
        $editResponse->assertSee('Ozel HTTP Header');
        $editResponse->assertSee('[hidden]');
        $editResponse->assertDontSee('super-secret');
    }

    public function test_invalid_request_headers_json_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from('/admin/super-admin/product-data-hub/sources/create')
            ->post('/admin/super-admin/product-data-hub/sources', [
                'supplier_name' => 'Broken Header Supplier',
                'source_name' => 'Broken Header XML',
                'source_type' => 'xml',
                'profile_key' => 'CUSTOM',
                'sync_frequency' => 'manual',
                'status' => 'active',
                'request_headers' => '{not-json}',
                'missing_product_policy' => 'manual_review',
                'report_channel' => 'screen',
                'report_enabled' => '1',
                'update_stock' => '1',
                'update_price' => '1',
                'update_images' => '1',
                'update_categories' => '1',
            ]);

        $response->assertSessionHasErrors('request_headers');
    }

    public function test_source_list_keeps_url_missing_badge_until_url_or_file_path_exists(): void
    {
        $this->actingAs($this->adminUser);

        SupplierSource::query()->delete();

        $source = $this->makeSource([
            'source_name' => 'Missing URL Source',
            'url' => null,
            'config' => [
                'profile_key' => 'CUSTOM',
                'source_file_path' => null,
            ],
        ]);

        $listResponse = $this->getOnCentralHost('/admin/super-admin/product-data-hub/sources?filter=all');
        $listResponse->assertOk();
        $listResponse->assertViewHas('stats', fn (array $stats) => ($stats['url_missing'] ?? null) === 1);
        $listResponse->assertViewHas('suppliers', function ($suppliers) {
            $first = $suppliers->first();

            return (int) $suppliers->count() === 1
                && (bool) ($first['supplier']->name ?? null)
                && (int) ($first['source_count'] ?? 0) === 1;
        });

        $source->update([
            'url' => 'https://example.com/defined.xml',
            'config' => [
                'profile_key' => 'CUSTOM',
                'source_file_path' => null,
            ],
        ]);

        $updatedResponse = $this->getOnCentralHost('/admin/super-admin/product-data-hub/sources?filter=all');
        $updatedResponse->assertOk();
        $updatedResponse->assertViewHas('stats', fn (array $stats) => ($stats['url_missing'] ?? null) === 0);
        $updatedResponse->assertViewHas('suppliers', fn ($suppliers) => (int) $suppliers->count() === 1);
    }

    public function test_source_with_only_user_agent_still_shows_url_missing_and_preview_warning(): void
    {
        $source = SupplierSource::query()->where('source_name', 'Yeni Nesil CSV')->firstOrFail();
        $config = (array) ($source->config ?? []);
        $config['user_agent'] = 'saklimavi.com';
        $source->update([
            'url' => null,
            'config' => $config,
        ]);

        $this->actingAs($this->adminUser);

        $listResponse = $this->getOnCentralHost('/admin/super-admin/product-data-hub/sources?filter=all');
        $listResponse->assertOk();
        $listResponse->assertViewHas('stats', fn (array $stats) => ($stats['url_missing'] ?? 0) >= 1);

        $previewResponse = $this->getOnCentralHost("/admin/super-admin/product-data-hub/sources/{$source->id}/preview");
        $previewResponse->assertOk();
        $previewResponse->assertSee('Canlı kaynak okunamadı, örnek veya demo veri gösteriliyor.');
        $previewResponse->assertSee('Kaynak URL veya dosya yolu tanımlanmamış.');
    }

    private function makeSource(array $attributes = []): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Policy Supplier',
            'code' => 'POLICY-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Policy Source',
            'url' => null,
            'config' => [],
            'status' => 'active',
        ], $attributes));
    }

    private function getOnCentralHost(string $uri)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get($uri);
    }
}
