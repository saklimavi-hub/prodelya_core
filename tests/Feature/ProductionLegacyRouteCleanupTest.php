<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\OrderItemPrintProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionLegacyRouteCleanupTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::PRODUCTION_SHOW_HOST]);
        $this->setUpProductionShowFixtures();
    }

    public function test_operator_select_stays_on_operator_screen_and_assignment_redirects_back(): void
    {
        $production = $this->createInternalProductionForShow([
            'assigned_to' => null,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
        ]);
        $production = $this->prepareProductionForReadyState($production);
        $production->forceFill(['assigned_to' => null])->save();

        $operator = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/operator'));

        $operator->assertOk();
        $operator->assertSee('operator-assignment-panel', false);
        $operator->assertSee('name="return_to" value="operator"', false);
        $operator->assertDontSee('?tab=islemler', false);
        $operator->assertDontSee('atama-guncelle', false);

        $response = $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/assignment'), [
                'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
                'assigned_to' => $this->adminUser->id,
                'production_unit_name' => 'UV İç Hat',
                'cliche_required' => '0',
                'cliche_status' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
                'return_to' => 'operator',
            ]);

        $response->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id . '/operator'));

        $after = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/operator'));

        $after->assertOk();
        $after->assertSee($this->adminUser->name);
        $after->assertSee('Üretime Başla');
    }

    public function test_legacy_tabs_redirect_to_canonical_surfaces(): void
    {
        $internal = $this->createInternalProductionForShow();
        $unsentExternal = $this->createExternalProductionForShow(null, [
            'production_company_id' => null,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'sent_to_subcontractor_at' => null,
        ]);
        $partner = $this->createRoleCompany('M13C3 Fason', 'print_fason', 'active');
        $sentExternal = $this->createExternalProductionForShow($partner, [
            'production_company_id' => $partner->id,
            'production_status' => OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
            'sent_to_subcontractor_at' => now(),
        ]);
        $completed = $this->createInternalProductionForShow([
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'completed_quantity' => 100,
            'remaining_quantity' => 0,
            'completed_at' => now(),
        ]);

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $internal->id . '?tab=islemler'))
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $internal->id . '/operator'));

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $unsentExternal->id . '?tab=dis-uretim'))
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $unsentExternal->id . '/subcontract-assignment'));

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $sentExternal->id . '?tab=islemler'))
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $sentExternal->id . '/subcontract-tracking'));

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $completed->id . '?tab=ic-uretim'))
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $completed->id));
    }

    public function test_production_detail_is_read_only_summary_with_canonical_action(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow());

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id));

        $response->assertOk();
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('Sıradaki İşlem');
        $response->assertSee('Fotoğraflar');
        $response->assertSee('Geçmiş');
        $response->assertSee('Operatör Ekranını Aç');
        $response->assertDontSee('İç Üretim</a>', false);
        $response->assertDontSee('Dış Üretim / Fason</a>', false);
        $response->assertDontSee('İşlemler</a>', false);
        $response->assertDontSee('admin.productions.partials._production_actions', false);
        $response->assertDontSee('?tab=islemler', false);
        $response->assertDontSee('Durumu Güncelle');
        $response->assertDontSee('Atama Güncelle');
    }

    public function test_pool_has_no_legacy_show_tab_links_for_issue_or_qc_rows(): void
    {
        $this->createInternalProductionForShow([
            'production_status' => OrderItemPrintProduction::STATUS_PROBLEMATIC,
            'issue_note' => 'Kontrol gerektirir.',
        ]);
        $this->createInternalProductionForShow([
            'production_status' => OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions?route=internal'));

        $response->assertOk();
        $response->assertDontSee('?tab=islemler', false);
        $response->assertDontSee('?tab=ic-uretim', false);
        $response->assertDontSee('?tab=dis-uretim', false);
        $response->assertSee('/operator', false);
    }

    public function test_return_target_rejects_arbitrary_urls(): void
    {
        $production = $this->createInternalProductionForShow();

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/assignment'), [
                'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
                'assigned_to' => $this->adminUser->id,
                'cliche_required' => '0',
                'cliche_status' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
                'return_to' => 'https://evil.example/steal',
            ])
            ->assertSessionHasErrors('return_to');
    }

    public function test_route_transfer_redirects_to_canonical_surface(): void
    {
        $partner = $this->createRoleCompany('M13C3 Rota Fason', 'print_fason', 'active');
        $internal = $this->createInternalProductionForShow([
            'production_status' => OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            'completed_quantity' => 10,
            'remaining_quantity' => 90,
        ]);

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $internal->id . '/assignment'), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
                'route_change_reason' => 'Kapasite nedeniyle fasona alındı.',
                'cliche_required' => '0',
                'cliche_status' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
                'return_to' => 'operator',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $internal->id . '/subcontract-assignment'));

        $external = $internal->fresh();
        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $external->production_type);

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $external->id . '/assignment'), [
                'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
                'assigned_to' => $this->adminUser->id,
                'production_unit_name' => 'UV İç Hat',
                'route_change_reason' => 'İş tekrar iç üretime alındı.',
                'cliche_required' => '0',
                'cliche_status' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
                'return_to' => 'subcontract_assignment',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $external->id . '/operator'));
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . self::PRODUCTION_SHOW_HOST . $path;
    }
    private function createRoleCompany(string $name, string $role, string $status): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => $name,
            'status' => $status,
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => $role,
        ]);

        return $company;
    }
}
