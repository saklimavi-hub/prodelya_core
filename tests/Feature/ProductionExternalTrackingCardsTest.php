<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionExternalTrackingCardsTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_canonical_subcontract_tracking_renders_quantity_without_finance_leaks(): void
    {
        $production = $this->createExternalProductionForShow();

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim')
            ->assertRedirect(route('admin.productions.subcontract-tracking', $production));

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.subcontract-tracking', $production));

        $response->assertOk();
        $response->assertSee('Fason Takibi');
        $response->assertSee('Gönderilen');
        $response->assertSee('Gelen');
        $response->assertSee('Kalan');
        $response->assertDontSee('Eşleşen Cari');
        $response->assertDontSee('Cari Hareketi');
        $response->assertDontSee('current_account_id', false);
        $response->assertDontSee('source_type', false);
    }
}
