<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionDefaultTabByTypeTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_show_defaults_to_read_only_summary_and_genel_query_remains_supported(): void
    {
        $internal = $this->createInternalProductionForShow();
        $external = $this->createExternalProductionForShow();
        $unknown = $this->createInternalProductionForShow(['production_type' => null]);

        foreach ([$internal, $external, $unknown] as $production) {
            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
                ->get(route('admin.productions.show', $production))
                ->assertOk()
                ->assertSee('Üretim Detayı · Exact Baskı')
                ->assertSee('Sıradaki İşlem')
                ->assertDontSee('Canonical Akış')
                ->assertDontSee('?tab=ic-uretim', false)
                ->assertDontSee('?tab=dis-uretim', false)
                ->assertDontSee('?tab=islemler', false);
        }

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $internal) . '?tab=genel')
            ->assertOk()
            ->assertSee('Üretim Detayı · Exact Baskı');
    }
}
