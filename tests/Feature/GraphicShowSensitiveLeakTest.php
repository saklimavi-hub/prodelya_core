<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowSensitiveLeakTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_does_not_leak_sensitive_or_technical_fields(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-SAFE-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();

        foreach ([
            'file_path',
            'physical_path',
            'storage path',
            'source_type',
            'source_id',
            'tenant_id',
            'meta_json',
            'payload',
            'group_code',
            'supplier_cost',
            'profit',
        ] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
    }
}
