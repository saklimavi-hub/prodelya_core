<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Priority-1 binding: OrderController's functionally-active write endpoints
 * (delivery packages/labels/info/complete, convert-from-quote) were
 * previously reachable by any tenant user. This proves edit_orders and
 * convert_quote_to_order are now enforced. (store/update/destroy/updateStatus
 * are still stubs per the audit report and are intentionally left untouched.)
 */
class OrderWritePermissionBindingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $tenantOwner;
    private User $adminUser;
    private User $productionUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill(['package_key' => 'enterprise'])->save();

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $this->tenant->id, 'module_key' => 'order_flow', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $this->tenantOwner = $this->makeUser('tenant_owner', 'owner-order-perm@example.test');
        $this->adminUser = $this->makeUser('admin', 'admin-order-perm@example.test');
        // production has view_orders only - no edit_orders/convert_quote_to_order in config.
        $this->productionUser = $this->makeUser('production', 'production-order-perm@example.test');
    }

    public function test_delivery_packages_store_requires_edit_orders_permission(): void
    {
        [$order, $item] = $this->makeOrderWithItem();
        $payload = [
            'packages' => [[
                'package_label' => 'Koli 1',
                'package_type' => 'box',
                'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
            ]],
        ];

        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-packages'), $payload)
            ->assertForbidden();

        $this->assertNotForbidden(
            $this->actingAs($this->adminUser, 'web')
                ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-packages'), $payload)
        );

        $this->assertNotForbidden(
            $this->actingAs($this->tenantOwner, 'web')
                ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-packages'), $payload)
        );
    }

    public function test_delivery_labels_store_requires_edit_orders_permission(): void
    {
        [$order] = $this->makeOrderWithItem();

        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-labels'), ['template_type' => 'default'])
            ->assertForbidden();

        $this->assertNotForbidden(
            $this->actingAs($this->adminUser, 'web')
                ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-labels'), ['template_type' => 'default'])
        );
    }

    public function test_delivery_info_update_requires_edit_orders_permission(): void
    {
        [$order] = $this->makeOrderWithItem();

        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-info'), ['recipient_name' => 'Test'])
            ->assertForbidden();

        $this->assertNotForbidden(
            $this->actingAs($this->adminUser, 'web')
                ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-info'), ['recipient_name' => 'Test'])
        );
    }

    public function test_delivery_complete_requires_edit_orders_permission(): void
    {
        [$order] = $this->makeOrderWithItem();

        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-complete'), [])
            ->assertForbidden();

        $this->assertNotForbidden(
            $this->actingAs($this->tenantOwner, 'web')
                ->post($this->tenantUrl('/admin/orders/' . $order->id . '/delivery-complete'), [])
        );
    }

    public function test_convert_from_quote_requires_convert_quote_to_order_permission(): void
    {
        $quote = $this->makeQuoteWithItem();

        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/orders/convert/' . $quote->id))
            ->assertForbidden();

        $this->assertNotForbidden(
            $this->actingAs($this->adminUser, 'web')
                ->post($this->tenantUrl('/admin/orders/convert/' . $quote->id))
        );
    }

    private function assertNotForbidden(TestResponse $response): void
    {
        $this->assertNotSame(403, $response->getStatusCode(), 'Expected an authorized role to not receive a 403 permission-denied response.');
    }

    private function makeUser(string $roleKey, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
        ]);

        return $user;
    }

    /**
     * @return array{0: Order, 1: OrderItem}
     */
    private function makeOrderWithItem(): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'document_number' => 'PERM-ORD-' . uniqid(),
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Perm Binding Test Item',
            'product_code' => 'PERM-001',
            'quantity' => 5,
            'unit' => 'Adet',
            'list_price' => 5,
            'discount_rate' => 0,
            'unit_price' => 5,
            'line_total' => 25,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return [$order, $item];
    }

    private function makeQuoteWithItem(): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'document_number' => 'PERM-QUO-' . uniqid(),
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'status' => 'pending',
            'workflow_status' => 'quote_draft',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'customer_company_id' => null,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Perm Binding Quote Item',
            'product_code' => 'PERM-Q-001',
            'quantity' => 5,
            'unit' => 'Adet',
            'list_price' => 5,
            'discount_rate' => 0,
            'unit_price' => 5,
            'line_total' => 25,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $quote;
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
