<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowFinancePermissionDoesNotBlockPageTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_finance_permission_hides_amounts_without_blocking_page(): void
    {
        $order = $this->createConvertedOrderForShow();
        $graphicUser = User::query()->create([
            'name' => 'Sipariş Grafik Kullanıcısı',
            'email' => 'order-graphic-no-finance@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $graphicUser->userRoles()->create([
            'role_id' => Role::query()->where('key', 'graphic')->firstOrFail()->id,
            'tenant_account_id' => $this->orderShowCustomer->tenant_account_id,
        ]);

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHostForOrder($order)])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'finans']));

        $response->assertOk()
            ->assertSee('Finans tutarları yalnız yetkili kullanıcıya gösterilir.')
            ->assertDontSee('Müşteri Borcu')
            ->assertDontSee('Tahsil Edilen')
            ->assertDontSee('Kalan Bakiye');
    }

    private function tenantHostForOrder(Order $order): string
    {
        $tenant = TenantAccount::query()->findOrFail($order->tenant_account_id);

        return $tenant->panel_subdomain . '.prodelya_core.test';
    }
}
