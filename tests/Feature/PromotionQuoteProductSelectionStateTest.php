<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteProductSelectionStateTest extends TestCase
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

    public function test_create_page_contains_defensive_catalog_selection_normalizer_with_fallback_keys(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/promotion-quotes/create');

        $response->assertOk();
        $response->assertSee('function normalizeCatalogSelectionEntry(entry = {}, currentItem = {})', false);
        $response->assertSee('normalizedEntry.product_id', false);
        $response->assertSee('normalizedEntry.urun_kodu', false);
        $response->assertSee('normalizedEntry.price_value', false);
        $response->assertSee('currentItem.product_code', false);
        $response->assertSee('currentItem.product_name', false);
    }

    public function test_create_page_uses_safe_snapshot_normalization_so_missing_snapshot_data_does_not_drop_row(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/promotion-quotes/create');

        $response->assertOk();
        $response->assertSee('function safeObject(value)', false);
        $response->assertSee('const entryProductSnapshot = safeObject(normalizedEntry.product_snapshot);', false);
        $response->assertSee('const entryPriceSnapshot = safeObject(normalizedEntry.price_snapshot);', false);
        $response->assertSee('const entryStockSnapshot = safeObject(normalizedEntry.stock_snapshot);', false);
        $response->assertSee('...safeObject(target.product_snapshot)', false);
        $response->assertSee('...safeObject(target.price_snapshot)', false);
        $response->assertSee('...safeObject(target.stock_snapshot)', false);
    }

    public function test_create_page_preserves_stable_key_and_prints_when_catalog_result_is_selected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/promotion-quotes/create');

        $response->assertOk();
        $response->assertSee("target._stable_key = target._stable_key || itemElement.dataset.stableKey || defaultItem()._stable_key;", false);
        $response->assertSee("target.prints = (target.prints || []).map((printRow, index) => normalizePrint({", false);
        $response->assertSee("print_quantity: printRow._manual_quantity ? printRow.print_quantity : (target.quantity || printRow.print_quantity || ''),", false);
        $response->assertSee('catalogEntryStore.set(entryKey, cloneJsonSafe(entry) ?? entry);', false);
        $response->assertSee('data-entry-key="${escapeHtml(entryKey)}"', false);
        $response->assertSee("badgeHtml(badge.text, badge.tone || 'amber')", false);
    }

    public function test_only_remove_action_filters_product_rows_out(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertStringContainsString("const items = collectItems().filter((item) => item._index !== index);", $contents);
        $this->assertStringContainsString("if (event.target.matches('[data-action=\"remove-item\"]')) {", $contents);
        $this->assertStringContainsString('updateItemSummary(itemElement, entry);', $contents);
    }
}
