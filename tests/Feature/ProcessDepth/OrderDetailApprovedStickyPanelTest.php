<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\Order;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDetailApprovedStickyPanelTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpOrderShowFixtures();
    }

    public function test_authorized_user_sees_approved_sticky_layout_cards_markers_and_css_contract(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'standard', 'string');

        $response = $this->showOrderAsAdmin($order);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('pd-order-sticky-layout', false)
            ->assertSee('data-order-sticky-layout="true"', false)
            ->assertSee('data-order-sticky-sidebar="true"', false)
            ->assertSee('data-sticky-sidebar="true"', false)
            ->assertSee('data-sticky-responsive="stack-under-1100"', false)
            ->assertSee('data-sticky-responsive-marker="true"', false)
            ->assertSee('grid-template-columns:minmax(0, 1fr) 330px', false)
            ->assertSee('position:sticky', false)
            ->assertSee('top:18px', false)
            ->assertSee('@media (max-width: 1100px)', false)
            ->assertSee('grid-template-columns:minmax(0, 1fr);', false)
            ->assertSee('position:static', false)
            ->assertSee('Sipariş Akışı')
            ->assertSee('Aktif Odak')
            ->assertSee('Süreç Durumu')
            ->assertSee('Hızlı İşlemler')
            ->assertSee('Finans')
            ->assertSee('Çalışma Şekli')
            ->assertSee('Sipariş Ailesi')
            ->assertSee('Teslim Tarihi');

        $this->assertSame(1, substr_count($content, 'data-order-sticky-layout="true"'));
        $this->assertSame(1, substr_count($content, 'data-order-sticky-sidebar="true"'));
        $this->assertSame(3, substr_count($content, 'pd-order-depth-primary-cta'));
        $this->assertTrue(strpos($content, 'data-order-sticky-layout="true"') < strpos($content, 'class="pd-page-stack pd-order-sticky-main"'));
        $this->assertTrue(strpos($content, 'class="pd-page-stack pd-order-sticky-main"') < strpos($content, 'data-order-sticky-sidebar="true"'));
    }

    public function test_fast_depth_hides_process_status_and_recent_activities_cards(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'fast', 'string');

        $response = $this->showOrderAsAdmin($order);

        $response->assertOk()
            ->assertSee('Hızlı Akış')
            ->assertSee('Aktif Odak')
            ->assertSee('Hızlı İşlemler')
            ->assertDontSee('Süreç Durumu')
            ->assertDontSee('Son Faaliyetler');
    }

    public function test_controlled_depth_uses_turkish_activity_labels_in_recent_activities_card(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'controlled', 'string');

        $response = $this->showOrderAsAdmin($order);

        $response->assertOk()
            ->assertSee('Kontrollü Akış')
            ->assertSee('Son Faaliyetler')
            ->assertSee('Tedarik İhtiyacı Oluşturuldu')
            ->assertSee('Üretim Operasyonu Oluşturuldu')
            ->assertSee('İş Formu Oluşturuldu')
            ->assertDontSee('Procurement Needed')
            ->assertDontSee('Production Operation Created')
            ->assertDontSee('Delivery Record Created')
            ->assertDontSee('Work Form Created');
    }

    public function test_finance_is_hidden_for_operations_user_in_kpis_table_and_sticky_sidebar(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'standard', 'string');
        $graphicUser = $this->createUserWithRole($order->tenant_account_id, 'graphic');

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk()
            ->assertDontSee('Açık Bakiye')
            ->assertDontSee('Kalan Bakiye')
            ->assertDontSee('Finans Özeti')
            ->assertDontSee(route('admin.finance.show', $order), false)
            ->assertDontSee('<th>Tutar</th>', false);
    }

    public function test_graphic_blocker_keeps_canonical_focus_and_primary_ctas_on_graphic_flow(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'controlled', 'string');

        $response = $this->showOrderAsAdmin($order);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Grafik kontrolü bekliyor')
            ->assertSee('Revize veya onay bekleyen grafik işini tamamla')
            ->assertSee('Grafik Detayını Aç')
            ->assertDontSee('Tedariğe Git');

        $this->assertStringContainsString('data-focus-key="graphic_pending"', $content);
        $this->assertSame(3, substr_count($content, 'pd-order-depth-primary-cta'));
    }

    private function showOrderAsAdmin(Order $order)
    {
        return $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', $order));
    }

    private function createUserWithRole(int $tenantId, string $roleKey): User
    {
        $user = User::query()->create([
            'name' => 'Approved Sticky ' . ucfirst($roleKey),
            'email' => 'approved-sticky-' . $roleKey . '-' . $tenantId . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $tenantId,
        ]);

        return $user;
    }
}
