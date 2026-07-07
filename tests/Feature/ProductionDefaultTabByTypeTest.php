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

    public function test_default_tab_follows_production_type_and_query_param_can_override(): void
    {
        $internal = $this->createInternalProductionForShow();
        $external = $this->createExternalProductionForShow();
        $unknown = $this->createInternalProductionForShow(['production_type' => null]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $internal))
            ->assertOk()
            ->assertSee('Üretim Akış Adımları');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $external))
            ->assertOk()
            ->assertSee('Adet Takibi');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $unknown))
            ->assertOk()
            ->assertSee('Genel Özet');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $internal) . '?tab=genel')
            ->assertOk()
            ->assertSee('Genel Özet');
    }
}
