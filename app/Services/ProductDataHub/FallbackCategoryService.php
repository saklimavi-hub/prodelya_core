<?php

namespace App\Services\ProductDataHub;

use App\Models\StandardCategory;

class FallbackCategoryService
{
    public const ROOT_CODE = 'PROMO-ESLENMEMIS';
    public const PENDING_CODE = 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN';
    public const MANUAL_REVIEW_CODE = 'PROMO-ESLENMEMIS-MANUEL-KONTROL';

    public function pendingCategory(): StandardCategory
    {
        $promoRoot = StandardCategory::query()->firstOrCreate(
            ['code' => 'PROMO'],
            [
                'name' => 'Promosyon Ürünleri',
                'slug' => StandardCategory::generateSlug('Promosyon Ürünleri'),
                'product_family' => 'promotion',
                'sort_order' => 1,
                'depth' => 0,
                'path' => 'Promosyon Ürünleri',
                'is_active' => true,
                'visible_in_catalog' => true,
                'requires_mapping' => false,
                'meta' => $this->categoryMeta(),
            ]
        );

        $this->normalizeCategory($promoRoot, null, 1);

        $root = StandardCategory::query()->firstOrCreate(
            ['code' => self::ROOT_CODE],
            [
                'parent_id' => $promoRoot->id,
                'name' => 'Eşlenmemiş / Kontrol Gereken',
                'slug' => StandardCategory::generateSlug('Eşlenmemiş / Kontrol Gereken'),
                'product_family' => 'promotion',
                'sort_order' => 180,
                'depth' => 1,
                'path' => 'Promosyon Ürünleri / Eşlenmemiş / Kontrol Gereken',
                'is_active' => true,
                'visible_in_catalog' => true,
                'requires_mapping' => false,
                'meta' => $this->categoryMeta(),
            ]
        );

        $this->normalizeCategory($root, $promoRoot->id, 180);

        $pending = StandardCategory::query()->firstOrCreate(
            ['code' => self::PENDING_CODE],
            [
                'parent_id' => $root->id,
                'name' => 'Kategori Bekleyen Ürünler',
                'slug' => StandardCategory::generateSlug('Kategori Bekleyen Ürünler'),
                'product_family' => 'promotion',
                'sort_order' => 10,
                'depth' => 2,
                'path' => $root->full_path . ' / Kategori Bekleyen Ürünler',
                'is_active' => true,
                'visible_in_catalog' => true,
                'requires_mapping' => false,
                'meta' => $this->categoryMeta(),
            ]
        );

        $this->normalizeCategory($pending, $root->id, 10);

        $manual = StandardCategory::query()->firstOrCreate(
            ['code' => self::MANUAL_REVIEW_CODE],
            [
                'parent_id' => $root->id,
                'name' => 'Manuel Kontrol Gereken Ürünler',
                'slug' => StandardCategory::generateSlug('Manuel Kontrol Gereken Ürünler'),
                'product_family' => 'promotion',
                'sort_order' => 20,
                'depth' => 2,
                'path' => $root->full_path . ' / Manuel Kontrol Gereken Ürünler',
                'is_active' => true,
                'visible_in_catalog' => true,
                'requires_mapping' => false,
                'meta' => $this->categoryMeta(),
            ]
        );

        $this->normalizeCategory($manual, $root->id, 20);

        return $pending->fresh();
    }

    public function isPendingFallback(?int $categoryId): bool
    {
        if (!$categoryId) {
            return false;
        }

        return StandardCategory::query()
            ->whereKey($categoryId)
            ->where('code', self::PENDING_CODE)
            ->exists();
    }

    private function normalizeCategory(StandardCategory $category, ?int $parentId, int $sortOrder): void
    {
        $meta = array_merge((array) ($category->meta ?? []), $this->categoryMeta());

        $category->forceFill([
            'parent_id' => $parentId,
            'product_family' => 'promotion',
            'sort_order' => $sortOrder,
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => false,
            'meta' => $meta,
        ])->save();

        $category->updatePath();
    }

    private function categoryMeta(): array
    {
        return [
            'permanent_category_backbone' => true,
            'is_system' => true,
            'is_permanent' => true,
            'supplier_dependent' => false,
            'tenant_visible' => true,
            'fallback_category' => true,
        ];
    }
}
