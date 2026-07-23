<?php

namespace Tests\Feature;

use App\Models\ProductDataHubSyncRun;
use App\Models\Supplier;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\User;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubSourceRecoveryAndTemplateTest extends TestCase
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

    public function test_standard_category_tree_is_visible_after_seeded_restore(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/standard-categories');

        $response->assertOk();
        $response->assertSee('Promosyon Ürünleri');
        $response->assertSee('Matbaa Ürünleri');
    }

    public function test_source_list_shows_real_four_suppliers_and_temp_profiles_are_warned(): void
    {
        $tempSupplier = Supplier::query()->create([
            'name' => 'Yeni Nesil Temp',
            'code' => 'TMP-YN-' . uniqid(),
            'status' => 'active',
        ]);

        SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Temp XML',
            'source_type' => 'xml',
            'status' => 'active',
            'config' => ['profile_key' => 'TMP-YN-DEMO', 'format' => 'xml'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources?filter=all');

        $response->assertOk();
        $response->assertSee('Yeni Nesil');
        $response->assertSee('Etkin Promosyon');
        $response->assertSee('Akdeniz Promosyon');
        $response->assertSee('İlpen');
        $response->assertSee('Yeni Nesil Temp');
        $response->assertSee('Kaynak Kartları');
        $response->assertSee('Geçici Profil');
        $response->assertSee('Bağlantı Bekleyen');
    }

    public function test_tmp_source_is_not_seeded_or_shown_by_default(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources');

        $response->assertOk();
        $response->assertDontSee('TMP-YN-');
        $response->assertDontSee('Yeni Nesil Temp');
    }

    public function test_tmp_source_is_excluded_from_visible_and_selectable_scopes(): void
    {
        $tempSupplier = Supplier::query()->create([
            'name' => 'Temp Seçim',
            'code' => 'TMP-SLC-' . uniqid(),
            'status' => 'active',
        ]);

        $tempSource = SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Temp Seçim XML',
            'source_type' => 'xml',
            'status' => 'active',
            'config' => ['profile_key' => 'TMP-SLC-DEMO'],
        ]);

        $this->assertFalse(SupplierSource::query()->visibleInProductDataHub()->pluck('id')->contains($tempSource->id));
        $this->assertFalse(SupplierSource::query()->selectable()->pluck('id')->contains($tempSource->id));
    }

    public function test_source_create_page_renders_profile_library_cards_from_config(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources/create');

        $response->assertOk();
        $response->assertSee('Profil ve Parsing');
        $response->assertSee('Mevcut Profilden Kopyala');
        $response->assertSee('Boş Profil / Manuel Alan Eşleme');

        foreach (array_keys(config('prodelya_product_data_hub.supplier_profiles', [])) as $profileKey) {
            $response->assertSee($profileKey);
        }
    }

    public function test_category_mapping_center_can_see_seeded_real_sources(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings');

        $response->assertOk();
        $response->assertSee('Yeni Nesil');
        $response->assertSee('Etkin Promosyon');
        $response->assertSee('Akdeniz Promosyon');
        $response->assertSee('İlpen');
    }

    public function test_archived_or_temp_sources_are_hidden_from_category_mapping_center_and_sync_reports(): void
    {
        $tempSupplier = Supplier::query()->create([
            'name' => 'Kategori Temp',
            'code' => 'TMP-CAT-' . uniqid(),
            'status' => 'active',
        ]);

        $archivedSource = SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Kategori Temp XML',
            'source_type' => 'xml',
            'status' => 'inactive',
            'config' => [
                'profile_key' => 'TMP-CAT-DEMO',
                'lifecycle_state' => 'archived',
            ],
        ]);

        ProductDataHubSyncRun::query()->create([
            'supplier_source_id' => $archivedSource->id,
            'run_type' => 'manual',
            'status' => 'success',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'records_read' => 0,
        ]);

        $categoryResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/category-mappings');

        $categoryResponse->assertOk();
        $categoryResponse->assertDontSee('Kategori Temp XML');
        $categoryResponse->assertDontSee('TMP-CAT-DEMO');

        $syncResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources/sync-reports');

        $syncResponse->assertOk();
        $syncResponse->assertDontSee('Kategori Temp XML');
        $syncResponse->assertDontSee('TMP-CAT-DEMO');
    }

    public function test_custom_supplier_source_can_be_created_without_ready_profile(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/sources', [
                'supplier_name' => 'X Tedarikçi',
                'source_name' => 'X Tedarikçi XML',
                'source_type' => 'xml',
                'profile_key' => 'CUSTOM',
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
        $this->assertDatabaseHas('suppliers', ['name' => 'X Tedarikçi']);
        $this->assertDatabaseHas('supplier_sources', ['source_name' => 'X Tedarikçi XML']);
    }

    public function test_profile_copy_creates_new_supplier_source_and_copies_field_mappings(): void
    {
        $template = SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', 'AKDENIZ'))
            ->firstOrFail();
        $templateMappingCount = SupplierFieldMapping::query()
            ->where('supplier_source_id', $template->id)
            ->count();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/sources', [
                'supplier_name' => 'X Tedarikçi',
                'source_name' => 'X Tedarikçi XML',
                'source_type' => 'xml',
                'profile_key' => 'AKDENIZ',
                'template_source_id' => $template->id,
                'sync_frequency' => 'daily',
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

        $newSource = SupplierSource::query()
            ->where('source_name', 'X Tedarikçi XML')
            ->with('supplier')
            ->firstOrFail();

        $this->assertSame('X Tedarikçi', $newSource->supplier->name);
        $this->assertSame('AKDENIZ', data_get($newSource->config, 'profile_key'));
        $this->assertSame($template->id, data_get($newSource->config, 'copied_from_source_id'));
        $this->assertSame($templateMappingCount, SupplierFieldMapping::query()->where('supplier_source_id', $newSource->id)->count());
    }

    public function test_tmp_profile_is_hidden_from_ready_template_cards(): void
    {
        $tempSupplier = Supplier::query()->create([
            'name' => 'Temp X',
            'code' => 'TMP-X-' . uniqid(),
            'status' => 'active',
        ]);

        SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Temp Source',
            'source_type' => 'xml',
            'status' => 'active',
            'config' => ['profile_key' => 'TMP-X-DEMO'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources/create');

        $response->assertOk();
        $response->assertDontSee('TMP-X-DEMO');
        $response->assertDontSee('Temp Source');
    }

    public function test_archived_and_temp_sources_are_hidden_from_copyable_source_list(): void
    {
        $tempSupplier = Supplier::query()->create([
            'name' => 'Kopya Temp',
            'code' => 'TMP-CP-' . uniqid(),
            'status' => 'active',
        ]);

        SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Kopya Temp XML',
            'source_type' => 'xml',
            'status' => 'active',
            'config' => ['profile_key' => 'TMP-CP-DEMO'],
        ]);

        $archivedSource = SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', 'ILPEN'))
            ->firstOrFail();

        $archivedSource->update([
            'status' => 'inactive',
            'config' => array_merge($archivedSource->config ?? [], ['lifecycle_state' => 'archived']),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources/create');

        $response->assertOk();
        $response->assertDontSee('Kopya Temp XML');
        $response->assertDontSee($archivedSource->source_name);
    }

    public function test_unlinked_temp_source_can_be_deleted(): void
    {
        $tempSupplier = Supplier::query()->create([
            'name' => 'Silinecek Temp',
            'code' => 'TMP-DEL-' . uniqid(),
            'status' => 'active',
        ]);

        $tempSource = SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Silinecek Temp XML',
            'source_type' => 'xml',
            'status' => 'inactive',
            'config' => ['profile_key' => 'TMP-DEL-DEMO'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->delete('/admin/super-admin/product-data-hub/sources/' . $tempSource->id);

        $response->assertRedirect('/admin/super-admin/product-data-hub/sources');
        $this->assertDatabaseMissing('supplier_sources', ['id' => $tempSource->id]);
    }

    public function test_linked_real_source_cannot_be_hard_deleted_but_can_be_deactivated(): void
    {
        $source = SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', 'YENI-NESIL'))
            ->firstOrFail();

        $deleteResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->delete('/admin/super-admin/product-data-hub/sources/' . $source->id);

        $deleteResponse->assertRedirect('/admin/super-admin/product-data-hub/sources');
        $this->assertDatabaseHas('supplier_sources', ['id' => $source->id]);

        $deactivateResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/sources/' . $source->id . '/deactivate');

        $deactivateResponse->assertRedirect('/admin/super-admin/product-data-hub/sources');
        $this->assertDatabaseHas('supplier_sources', [
            'id' => $source->id,
            'status' => 'inactive',
        ]);
    }

    public function test_source_list_shows_delete_and_deactivate_actions(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources');

        $response->assertOk();
        $response->assertSee('Kaynak Kartları');
        $response->assertDontSee('Pasifleştir');
        $response->assertDontSee('Sil');
        $response->assertDontSee('Arşivle');
    }

    public function test_source_list_can_show_temp_sources_only_with_explicit_filter(): void
    {
        $tempSupplier = Supplier::query()->create([
            'name' => 'Filtre Temp',
            'code' => 'TMP-FLT-' . uniqid(),
            'status' => 'active',
        ]);

        SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Filtre Temp XML',
            'source_type' => 'xml',
            'status' => 'inactive',
            'config' => [
                'profile_key' => 'TMP-FLT-DEMO',
                'lifecycle_state' => 'archived',
            ],
        ]);

        $defaultResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources');

        $defaultResponse->assertOk();
        $defaultResponse->assertDontSee('Filtre Temp XML');

        $tempResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources?filter=temp');

        $tempResponse->assertOk();
        $tempResponse->assertSee('Filtre Temp');
        $tempResponse->assertSee('Kaynak Kartları');
    }

    public function test_deactivated_source_disappears_from_active_dropdowns_but_real_others_remain(): void
    {
        $source = SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', 'ETKIN'))
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/super-admin/product-data-hub/sources/' . $source->id . '/deactivate')
            ->assertRedirect('/admin/super-admin/product-data-hub/sources');

        $createResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/sources/create');

        $createResponse->assertOk();
        $createResponse->assertDontSee($source->source_name);
        $createResponse->assertSee('Yeni Nesil CSV');
        $createResponse->assertSee('Akdeniz Promosyon API');
        $createResponse->assertSee('İlpen XML');
    }

    public function test_tenant_supplier_access_edit_hides_temp_or_archived_supplier_sources(): void
    {
        $tenant = \App\Models\TenantAccount::query()->firstOrFail();
        $tempSupplier = Supplier::query()->create([
            'name' => 'Tenant Temp',
            'code' => 'TMP-TEN-' . uniqid(),
            'status' => 'active',
        ]);

        SupplierSource::query()->create([
            'supplier_id' => $tempSupplier->id,
            'source_name' => 'Tenant Temp XML',
            'source_type' => 'xml',
            'status' => 'inactive',
            'config' => [
                'profile_key' => 'TMP-TEN-DEMO',
                'lifecycle_state' => 'archived',
            ],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/tenant-supplier-access/' . $tenant->id . '/edit');

        $response->assertOk();
        $response->assertDontSee('Tenant Temp');
        $response->assertSee('Yeni Nesil');
        $response->assertSee('Etkin Promosyon');
        $response->assertSee('Akdeniz Promosyon');
        $response->assertSee('İlpen');
    }

    public function test_sync_report_marks_updated_created_and_missing_without_hard_delete(): void
    {
        $source = SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', 'AKDENIZ'))
            ->firstOrFail();

        SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'PB-4007',
            'source_name' => 'Powerbank',
            'source_category' => 'Powerbanklar',
            'supplier_product_code' => 'PB-4007',
            'product_name' => 'Powerbank',
            'supplier_category_name' => 'Powerbanklar',
            'image_url' => 'old-powerbank.jpg',
            'source_price' => 100,
            'normalized_payload' => ['list_price' => 100],
            'import_hash' => 'existing-pb-4007',
            'sync_status' => 'processed',
        ]);

        SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'OLD-100',
            'source_name' => 'Eski Ürün',
            'source_category' => 'Eski Kategori',
            'supplier_product_code' => 'OLD-100',
            'product_name' => 'Eski Ürün',
            'supplier_category_name' => 'Eski Kategori',
            'image_url' => 'old.jpg',
            'source_price' => 50,
            'normalized_payload' => ['list_price' => 50],
            'import_hash' => 'existing-old-100',
            'sync_status' => 'processed',
        ]);

        $previewData = [
            'products' => [
                [
                    'import_hash' => 'new-pb-4007',
                    'supplier_product_code' => 'PB-4007',
                    'product_name' => 'Powerbank',
                    'supplier_category_name' => 'Powerbanklar',
                    'image_url' => 'new-powerbank.jpg',
                    'list_price' => 150,
                    'normalized_payload' => ['list_price' => 150],
                    'warnings' => [],
                    'errors' => [],
                ],
                [
                    'import_hash' => 'new-usb-1',
                    'supplier_product_code' => 'USB-1',
                    'product_name' => 'Yeni USB',
                    'supplier_category_name' => 'USB Bellekler',
                    'image_url' => 'usb.jpg',
                    'list_price' => 80,
                    'normalized_payload' => ['list_price' => 80],
                    'warnings' => [],
                    'errors' => [],
                ],
            ],
            'variants' => [],
            'stats' => ['records_read' => 2],
        ];

        $result = app(SupplierSourceSyncService::class)->syncPreviewData($source, $previewData);
        $run = $result['run']->fresh();

        $this->assertSame(1, $run->products_created);
        $this->assertSame(1, $run->products_updated);
        $this->assertSame(1, $run->products_missing_from_feed);
        $this->assertSame(1, $run->price_changed_count);
        $this->assertSame(1, $run->image_changed_count);
        $this->assertDatabaseHas('supplier_products_raw', [
            'supplier_source_id' => $source->id,
            'supplier_product_code' => 'OLD-100',
        ]);
        $this->assertDatabaseHas('supplier_products_raw', [
            'supplier_source_id' => $source->id,
            'supplier_product_code' => 'OLD-100',
            'sync_status' => 'skipped',
        ]);
        $missingProduct = SupplierProductRaw::query()
            ->where('supplier_source_id', $source->id)
            ->where('supplier_product_code', 'OLD-100')
            ->firstOrFail();
        $this->assertSame('missing_from_feed', data_get($missingProduct->normalized_payload, '_sync_meta.last_sync_status'));
        $this->assertDatabaseHas('product_data_hub_sync_runs', [
            'id' => $run->id,
            'supplier_source_id' => $source->id,
        ]);
        $this->assertGreaterThanOrEqual(3, $run->changes()->count());
    }
}
