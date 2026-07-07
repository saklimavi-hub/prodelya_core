<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class SupplierRequestEditNoDoubleHeaderTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_edit_screen_avoids_duplicate_main_header_blocks(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-HEAD');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-HEAD-001');
        $requestRecord = $this->createSupplierRequest($procurement);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSeeText('Tedarikçi Talebi Düzenle');
        $response->assertDontSee('Cari Kart Detayı');
        $response->assertSee('Talep Aksiyonları');
    }
}
