<?php

namespace App\Services\ProductDataHub;

interface ProductImageAnalyzerInterface
{
    public function analyze(?string $imageUrl, array $context = []): array;
}
