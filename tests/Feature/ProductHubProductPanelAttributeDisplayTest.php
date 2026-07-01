<?php

namespace Tests\Feature;

use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductHubProductPanelAttributeDisplayTest extends TestCase
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

    public function test_product_panel_uses_normalized_attribute_display_without_backfill(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Pozitron',
            'code' => 'POZITRON-PANEL',
            'status' => 'active',
        ]);

        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $product = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'PZ-KL25GU',
            'sku' => 'PZ-KL25GU',
            'product_name' => 'Metalik Kalem',
            'base_product_name' => 'Metalik Kalem',
            'name' => 'Metalik Kalem',
            'slug' => 'pz-kl25gu',
            'standard_category_id' => $category->id,
            'currency' => 'USD',
            'min_purchase_price' => 7.75,
            'max_purchase_price' => 7.75,
            'total_stock_quantity' => 25,
            'supplier_count' => 1,
            'variant_count' => 3,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_product_code' => 'PZ-KL25GU',
                'supplier_group_code' => 'PZ-KL25',
            ]],
            'meta' => [
                'price_snapshot' => ['list_price' => 7.75, 'vat_rate' => 20],
            ],
        ]);

        foreach ([
            ['code' => 'PZ-K500SY', 'color' => 'siyah'],
            ['code' => 'PZ-K500BY', 'color' => 'beyaz'],
            ['code' => 'PZ-KL25GU', 'color' => 'gumus'],
        ] as $variant) {
            StandardProductVariant::query()->create([
                'standard_product_id' => $product->id,
                'variant_code' => $variant['code'],
                'generated_variant_code' => $variant['code'],
                'variant_name' => 'Metalik Kalem',
                'variant_color' => $variant['color'],
                'variant_size' => null,
                'variant_attributes' => [],
                'stock_quantity' => 10,
                'min_purchase_price' => 7.75,
                'max_purchase_price' => 7.75,
                'supplier_count' => 1,
                'is_active' => true,
                'visible_in_catalog' => true,
                'source_summary' => [
                    'supplier_group_code' => 'PZ-KL25',
                    'variant_stock_code' => $variant['code'],
                    'supplier_product_code' => $variant['code'],
                ],
                'meta' => [
                    'price_snapshot' => ['list_price' => 7.75, 'vat_rate' => 20],
                ],
            ]);
        }

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=PZ-K');

        $response->assertOk();
        $response->assertSeeText('Siyah');
        $response->assertSeeText('Beyaz');
        $response->assertSeeText('Gümüş');
        $response->assertDontSeeText('gumus');
        $response->assertDontSeeText('siyah');
        $response->assertDontSeeText('beyaz');
        $response->assertSeeText('PZ-K500SY');
        $response->assertSeeText('PZ-KL25GU');

        $this->assertSame('gumus', DB::table('standard_product_variants')->where('variant_code', 'PZ-KL25GU')->value('variant_color'));
    }
}
