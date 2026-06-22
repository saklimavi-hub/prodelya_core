<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductAttributeDefinition;
use App\Models\StandardCategory;

class CategoryAttributeTemplateService
{
    public function getTemplates(): array
    {
        return [
            'pen' => [
                'label' => 'Kalem Şablonu',
                'description' => 'Kalem ve yazım grubu için temel özellikleri uygular.',
                'attributes' => ['color', 'material', 'mechanism', 'touch_pen', 'ink_color', 'print_type', 'print_area'],
            ],
            'mug_thermos' => [
                'label' => 'Termos/Kupa Şablonu',
                'description' => 'Kupa, bardak ve termos ürünleri için uygundur.',
                'attributes' => ['color', 'material', 'volume_ml', 'cover_type', 'print_type', 'print_area'],
            ],
            'technology' => [
                'label' => 'Teknoloji Şablonu',
                'description' => 'USB, powerbank ve elektronik aksesuarlar için temel set.',
                'attributes' => ['color', 'material', 'capacity_gb', 'capacity_mah', 'print_type', 'print_area'],
            ],
            'notebook_agenda' => [
                'label' => 'Defter/Ajanda Şablonu',
                'description' => 'Defter, ajanda ve bloknot ürünlerinde kullanılır.',
                'attributes' => ['size', 'cover_type', 'page_count', 'paper_weight', 'binding_type', 'print_type'],
            ],
            'bag' => [
                'label' => 'Çanta Şablonu',
                'description' => 'Bez çanta, sırt çantası ve taşıma ürünleri için önerilir.',
                'attributes' => ['material', 'size', 'color', 'print_area', 'print_type'],
            ],
            'print' => [
                'label' => 'Matbaa Ürün Şablonu',
                'description' => 'Matbaa teklif ve sipariş ürünleri için temel baskı alanları.',
                'attributes' => ['paper_size', 'paper_weight', 'print_side', 'lamination', 'cutting_type', 'binding_type', 'page_count'],
            ],
        ];
    }

    public function getTemplateAttributes(string $templateKey): array
    {
        return $this->getTemplates()[$templateKey]['attributes'] ?? [];
    }

    public function applyTemplate(StandardCategory $category, string $templateKey): int
    {
        $attributeCodes = $this->getTemplateAttributes($templateKey);
        if ($attributeCodes === []) {
            return 0;
        }

        $definitions = ProductAttributeDefinition::query()
            ->whereIn('code', $attributeCodes)
            ->get()
            ->keyBy('code');

        $applied = 0;
        foreach ($attributeCodes as $index => $code) {
            $definition = $definitions->get($code);
            if (!$definition) {
                continue;
            }

            $category->attributeRules()->updateOrCreate(
                ['product_attribute_definition_id' => $definition->id],
                [
                    'is_required' => false,
                    'is_filterable' => true,
                    'visible_in_catalog' => true,
                    'sort_order' => ($index + 1) * 10,
                    'meta' => [
                        'template_key' => $templateKey,
                        'template_applied' => true,
                    ],
                ]
            );

            $applied++;
        }

        return $applied;
    }
}
