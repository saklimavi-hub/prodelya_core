<?php

namespace Tests\Feature;

use Tests\TestCase;

class PromotionQuoteMultiplePrintRowsRuntimeRegressionTest extends TestCase
{
    public function test_workspace_script_does_not_boot_with_hidden_placeholder_print_row_and_supports_progressive_rows(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('prints: [],', $contents);
        $this->assertStringContainsString(': (hasPrint ? [createDefaultPrintForItem(item, 0)] : []);', $contents);
        $this->assertStringContainsString('target.prints.push(createDefaultPrintForItem(target, target.prints.length, printRow || {}));', $contents);
        $this->assertStringContainsString('function handleHasPrintToggle(itemIndex, enabled)', $contents);
        $this->assertStringContainsString('ensureItemHasFirstPrintRow(target);', $contents);
        $this->assertStringContainsString('function defaultPrintQuantityForItem(item = {})', $contents);
        $this->assertStringContainsString('function printRowCode(itemIndex, printIndex)', $contents);
        $this->assertStringContainsString('while (offset >= 0)', $contents);
        $this->assertStringContainsString('String.fromCharCode(97 + (offset % 26)) + suffix', $contents);
        $this->assertStringContainsString('data-print-key="${escapeHtml(printRow._stable_key || \'\')}"', $contents);
        $this->assertStringContainsString('collectItems().forEach((item, itemIndex) => {', $contents);
        $this->assertStringNotContainsString('prints: [normalizePrint()]', $contents);
        $this->assertStringNotContainsString('maxPrints = 2', $contents);
        $this->assertStringNotContainsString('prints.length >= 2', $contents);
        $this->assertStringNotContainsString('slice(0, 2)', $contents);
        $this->assertStringNotContainsString('index < 2', $contents);
    }
}
