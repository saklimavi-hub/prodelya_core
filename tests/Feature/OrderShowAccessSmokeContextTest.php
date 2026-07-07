<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowAccessSmokeContextTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_correct_tenant_user_can_open_order_show_and_other_tenant_gets_403(): void
    {
        $order = $this->createConvertedOrderForShow();
        $tenantHost = $this->tenantHostForOrder($order);

        $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => $tenantHost])
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee($order->document_number);

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Sipariş Dışı Tenant',
            'legal_name' => 'Sipariş Dışı Tenant Ltd. Şti.',
            'slug' => 'siparis-disi-tenant',
            'panel_subdomain' => 'siparis-disi-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $otherUser = User::query()->create([
            'name' => 'Yanlış Tenant Kullanıcısı',
            'email' => 'wrong-tenant-order-show@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'user_id' => $otherUser->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->firstOrFail()->id,
        ]);

        $this->actingAs($otherUser)
            ->withServerVariables(['HTTP_HOST' => $tenantHost])
            ->get(route('admin.orders.show', $order))
            ->assertForbidden();
    }

    public function test_order_show_stays_open_when_delivery_planning_tables_are_missing(): void
    {
        $order = $this->createConvertedOrderForShow();

        Schema::drop('order_delivery_package_items');
        Schema::drop('order_delivery_label_batches');
        Schema::drop('order_delivery_packages');

        $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHostForOrder($order)])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']))
            ->assertOk()
            ->assertSee('Teslimat')
            ->assertSee('Bu ortamda koli planı ve etiket kayıtları henüz hazır değil.');
    }

    private function tenantHostForOrder(Order $order): string
    {
        $tenant = TenantAccount::query()->findOrFail($order->tenant_account_id);

        return $tenant->panel_subdomain . '.prodelya_core.test';
    }
}
