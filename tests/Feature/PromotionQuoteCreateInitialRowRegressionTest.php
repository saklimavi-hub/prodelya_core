<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCreateInitialRowRegressionTest extends TestCase
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

    public function test_create_screen_keeps_default_item_row_mount_fallback_and_print_actions(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('id="product-items-container"', false);
        $response->assertSee('Ürün Ekle');
        $response->assertSee('Baskı Ekle');
        $response->assertSee('Teklif Özeti');
        $response->assertSee('const initialItems = quoteWorkspace.items?.length ? quoteWorkspace.items : [defaultItem()];', false);
        $response->assertSee('mountItems(initialItems);', false);
        $response->assertSee('prints: [],', false);
        $response->assertSee(": (hasPrint ? [createDefaultPrintForItem(item, 0)] : []);", false);
        $response->assertSee('function createDefaultPrintForItem(item = {}, index = 0, printRow = {})', false);
        $response->assertDontSee(": (hasPrint ? [normalizePrint()] : []);", false);
    }

    public function test_workspace_script_avoids_nullish_and_or_parse_error_in_print_option_resolution(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString("String((optionLabel ?? printRow.print_option) || '').trim();", $contents);
        $this->assertStringNotContainsString("String(optionLabel ?? printRow.print_option || '').trim();", $contents);
    }
}
