<?php

namespace App\Services\ProductDataHub;

class NullProductImageAnalyzer implements ProductImageAnalyzerInterface
{
    public function analyze(?string $imageUrl, array $context = []): array
    {
        return [
            'image_score' => 0.0,
            'status' => 'manual_review',
            'message' => filled($imageUrl)
                ? 'Görsel sinyali manuel kontrol edilecek.'
                : 'Görsel analizi bekliyor.',
            'signals' => [],
        ];
    }
}
