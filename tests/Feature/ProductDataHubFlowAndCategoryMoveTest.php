<?php

namespace Tests\Feature;

use App\Models\CategoryMoveLog;
use App\Models\StandardCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubFlowAndCategoryMoveTest extends TestCase
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

    public function test_product_data_hub_index_shows_flow_and_catalog_output_links(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub')
            ->assertOk()
            ->assertSee('Product Data Hub Akışı')
            ->assertSee('Tenant Çıkışları')
            ->assertSee('Ortak Ürün Havuzu')
            ->assertSee('Tedarikçi Kaynakları')
            ->assertSee('Product Data Hub')
            ->assertSee('Kategori Eşleme');
    }

    public function test_catalog_output_page_renders_super_admin_output_panel(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/catalog-output')
            ->assertOk()
            ->assertSee('Katalog Çıkışı')
            ->assertSee('Tenant katalog projeksiyonunu güncelle')
            ->assertSee('Gelişmiş Ürün ve Katalog');
    }

    public function test_category_parent_can_be_changed_and_move_is_logged(): void
    {
        $this->actingAs($this->adminUser);

        $rootA = $this->makeCategory('PROMO-A', 'Promosyon A', null, 0);
        $rootB = $this->makeCategory('PROMO-B', 'Promosyon B', null, 0);
        $child = $this->makeCategory('PROMO-CHILD', 'Taşınacak Kategori', $rootA->id, 1);

        $response = $this->postOnCentralHost("/admin/super-admin/standard-categories/{$child->id}/move", [
            'new_parent_id' => $rootB->id,
            'new_sort_order' => 25,
            'product_family' => 'promotion',
            'notes' => 'Taşıma testi',
        ]);

        $response->assertRedirect('/admin/super-admin/standard-categories');

        $child->refresh();

        $this->assertSame($rootB->id, $child->parent_id);
        $this->assertSame(25, $child->sort_order);
        $this->assertStringContainsString('Promosyon B / Taşınacak Kategori', $child->full_path);
        $this->assertDatabaseHas('category_move_logs', [
            'category_id' => $child->id,
            'old_parent_id' => $rootA->id,
            'new_parent_id' => $rootB->id,
            'notes' => 'Taşıma testi',
        ]);
    }

    public function test_category_cannot_be_moved_under_its_descendant(): void
    {
        $this->actingAs($this->adminUser);

        $root = $this->makeCategory('PROMO-ROOT', 'Kök', null, 0);
        $child = $this->makeCategory('PROMO-CHILD-2', 'Alt', $root->id, 1);
        $grandChild = $this->makeCategory('PROMO-GRAND', 'Alt Alt', $child->id, 2);

        $response = $this->from('/admin/super-admin/standard-categories')->postOnCentralHost("/admin/super-admin/standard-categories/{$root->id}/move", [
            'new_parent_id' => $grandChild->id,
            'new_sort_order' => 10,
            'product_family' => 'promotion',
        ]);

        $response->assertSessionHasErrors('new_parent_id');
        $this->assertDatabaseCount('category_move_logs', 0);
    }

    public function test_deep_category_move_requires_explicit_confirmation(): void
    {
        $this->actingAs($this->adminUser);

        $level0 = $this->makeCategory('ROOT-L0', 'L0', null, 0);
        $level1 = $this->makeCategory('ROOT-L1', 'L1', $level0->id, 1);
        $level2 = $this->makeCategory('ROOT-L2', 'L2', $level1->id, 2);
        $level3 = $this->makeCategory('ROOT-L3', 'L3', $level2->id, 3);
        $moveMe = $this->makeCategory('ROOT-MOVE', 'Move Me', null, 0);

        $response = $this->from('/admin/super-admin/standard-categories')->postOnCentralHost("/admin/super-admin/standard-categories/{$moveMe->id}/move", [
            'new_parent_id' => $level3->id,
            'new_sort_order' => 10,
            'product_family' => 'promotion',
        ]);

        $response->assertSessionHasErrors('confirm_deep_move');
        $moveMe->refresh();
        $this->assertNull($moveMe->parent_id);
    }

    private function makeCategory(string $code, string $name, ?int $parentId, int $depth): StandardCategory
    {
        $path = $name;

        if ($parentId) {
            $parent = StandardCategory::query()->findOrFail($parentId);
            $path = $parent->full_path . ' / ' . $name;
        }

        return StandardCategory::query()->create([
            'code' => $code,
            'name' => $name,
            'slug' => StandardCategory::generateSlug($name),
            'parent_id' => $parentId,
            'product_family' => 'promotion',
            'sort_order' => 10,
            'depth' => $depth,
            'path' => $path,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => true,
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
