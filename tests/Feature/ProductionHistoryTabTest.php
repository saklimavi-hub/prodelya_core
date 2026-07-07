<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionHistoryTabTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_history_tab_renders_readable_filters_and_log_table(): void
    {
        $production = $this->createInternalProductionForShow();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=gecmis');

        $response->assertOk();
        $response->assertSee('İşlem Geçmişi');
        $response->assertSee('Filtreler');
        $response->assertDontSee('production_status_updated');
    }
}
