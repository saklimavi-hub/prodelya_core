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
use Tests\Feature\Concerns\BuildsCurrentAccountListFixtures;
use Tests\TestCase;

class ProductionFinancePermissionTest extends TestCase
{
    use BuildsCurrentAccountListFixtures;
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

    public function test_subcontractor_cost_field_visible_only_with_financial_permission(): void
    {
        $partner = $this->createPartnerCompany();
        $production = $this->createSubcontractedProduction($partner, [
            'subcontractor_cost' => 1250,
            'subcontractor_cost_currency' => 'TRY',
        ]);

        $financeUser = $this->makeFinanceUser($this->tenant, 'production-finance-view@example.test');
        $limitedUser = $this->makeLimitedUser($this->tenant, 'production-limited-view@example.test');

        $financeResponse = $this->actingAs($financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');

        $financeResponse->assertOk();
        $financeResponse->assertSee('Fason Maliyeti');
        $financeResponse->assertSee('1.250,00');

        $limitedResponse = $this->actingAs($limitedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');

        $limitedResponse->assertOk();
        $limitedResponse->assertDontSee('Fason Maliyeti');
        $limitedResponse->assertDontSee('1.250,00');
    }

    private function createSubcontractedProduction(Company $partner, array $productionOverrides = []): OrderItemPrintProduction
    {
        $order = $this->createOrder('SP-FIN-' . random_int(1000, 9999));
        $item = $this->createOrderItem($order, ['quantity' => 80]);
        $print = $this->createPrint($order, $item, [
            'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
            'subcontractor_company_id' => $partner->id,
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Etiket',
            'print_quantity' => 80,
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();
        $production = $print->fresh('production')->production;
        $this->assertNotNull($production);

        $production->forceFill(array_merge([
            'subcontractor_cost' => $productionOverrides['subcontractor_cost'] ?? null,
            'subcontractor_cost_currency' => $productionOverrides['subcontractor_cost_currency'] ?? 'TRY',
        ], $productionOverrides))->save();

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
            'product_name' => 'Finans Test Ürünü',
            'product_code' => 'PRD-' . random_int(1000, 9999),
            'quantity' => 80,
            'unit' => 'Adet',
            'description' => 'Fason maliyet finans testi',
            'has_print' => true,
            'print_total' => 80,
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
            'print_option' => 'Etiket',
            'print_location' => 'Gövde',
            'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
            'subcontractor_company_id' => null,
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'cliche_status' => null,
            'print_quantity' => $item->quantity,
            'print_unit_price' => 5,
            'print_total' => 400,
            'note' => 'Fason finans testi',
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
