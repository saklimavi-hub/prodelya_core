<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionHistoryReadableTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_history_tab_uses_readable_turkish_labels(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'assign_internal',
                'production_unit_name' => 'İç Üretim Hattı',
                'note' => 'Makine hazırlığı tamamlandı.',
            ])
            ->assertRedirect(route('admin.productions.operator', $production));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production->fresh()) . '?tab=gecmis');

        $response->assertOk();
        $response->assertSee('İşlem Geçmişi');
        $response->assertSee('İç üretime atandı');
        $response->assertSee('Makine hazırlığı tamamlandı.');
        $response->assertDontSee('production_assigned_internal', false);
        $response->assertDontSee('payload', false);
    }
}
