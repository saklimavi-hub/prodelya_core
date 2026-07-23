<?php

namespace Tests\Feature;

use App\Models\TenantSetting;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_controlled_procurement_detail_shows_plain_supplier_cari_language_without_technical_fields(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-CARI');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-CARI-001');
        $company = app(TenantSupplierCurrentAccountSyncService::class)
            ->syncForTenantSupplierAccess($this->tenant, $supplier)['company'];

        TenantSetting::setValue($this->tenant->id, 'process_depth', 'controlled', 'string');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement));

        $response->assertOk();
        $response->assertSee('Tedarikçi Cari Bağlantısı');
        $response->assertSee('Eşleşen Cari');
        $response->assertSee($company->legal_name);
        $response->assertSee('Cari Kartı Aç');
        $response->assertDontSee('link_type', false);
        $response->assertDontSee('current_account_id', false);
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
            ->get(route('admin.procurements.show', $procurement));

        $response->assertOk();
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('payload', false);
        $response->assertDontSee('file_path', false);
    }
}
