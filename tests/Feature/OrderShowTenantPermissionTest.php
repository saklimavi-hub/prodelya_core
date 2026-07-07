<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowTenantPermissionTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $foreignTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();

        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Yabancı Sipariş Tenantı',
            'legal_name' => 'Yabancı Sipariş Tenantı Ltd.',
            'slug' => 'yabanci-siparis-tenant',
            'panel_subdomain' => 'yabanci-siparis-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    public function test_other_tenant_order_and_actions_are_forbidden(): void
    {
        $foreignUser = User::query()->create([
            'name' => 'Yabancı Sipariş Kullanıcısı',
            'email' => 'foreign-order-user@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'user_id' => $foreignUser->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->firstOrFail()->id,
        ]);

        $foreignOrder = Order::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'document_number' => 'YAB-ORD-001',
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $foreignUser->id,
        ]);

        $foreignItem = OrderItem::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'order_id' => $foreignOrder->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Yabancı Ürün',
            'product_code' => 'YAB-001',
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

        $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', $foreignOrder))
            ->assertForbidden();

        $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->post(route('admin.orders.delivery-packages.store', $foreignOrder), [
                'packages' => [[
                    'package_label' => 'Yabancı Koli',
                    'package_type' => 'box',
                    'items' => [[
                        'order_item_id' => $foreignItem->id,
                        'quantity' => 1,
                    ]],
                ]],
            ])
            ->assertForbidden();
    }
}
