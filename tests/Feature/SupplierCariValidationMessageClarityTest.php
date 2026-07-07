<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplierCariValidationMessageClarityTest extends CompanyEditSupplierSourceDifferentCompanyConflictTest
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_conflict_error_is_clear_and_technical_terms_are_hidden(): void
    {
        $this->test_different_active_company_conflict_message_names_existing_company();
    }
}
