<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\Order;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDetailProcessDepthPilotTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpOrderShowFixtures();
    }

    public function test_fast_depth_renders_compact_focus_without_controlled_details(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'fast', 'string');

        $response = $this->showOrderAsAdmin($order);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Hızlı Akış')
            ->assertSee('data-process-depth="fast"', false)
            ->assertSee('data-depth-branch="fast"', false)
            ->assertSee('data-sticky-layout="true"', false)
            ->assertSee('data-sticky-sidebar="true"', false)
            ->assertSee('data-sticky-responsive-marker="true"', false)
            ->assertSee('Aktif Odak')
            ->assertSee('Hızlı İşlemler')
            ->assertSee('Şu an')
            ->assertSee('Sıradaki işlem')
            ->assertDontSee('Süreç Durumu')
            ->assertDontSee('Son Faaliyetler')
            ->assertDontSee('Kontrol Ayrıntıları');

        $this->assertSame(3, substr_count($content, 'pd-order-depth-primary-cta'));
    }

    public function test_standard_depth_renders_balanced_surface_without_controlled_blocks(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'standard', 'string');

        $response = $this->showOrderAsAdmin($order);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Standart Akış')
            ->assertSee('data-process-depth="standard"', false)
            ->assertSee('data-depth-branch="standard"', false)
            ->assertSee('Süreç Durumu')
            ->assertSee('Aktif Odak')
            ->assertSee('Hızlı İşlemler')
            ->assertDontSee('Son Faaliyetler')
            ->assertDontSee('Kontrol Ayrıntıları');

        $this->assertSame(3, substr_count($content, 'pd-order-depth-primary-cta'));
    }

    public function test_controlled_depth_renders_detailed_operation_and_control_sections(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'controlled', 'string');

        $response = $this->showOrderAsAdmin($order);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Kontrollü Akış')
            ->assertSee('data-process-depth="controlled"', false)
            ->assertSee('data-depth-branch="controlled"', false)
            ->assertSee('Kontrol Ayrıntıları')
            ->assertSee('Süreç Durumu')
            ->assertSee('Son Faaliyetler')
            ->assertSee('Finans Hattı')
            ->assertSee('Tedarik İhtiyacı Oluşturuldu')
            ->assertSee('Üretim Operasyonu Oluşturuldu')
            ->assertSee('İş Formu Oluşturuldu')
            ->assertDontSee('Procurement Needed')
            ->assertDontSee('Production Operation Created')
            ->assertDontSee('Delivery Record Created')
            ->assertDontSee('Work Form Created')
            ->assertDontSee('Ã')
            ->assertDontSee('�');

        $this->assertSame(3, substr_count($content, 'pd-order-depth-primary-cta'));
    }

    public function test_graphic_blocker_focus_stays_graphic_across_all_depths_when_procurement_is_also_waiting(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);

        foreach (['fast', 'standard', 'controlled'] as $depth) {
            TenantSetting::setValue($order->tenant_account_id, 'process_depth', $depth, 'string');

            $response = $this->showOrderAsAdmin($order->fresh());
            $content = $response->getContent();

            $response->assertOk()
                ->assertSee('Grafik kontrolü bekliyor')
                ->assertSee('Revize veya onay bekleyen grafik işini tamamla')
                ->assertSee('Revize veya onay bekleyen grafik işi var.')
                ->assertSee('Grafik Detayını Aç')
                ->assertDontSee('Tedariğe Git');

            $this->assertStringContainsString('data-focus-key="graphic_pending"', $content);
            $this->assertSame(3, substr_count($content, 'pd-order-depth-primary-cta'));
        }
    }

    public function test_procurement_focus_takes_over_after_graphic_blocker_clears(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);

        foreach ($order->fresh('workForms')->workForms as $workForm) {
            $snapshot = is_array($workForm->graphic_snapshot) ? $workForm->graphic_snapshot : [];
            $workForm->forceFill([
                'graphic_snapshot' => array_merge($snapshot, ['status' => 'uretime_hazir']),
            ])->save();
        }

        foreach (['fast', 'standard', 'controlled'] as $depth) {
            TenantSetting::setValue($order->tenant_account_id, 'process_depth', $depth, 'string');

            $response = $this->showOrderAsAdmin($order->fresh());
            $content = $response->getContent();

            $response->assertOk()
                ->assertSee('Tedarik bekliyor')
                ->assertSee('Tedarik bilgilerini tamamla')
                ->assertSee('Tedariğe Git')
                ->assertDontSee('Revize veya onay bekleyen grafik işi var.');

            $this->assertStringContainsString('data-focus-key="procurement_pending"', $content);
            $this->assertStringNotContainsString('data-focus-key="graphic_pending"', $content);
            $this->assertSame(3, substr_count($content, 'pd-order-depth-primary-cta'));
        }
    }

    public function test_each_depth_renders_distinct_dom_markers_and_response_hashes(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);

        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'fast', 'string');
        $fast = $this->showOrderAsAdmin($order)->getContent();

        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'standard', 'string');
        $standard = $this->showOrderAsAdmin($order->fresh())->getContent();

        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'controlled', 'string');
        $controlled = $this->showOrderAsAdmin($order->fresh())->getContent();

        $this->assertStringContainsString('data-depth-branch="fast"', $fast);
        $this->assertStringContainsString('data-depth-branch="standard"', $standard);
        $this->assertStringContainsString('data-depth-branch="controlled"', $controlled);
        $this->assertStringNotContainsString('Süreç Durumu', $fast);
        $this->assertStringContainsString('Süreç Durumu', $standard);
        $this->assertStringContainsString('Son Faaliyetler', $controlled);
        $this->assertNotSame(md5($fast), md5($standard));
        $this->assertNotSame(md5($standard), md5($controlled));
        $this->assertNotSame(md5($fast), md5($controlled));
    }

    public function test_invalid_override_falls_back_to_standard_package_depth(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'broken-depth', 'string');

        $response = $this->showOrderAsAdmin($order);

        $response->assertOk()
            ->assertSee('Standart Akış')
            ->assertSee('data-process-depth="standard"', false)
            ->assertSee('data-depth-branch="standard"', false)
            ->assertSee('Süreç Durumu')
            ->assertDontSee('Hızlı Akış')
            ->assertDontSee('Kontrollü Akış')
            ->assertDontSee('Son Faaliyetler');
    }

    public function test_each_depth_keeps_single_canonical_focus_panel_and_single_focus_label_set(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);

        foreach (['fast', 'standard', 'controlled'] as $depth) {
            TenantSetting::setValue($order->tenant_account_id, 'process_depth', $depth, 'string');

            $content = $this->showOrderAsAdmin($order->fresh())->getContent();

            $this->assertSame(1, substr_count($content, 'data-canonical-focus-panel="true"'));
            $this->assertSame(3, substr_count($content, 'pd-order-depth-primary-cta'));
            $this->assertSame(1, substr_count($content, '>Şu an</span><strong>'));
            $this->assertSame(1, substr_count($content, '>Sıradaki işlem</span><strong>'));
            $this->assertSame(1, substr_count($content, '>Engel</span><strong>'));
            $this->assertStringContainsString('data-sticky-sidebar="true"', $content);
            $this->assertStringNotContainsString('Kısa Özet', $content);
        }
    }

    public function test_process_depth_does_not_expose_finance_panel_to_operations_user(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'controlled', 'string');
        $graphicUser = $this->createUserWithRole($order->tenant_account_id, 'graphic');

        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk()
            ->assertSee('Kontrollü Akış')
            ->assertSee('Kontrol Ayrıntıları')
            ->assertDontSee('Açık Bakiye')
            ->assertDontSee('Kalan Bakiye')
            ->assertDontSee('Finans Özeti')
            ->assertDontSee(route('admin.finance.show', $order), false)
            ->assertDontSee('<th>Tutar</th>', false)
            ->assertDontSee('Tahsilat');
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
            'name' => 'Process Depth ' . ucfirst($roleKey),
            'email' => 'process-depth-' . $roleKey . '-' . $tenantId . '@prodelya.local',
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

