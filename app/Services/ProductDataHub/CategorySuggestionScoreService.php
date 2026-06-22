<?php

namespace App\Services\ProductDataHub;

class CategorySuggestionScoreService
{
    public function finalize(array $components): array
    {
        $nameScore = (float) ($components['name_score'] ?? 0);
        $categoryScore = (float) ($components['category_score'] ?? 0);
        $attributeScore = (float) ($components['attribute_score'] ?? 0);
        $codeScore = (float) ($components['code_score'] ?? 0);
        $imageScore = (float) ($components['image_score'] ?? 0);
        $historyScore = (float) ($components['history_score'] ?? 0);

        $confidence = min(99.0, round(
            $nameScore + $categoryScore + $attributeScore + $codeScore + $imageScore + $historyScore,
            2
        ));

        return [
            'confidence_score' => $confidence,
            'name_score' => round($nameScore, 2),
            'category_score' => round($categoryScore, 2),
            'attribute_score' => round($attributeScore, 2),
            'code_score' => round($codeScore, 2),
            'image_score' => round($imageScore, 2),
            'history_score' => round($historyScore, 2),
        ];
    }
}
