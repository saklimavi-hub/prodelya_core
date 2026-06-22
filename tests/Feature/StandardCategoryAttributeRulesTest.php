<?php

namespace Tests\Feature;

use App\Models\ProductAttributeDefinition;
use App\Models\StandardCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardCategoryAttributeRulesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_default_product_attribute_definition_seeder_populates_core_attributes(): void
    {
        $this->assertDatabaseHas('product_attribute_definitions', [
            'code' => 'color',
            'name' => 'Renk',
            'type' => 'select',
        ]);

        $this->assertDatabaseHas('product_attribute_definitions', [
            'code' => 'print_type',
            'name' => 'Baskı Türü',
        ]);
    }

    public function test_category_attribute_detail_page_opens_for_super_admin(): void
    {
        $this->actingAs($this->adminUser);

        $category = StandardCategory::query()->create([
            'code' => 'PROMO-ATTR-TEST',
            'name' => 'Attribute Test',
            'slug' => 'attribute-test',
            'product_family' => 'promotion',
            'sort_order' => 10,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => true,
        ]);

        $this->getOnCentralHost("/admin/super-admin/standard-categories/{$category->id}/attributes")
            ->assertOk()
            ->assertSee('Kategori Özellik Kuralları');
    }

    public function test_apply_attribute_template_creates_category_attribute_rules(): void
    {
        $this->actingAs($this->adminUser);

        $category = StandardCategory::query()->create([
            'code' => 'PROMO-PEN',
            'name' => 'Kalem Test',
            'slug' => 'kalem-test',
            'product_family' => 'promotion',
            'sort_order' => 10,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => true,
        ]);

        $response = $this->postOnCentralHost("/admin/super-admin/standard-categories/{$category->id}/attributes/apply-template", [
            'template_key' => 'pen',
        ]);

        $response->assertRedirect("/admin/super-admin/standard-categories/{$category->id}/attributes");

        $this->assertDatabaseCount('category_attribute_rules', 7);

        $colorDefinition = ProductAttributeDefinition::query()->where('code', 'color')->firstOrFail();
        $this->assertDatabaseHas('category_attribute_rules', [
            'standard_category_id' => $category->id,
            'product_attribute_definition_id' => $colorDefinition->id,
        ]);
    }

    public function test_update_attributes_route_updates_rules(): void
    {
        $this->actingAs($this->adminUser);

        $category = StandardCategory::query()->create([
            'code' => 'PRINT-DEFTER',
            'name' => 'Defter Test',
            'slug' => 'defter-test',
            'product_family' => 'print',
            'sort_order' => 20,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => true,
        ]);

        $colorDefinition = ProductAttributeDefinition::query()->where('code', 'color')->firstOrFail();
        $sizeDefinition = ProductAttributeDefinition::query()->where('code', 'size')->firstOrFail();

        $response = $this->postOnCentralHost("/admin/super-admin/standard-categories/{$category->id}/attributes", [
            'attributes' => [
                $colorDefinition->id => [
                    'enabled' => '1',
                    'is_required' => '1',
                    'is_filterable' => '1',
                    'visible_in_catalog' => '1',
                    'sort_order' => '15',
                ],
                $sizeDefinition->id => [
                    'enabled' => '0',
                    'sort_order' => '20',
                ],
            ],
        ]);

        $response->assertRedirect("/admin/super-admin/standard-categories/{$category->id}/attributes");

        $this->assertDatabaseHas('category_attribute_rules', [
            'standard_category_id' => $category->id,
            'product_attribute_definition_id' => $colorDefinition->id,
            'is_required' => true,
            'is_filterable' => true,
            'visible_in_catalog' => true,
            'sort_order' => 15,
        ]);

        $this->assertDatabaseMissing('category_attribute_rules', [
            'standard_category_id' => $category->id,
            'product_attribute_definition_id' => $sizeDefinition->id,
        ]);
    }

    private function getOnCentralHost(string $uri)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get($uri);
    }

    private function postOnCentralHost(string $uri, array $data)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->post($uri, $data);
    }
}
