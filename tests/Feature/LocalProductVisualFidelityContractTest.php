<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalProductVisualFidelityContractTest extends TestCase
{
    public function test_scoped_local_product_css_uses_reference_visual_tokens(): void
    {
        $css = file_get_contents(public_path('css/prodelya-admin.css'));

        $this->assertNotFalse($css);
        $this->assertStringContainsString('.pd-local-product-shell', $css);
        $this->assertStringContainsString('.pd-catalog-detail-main-image-wrap', $css);
        $this->assertStringContainsString('font-family: Arial, Helvetica, sans-serif;', $css);
        $this->assertStringContainsString('background: #fafbfd;', $css);
        $this->assertStringContainsString('box-shadow: 0 8px 26px rgba(21, 34, 56, 0.06);', $css);
        $this->assertStringContainsString('height: 330px;', $css);
        $this->assertStringContainsString('width: 54px;', $css);
        $this->assertStringContainsString('object-fit: contain !important;', $css);
    }
}
