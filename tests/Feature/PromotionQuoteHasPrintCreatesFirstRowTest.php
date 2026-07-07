<?php

namespace Tests\Feature;

use Tests\TestCase;

class PromotionQuoteHasPrintCreatesFirstRowTest extends TestCase
{
    public function test_has_print_toggle_bootstraps_only_first_print_row_and_add_button_grows_progressively(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('function handleHasPrintToggle(itemIndex, enabled)', $contents);
        $this->assertStringContainsString('ensureItemHasFirstPrintRow(target);', $contents);
        $this->assertStringContainsString('function createDefaultPrintForItem(item = {}, index = 0, printRow = {})', $contents);
        $this->assertStringContainsString('print_quantity: defaultPrintQuantityForItem(item),', $contents);
        $this->assertStringContainsString("if (itemHasMeaningfulPrintData(target) && !window.confirm('Bu üründeki baskı satırları kaldırılacak. Devam edilsin mi?'))", $contents);
        $this->assertStringContainsString('if (!item.prints.length) {', $contents);
        $this->assertStringContainsString('item.prints.push(createDefaultPrintForItem(item, 0));', $contents);
        $this->assertStringContainsString('if (event.target.classList.contains(\'quote-has-print\')) {', $contents);
        $this->assertStringContainsString('const changed = handleHasPrintToggle(itemIndex, !!event.target.checked);', $contents);
        $this->assertStringContainsString('if (!changed) {', $contents);
        $this->assertStringContainsString('event.target.checked = true;', $contents);
        $this->assertStringContainsString('target.prints.push(createDefaultPrintForItem(target, target.prints.length, printRow || {}));', $contents);
    }
}
