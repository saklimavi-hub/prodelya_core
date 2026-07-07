<?php

namespace Tests\Feature;

use Tests\TestCase;

class PromotionQuoteSetupSummaryUiTest extends TestCase
{
    public function test_setup_summary_ui_uses_compact_ara_eleman_summary_and_modal_action_copy(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('Ara Eleman Gerekli', $contents);
        $this->assertStringContainsString('data-setup-summary-action', $contents);
        $this->assertStringContainsString('Ara Eleman Ayarla', $contents);
        $this->assertStringContainsString('Düzenle', $contents);
        $this->assertStringContainsString('pd-setup-inline-summary', $contents);
        $this->assertStringContainsString('pd-setup-inline-chip', $contents);
        $this->assertStringContainsString("cliche: 'Klişe'", $contents);
        $this->assertStringContainsString("mold: 'Kalıp'", $contents);
        $this->assertStringContainsString("film: 'Film'", $contents);
        $this->assertStringContainsString("apparatus: 'Aparat'", $contents);
        $this->assertStringNotContainsString('KlişE', $contents);
        $this->assertStringNotContainsString('Hesapla / Düzenle', $contents);
    }
}
