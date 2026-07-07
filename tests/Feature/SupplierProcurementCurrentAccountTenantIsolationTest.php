<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProcurementRequestItem;
use App\Models\TenantAccount;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class SupplierProcurementCurrentAccountTenantIsolationTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_foreign_tenant_procurement_request_item_is_not_synced_into_local_supplier_payable(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPHARD-B');
        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SUP-HARD-002');

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Supplier Tenant',
            'legal_name' => 'Foreign Supplier Tenant Ltd.',
            'slug' => 'foreign-supplier-hardening',
            'panel_subdomain' => 'foreign-supplier-hardening',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignRequest = SupplierProcurementRequest::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'supplier_id' => $supplier->id,
            'request_number' => 'TS-FOREIGN-HARD-001',
            'request_date' => '2026-07-03',
            'status' => SupplierProcurementRequest::STATUS_DRAFT,
        ]);

        $foreignItem = SupplierProcurementRequestItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_procurement_request_id' => $foreignRequest->id,
            'order_item_procurement_id' => $procurement->id,
            'order_id' => $procurement->order_id,
            'order_item_id' => $procurement->order_item_id,
            'work_form_id' => $procurement->work_form_id,
            'product_code' => 'FOREIGN-PROC',
            'product_name' => 'Foreign Procurement',
            'requested_quantity' => 10,
            'unit' => 'Adet',
            'received_quantity' => 0,
            'remaining_quantity' => 10,
            'purchase_total' => 50,
        ]);

        app(SupplierProcurementCurrentAccountSyncService::class)->syncRequestItem($foreignItem->fresh(['request.supplier.tenants', 'procurement', 'order']));

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $foreignItem->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
        ]);
    }
}
