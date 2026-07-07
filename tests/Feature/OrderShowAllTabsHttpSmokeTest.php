<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowAllTabsHttpSmokeTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_all_supported_tabs_open_and_invalid_tab_falls_back_to_general_summary(): void
    {
        $order = $this->createConvertedOrderForShow();
        $host = $this->tenantHostForOrder($order);

        foreach (['genel', 'is-formu', 'grafik', 'tedarik', 'uretim', 'teslimat', 'finans', 'gecmis'] as $tab) {
            $this->actingAs($this->orderShowAdminUser)
                ->withServerVariables(['HTTP_HOST' => $host])
                ->get(route('admin.orders.show', ['order' => $order, 'tab' => $tab]))
                ->assertOk();
        }

        $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'yanlis']))
            ->assertOk()
            ->assertSee('Genel Özet');
    }

    private function tenantHostForOrder(Order $order): string
    {
        $tenant = TenantAccount::query()->findOrFail($order->tenant_account_id);

        return $tenant->panel_subdomain . '.prodelya_core.test';
    }
}
