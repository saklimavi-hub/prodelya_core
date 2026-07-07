<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\TenantAccount;
use App\Models\TenantDeliveryType;
use App\Models\User;
use App\Services\TenantDeliveryTypeService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryScreenDeliveryTypeUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()->where('tenant_account_id', $this->tenant->id)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
    }

    public function test_delivery_screen_shows_and_updates_commercial_delivery_type(): void
    {
        $types = app(TenantDeliveryTypeService::class)->ensureDefaultsForTenant($this->tenant);
        $first = $types->firstWhere('name', 'Ofis Teslim');
        $second = $types->firstWhere('name', 'Kurye');
        $delivery = $this->createDeliveryRecord($first);

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $show->assertOk()
            ->assertSee('Ticari teslimat tipi')
            ->assertSee('Ofis Teslim');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-details', $delivery), [
                'delivery_type_id' => $second?->id,
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_COURIER,
                'recipient_name' => 'Kurye Test',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh(['order', 'workForm']);

        $this->assertSame($second?->id, $delivery->order?->delivery_type_id);
        $this->assertSame('Kurye', $delivery->order?->delivery_type);
        $this->assertSame('Kurye', data_get($delivery->workForm?->delivery_snapshot, 'delivery_type'));
    }

    public function test_delivery_screen_blocks_foreign_delivery_type_id(): void
    {
        $types = app(TenantDeliveryTypeService::class)->ensureDefaultsForTenant($this->tenant);
        $delivery = $this->createDeliveryRecord($types->firstWhere('name', 'Ofis Teslim'));

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Foreign Delivery Screen Tenant',
            'legal_name' => 'Foreign Delivery Screen Tenant Ltd.',
            'slug' => 'foreign-delivery-screen-tenant',
            'panel_subdomain' => 'foreign-delivery-screen-tenant',
            'status' => 'active',
        ]);

        $foreignType = TenantDeliveryType::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'name' => 'Foreign Type',
            'code' => 'foreign-type',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.deliveries.show', $delivery))
            ->patch(route('admin.deliveries.update-details', $delivery), [
                'delivery_type_id' => $foreignType->id,
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
            ])
            ->assertSessionHasErrors('delivery_type_id');
    }

    private function createDeliveryRecord(?TenantDeliveryType $type): OrderItemWorkFormDelivery
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-DTYPE-' . random_int(1000, 9999),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fis',
            'delivery_type' => $type?->name,
            'delivery_type_id' => $type?->id,
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Delivery Type Item',
            'product_code' => 'DT-ITEM-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'line_total' => 100,
            'unit_price' => 10,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
            'product_snapshot' => ['warning_badges' => []],
            'price_snapshot' => ['vat_mode' => 'none', 'vat_rate' => 0],
            'stock_snapshot' => ['supplier_stock_quantity' => 100],
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return OrderItemWorkFormDelivery::query()->latest('id')->firstOrFail();
    }
}
