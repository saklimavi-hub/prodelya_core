<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionSensitiveLeakTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_production_detail_does_not_render_sensitive_or_technical_fields(): void
    {
        $production = $this->createExternalProductionForShow();

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');

        $response->assertOk();

        foreach ([
            'subcontractor_cost',
            'purchase_price',
            'profit',
            'group_code',
            'file_path',
            'physical_path',
            'source_type',
            'source_id',
            'transaction_id',
            'current_account_id',
            'tenant_id',
            'meta_json',
            'payload',
            'raw_mapping',
            'projection_json',
        ] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
    }
}
