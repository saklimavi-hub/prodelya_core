<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionCurrentAccountStatusTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_legacy_external_tab_redirects_to_canonical_tracking_even_when_transaction_exists(): void
    {
        $production = $this->createExternalProductionForShow();
        $this->createExternalTransactionStatus($production);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production->fresh()) . '?tab=dis-uretim')
            ->assertRedirect(route('admin.productions.subcontract-tracking', $production->fresh()));
    }
}
