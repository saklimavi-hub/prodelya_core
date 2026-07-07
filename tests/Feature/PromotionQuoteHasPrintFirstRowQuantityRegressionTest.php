<?php

namespace Tests\Feature;

use Tests\TestCase;

class PromotionQuoteHasPrintFirstRowQuantityRegressionTest extends TestCase
{
    public function test_workspace_script_uses_item_quantity_for_first_and_following_default_print_rows(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('function defaultPrintQuantityForItem(item = {})', $contents);
        $this->assertStringContainsString("return item?.quantity || '';", $contents);
        $this->assertStringContainsString('function createDefaultPrintForItem(item = {}, index = 0, printRow = {})', $contents);
        $this->assertStringContainsString('print_quantity: defaultPrintQuantityForItem(item),', $contents);
        $this->assertStringContainsString(': (hasPrint ? [createDefaultPrintForItem(item, 0)] : []);', $contents);
        $this->assertStringContainsString('item.prints.push(createDefaultPrintForItem(item, 0));', $contents);
        $this->assertStringContainsString('target.prints.push(createDefaultPrintForItem(target, target.prints.length, printRow || {}));', $contents);
        $this->assertStringNotContainsString(': (hasPrint ? [normalizePrint()] : []);', $contents);
        $this->assertStringNotContainsString("item.prints.push(normalizePrint({\r\n            print_quantity: item.quantity || '',", $contents);
    }
}
