<?php

namespace App\Services;

class PromotionIntermediateElementPolicy
{
    public function enabled(): bool
    {
        return (bool) config('prodelya.features.promotion_intermediate_element_enabled', false);
    }

    public function shouldRender(): bool
    {
        return $this->enabled();
    }

    public function shouldValidate(): bool
    {
        return $this->enabled();
    }

    public function shouldPersist(): bool
    {
        return $this->enabled();
    }

    public function shouldGenerateRequirements(): bool
    {
        return $this->enabled();
    }

    public function blocksProductionReadiness(): bool
    {
        return $this->enabled();
    }
}
