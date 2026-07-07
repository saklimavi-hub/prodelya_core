<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionQuantityDisplayNoTrailingZerosTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_quantities_render_without_trailing_zeros_on_user_facing_screen(): void
    {
        $production = $this->createInternalProductionForShow([
            'planned_quantity' => 12.5000,
            'completed_quantity' => 2.2500,
            'remaining_quantity' => 10.2500,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=genel');

        $response->assertOk();
        $response->assertDontSee('12,5000');
        $response->assertDontSee('2,2500');
        $response->assertDontSee('10,2500');
        $response->assertSee('12,5');
        $response->assertSee('2,25');
        $response->assertSee('10,25');
    }
}
