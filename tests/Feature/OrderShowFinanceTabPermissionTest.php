<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowFinanceTabPermissionTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_finance_tab_respects_financial_permission(): void
    {
        $order = $this->createConvertedOrderForShow();

        $allowed = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'finans']));

        $allowed->assertOk()->assertSee('Müşteri Borcu')->assertSee('Kalan Bakiye');

        $graphicUser = User::query()->create([
            'name' => 'Order Finance Hidden',
            'email' => 'order-finance-hidden@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $graphicRole = Role::query()->where('key', 'graphic')->firstOrFail();
        $graphicUser->userRoles()->create([
            'role_id' => $graphicRole->id,
            'tenant_account_id' => $this->orderShowCustomer->tenant_account_id,
        ]);

        $hidden = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'finans']));

        $hidden->assertOk()
            ->assertSee('Finans tutarları yalnız yetkili kullanıcıya gösterilir.')
            ->assertDontSee('Müşteri Borcu')
            ->assertDontSee(route('admin.finance.show', $order), false);
    }
}
