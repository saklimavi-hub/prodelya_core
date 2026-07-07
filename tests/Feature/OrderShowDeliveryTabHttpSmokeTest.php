<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowDeliveryTabHttpSmokeTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_delivery_tab_opens_and_hides_sensitive_fields(): void
    {
        $order = $this->createConvertedOrderForShow();

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHostForOrder($order)])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']));

        $response->assertOk()
            ->assertSee('Teslimata Hazırla')
            ->assertSee('Koli Planı')
            ->assertSee('Etiket Oluştur')
            ->assertSee('Teslim Bilgisi')
            ->assertSee('Teslim Edildi')
            ->assertSee('A4 1/4')
            ->assertSee('A4 1/2')
            ->assertSee('A4 1/1')
            ->assertSee('Rulo Etiket')
            ->assertSee('Kargo')
            ->assertSee('Müşteri Kendisi Alacak')
            ->assertSee('Ambar')
            ->assertSee('Kurye')
            ->assertSee('Elden Teslim')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('profit')
            ->assertDontSee('current_account_id');
    }

    private function tenantHostForOrder(Order $order): string
    {
        $tenant = TenantAccount::query()->findOrFail($order->tenant_account_id);

        return $tenant->panel_subdomain . '.prodelya_core.test';
    }
}
