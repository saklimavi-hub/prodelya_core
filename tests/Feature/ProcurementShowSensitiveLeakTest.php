<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementShowSensitiveLeakTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_procurement_detail_does_not_render_sensitive_or_raw_fields(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-LEAK');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LEAK-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', ['procurement' => $procurement, 'tab' => 'gecmis']));

        $response->assertOk();
        $response->assertDontSee('source_type', false);
        $response->assertDontSee('meta_json', false);
        $response->assertDontSee('payload', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
    }
}
