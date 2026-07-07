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

    public function test_external_tab_renders_tracking_cards_and_sade_finance_labels(): void
    {
        $production = $this->createExternalProductionForShow();

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');

        $response->assertOk();
        $response->assertSee('Fason Üretim Bilgileri');
        $response->assertSee('Adet Takibi');
        $response->assertSee('Planlanan / Gönderilecek Adet');
        $response->assertSee('Gelen / Tamamlanan Adet');
        $response->assertSee('Kalan Adet');
        $response->assertSee('Eşleşen Cari');
        $response->assertSee('Cari Hareketi');
        $response->assertDontSee('current_account_id', false);
        $response->assertDontSee('source_type', false);
    }
}

