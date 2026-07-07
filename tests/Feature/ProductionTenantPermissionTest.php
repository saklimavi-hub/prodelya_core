<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionTenantPermissionTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_other_tenant_production_detail_cannot_be_opened(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Production Tenant',
            'legal_name' => 'Other Production Tenant Ltd.',
            'slug' => 'other-production-tenant',
            'panel_subdomain' => 'other-production-tenant',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-OTHER-PRD',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_name' => 'Other Tenant Product',
            'product_code' => 'OTH-PRD-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'has_print' => true,
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 10,
        ]);

        $production = OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'planned_quantity' => 10,
            'remaining_quantity' => 10,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'qc_status' => OrderItemPrintProduction::QC_WAITING,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production))
            ->assertForbidden();
    }
}

