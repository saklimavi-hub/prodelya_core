<?php

namespace Tests\Feature;

use Tests\TestCase;

class SetupRequiredMultiPrintRowsRegressionTest extends TestCase
{
    public function test_setup_required_print_rows_keep_modal_and_key_lookup_scoped_per_row(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('data-print-key="${escapeHtml(printRow._stable_key || \'\')}"', $contents);
        $this->assertStringContainsString('function resolveSetupModalElement(printOperation)', $contents);
        $this->assertStringContainsString('function resolvePrintOperationForModal(modal)', $contents);
        $this->assertStringContainsString("const modal = resolveSetupModalElement(printOperation);", $contents);
        $this->assertStringContainsString("const modalQuantityInputs = modal?.querySelectorAll('[data-setup-modal-quantity]') || [];", $contents);
        $this->assertStringContainsString("const modalFinalUnitInputs = modal?.querySelectorAll('[data-setup-modal-final-unit]') || [];", $contents);
        $this->assertStringContainsString("const modalFinalTotalInputs = modal?.querySelectorAll('[data-setup-modal-final-total]') || [];", $contents);
        $this->assertStringContainsString("if (event.target.matches('[data-action=\"open-setup-modal\"]'))", $contents);
        $this->assertStringContainsString("toggleSetupModal(printRow, true);", $contents);
        $this->assertStringContainsString("applySetupModal(modalPrintRow);", $contents);
        $this->assertStringContainsString("if (event.target.classList.contains('print-type-select'))", $contents);
        $this->assertStringContainsString("if (event.target.classList.contains('print-option-select'))", $contents);
        $this->assertStringContainsString('mountItems(collectItems());', $contents);
        $this->assertStringContainsString('${showCliche ? `', $contents);
        $this->assertStringContainsString('data-setup-modal-for="${escapeHtml(printRow._stable_key || \'\')}"', $contents);
        $this->assertStringContainsString('Ara Eleman Gerekli', $contents);
        $this->assertStringContainsString('data-setup-summary-action', $contents);
    }
}
