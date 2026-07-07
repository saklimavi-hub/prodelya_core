<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\SupplierProcurementRequest;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementTenantPermissionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_other_tenant_procurement_and_supplier_request_are_not_accessible(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Diğer Tenant',
            'legal_name' => 'Diğer Tenant Ltd.',
            'slug' => 'diger-tenant',
            'panel_subdomain' => 'diger-tenant',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-OTHER-PR-001',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Diğer Tenant Ürün',
            'product_code' => 'DT-001',
            'quantity' => 10,
        ]);

        $procurement = OrderItemProcurement::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requires_procurement' => true,
            'fulfillment_source' => OrderItemProcurement::FULFILLMENT_SUPPLIER,
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'requested_quantity' => 10,
            'remaining_quantity' => 10,
        ]);

        $requestRecord = SupplierProcurementRequest::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'request_number' => 'TS-2026-9001',
            'request_date' => now()->toDateString(),
            'status' => SupplierProcurementRequest::STATUS_DRAFT,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement))
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord))
            ->assertForbidden();
    }
}
