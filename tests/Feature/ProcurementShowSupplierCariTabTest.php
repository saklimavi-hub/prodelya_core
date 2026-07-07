<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementShowSupplierCariTabTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_supplier_cari_tab_shows_plain_matching_language_without_technical_fields(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-CARI');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-CARI-001');
        $company = app(TenantSupplierCurrentAccountSyncService::class)
            ->syncForTenantSupplierAccess($this->tenant, $supplier)['company'];

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', ['procurement' => $procurement, 'tab' => 'tedarikci']));

        $response->assertOk();
        $response->assertSee('Tedarikçi ve Cari');
        $response->assertSee('Eşleşen Cari');
        $response->assertSee($company->legal_name);
        $response->assertSee('Güvenli eşleşme var');
    }

    public function test_procurement_show_hides_sensitive_technical_fields(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-SAFE');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-SAFE-001', [
            'product_snapshot' => [
                'product_name' => 'Güvenli Ürün',
                'product_code' => 'SAFE-001',
                'supplier_name' => $supplier->name,
                'group_code' => 'LEAK-GROUP',
                'raw_mapping' => ['token' => 'secret'],
            ],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', ['procurement' => $procurement, 'tab' => 'urun']));

        $response->assertOk();
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('payload', false);
        $response->assertDontSee('file_path', false);
    }
}
