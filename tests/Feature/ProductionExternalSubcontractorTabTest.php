<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionExternalSubcontractorTabTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;
    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_legacy_dis_uretim_tab_redirects_to_canonical_assignment_without_internal_actions(): void
    {
        $partner = $this->createPartnerCompany();
        $production = $this->createSubcontractedProduction($partner, [
            'completed_quantity' => 20,
            'remaining_quantity' => 30,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim')
            ->assertRedirect(route('admin.productions.subcontract-assignment', $production));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.subcontract-assignment', $production));

        $response->assertOk();
        $response->assertSee('Üretim / Fason');
        $response->assertSee('Kalan');
        $response->assertSee((string) ($partner->short_name ?: $partner->legal_name));
        $response->assertDontSee('Üretim Akış Adımları');
        $response->assertDontSee('Cari Hareketi');
    }

    private function createSubcontractedProduction(Company $partner, array $quantityOverrides = []): OrderItemPrintProduction
    {
        $order = $this->createOrder('SP-EXT-' . random_int(1000, 9999));
        $item = $this->createOrderItem($order, ['quantity' => 50]);
        $print = $this->createPrint($order, $item, [
            'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
            'subcontractor_company_id' => $partner->id,
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Logo',
            'print_quantity' => 50,
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();
        $production = $print->fresh('production')->production;
        $this->assertNotNull($production);

        if ($quantityOverrides !== []) {
            $production->forceFill(array_merge([
                'completed_quantity' => (float) ($quantityOverrides['completed_quantity'] ?? $production->completed_quantity),
                'remaining_quantity' => (float) ($quantityOverrides['remaining_quantity'] ?? $production->remaining_quantity),
            ], $quantityOverrides))->save();
        }

        return $production->fresh();
    }

    private function createOrder(string $documentNumber): Order
    {
        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function createOrderItem(Order $order, array $overrides = []): OrderItem
    {
        return OrderItem::query()->create(array_merge([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Dış Üretim Test Ürünü',
            'product_code' => 'PRD-' . random_int(1000, 9999),
            'quantity' => 50,
            'unit' => 'Adet',
            'description' => 'Fason üretim testi',
            'has_print' => true,
            'print_total' => 50,
            'status' => 'pending',
        ], $overrides));
    }

    private function createPrint(Order $order, OrderItem $item, array $overrides = []): OrderItemPrint
    {
        return OrderItemPrint::query()->create(array_merge([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Logo',
            'print_location' => 'Gövde',
            'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
            'subcontractor_company_id' => null,
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'cliche_status' => null,
            'print_quantity' => $item->quantity,
            'print_unit_price' => 5,
            'print_total' => 250,
            'note' => 'Fason testi baskı',
            'production_note' => null,
            'status' => 'draft',
        ], $overrides));
    }

    private function createPartnerCompany(): Company
    {
        return Company::query()
            ->where('status', 'active')
            ->whereKeyNot($this->customer->id)
            ->orderBy('id')
            ->firstOrFail();
    }
}
