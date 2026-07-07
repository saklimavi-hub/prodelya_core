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

    public function test_external_tab_shows_current_account_processing_status_when_transaction_exists(): void
    {
        $production = $this->createExternalProductionForShow();
        $this->createExternalTransactionStatus($production);

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production->fresh()) . '?tab=dis-uretim');

        $response->assertOk();
        $response->assertSee('Eşleşen Cari');
        $response->assertSee('Cari Hareketi');
        $response->assertSee('İşlendi');
    }
}

