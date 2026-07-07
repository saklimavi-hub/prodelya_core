<?php

namespace Tests\Feature;

use Tests\TestCase;

class PromotionQuoteMultiPrintRowsRegressionTest extends TestCase
{
    public function test_workspace_script_keeps_multi_print_row_addition_unbounded_and_labels_progressively(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('prints: [],', $contents);
        $this->assertStringContainsString(': (hasPrint ? [createDefaultPrintForItem(item, 0)] : []);', $contents);
        $this->assertStringContainsString('function ensureItemHasFirstPrintRow(item = {})', $contents);
        $this->assertStringContainsString('function createDefaultPrintForItem(item = {}, index = 0, printRow = {})', $contents);
        $this->assertStringContainsString('item.prints.push(createDefaultPrintForItem(item, 0));', $contents);
        $this->assertStringContainsString('target.prints = Array.isArray(target.prints) ? target.prints : [];', $contents);
        $this->assertStringContainsString('target.prints.push(createDefaultPrintForItem(target, target.prints.length, printRow || {}));', $contents);
        $this->assertStringContainsString('printRowCode(item._index, printIndex)', $contents);
        $this->assertStringContainsString('data-print-index="${printIndex}" data-print-key="${escapeHtml(printRow._stable_key || \'\')}"', $contents);
        $this->assertStringContainsString('String.fromCharCode(97 + (offset % 26)) + suffix', $contents);
        $this->assertStringContainsString('forceAddPrints(itemIndex, count = 1)', $contents);
        $this->assertStringContainsString('countDomRows(itemIndex)', $contents);
        $this->assertStringContainsString('generatedLabel: printRowCode(itemIndex, target.prints.length - 1)', $contents);
        $this->assertStringContainsString('data-setup-modal-for="${escapeHtml(printRow._stable_key || \'\')}"', $contents);
        $this->assertStringNotContainsString('target.prints = target.prints.slice(0, 2)', $contents);
        $this->assertStringNotContainsString('if (target.prints.length >= 2)', $contents);
        $this->assertStringNotContainsString('prints: [normalizePrint()]', $contents);
    }
}
