<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CurrentAccountTransaction;
use App\Models\OrderItemPrintProduction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionSubcontractorAssignmentFlowTest extends TestCase
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

    public function test_subcontract_assignment_page_lists_only_active_tenant_fason_companies_and_rejects_internal_jobs(): void
    {
        $allowedPrintCompany = $this->createRoleCompany('M13D Fason Baskı', 'print_fason', 'active');
        $allowedProductionCompany = $this->createRoleCompany('M13D Fason Üretim', 'production_partner', 'active');
        $this->createRoleCompany('M13D Pasif Fason', 'print_fason', 'blocked');
        $plainCompany = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'M13D Düz Cari',
            'status' => 'active',
        ]);
        $foreignCompany = $this->createForeignRoleCompany('M13D Foreign Fason');

        $production = $this->createExternalProductionForShow(null, [
            'production_company_id' => null,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'started_at' => null,
            'sent_to_subcontractor_at' => null,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'subcontractor_cost' => null,
            'subcontractor_cost_currency' => null,
            'subcontractor_cost_note' => null,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-assignment'));

        $response->assertOk()
            ->assertSee('Üretim / Fason · Atama')
            ->assertSee('Üretim Yolu: Dış Baskı / Fason')
            ->assertSee('pd-subcontract-assignment__compact-header', false)
            ->assertSee('pd-subcontract-assignment__metrics-line', false)
            ->assertSee('pd-subcontract-assignment__company-row', false)
            ->assertSee('Seçilen Firmaya Ata')
            ->assertSee('pd-subcontract-assignment__sticky-action', false)
            ->assertSee('pd-subcontract-assignment__submit', false)
            ->assertSee($allowedPrintCompany->legal_name)
            ->assertSee($allowedProductionCompany->legal_name)
            ->assertDontSee('M13D Pasif Fason')
            ->assertDontSee($plainCompany->legal_name)
            ->assertDontSee($foreignCompany->legal_name)
            ->assertDontSee('subcontractor_cost', false)
            ->assertDontSee('Maliyet')
            ->assertDontSee('Ödeme');

        $internal = $this->createInternalProductionForShow();

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $internal->id . '/subcontract-assignment'))
            ->assertNotFound();
    }

    public function test_assignment_updates_existing_exact_production_without_current_account_transaction(): void
    {
        $partner = $this->createPartnerCompany('M13D İlk Fason');
        $production = $this->createExternalProductionForShow(null, [
            'production_company_id' => null,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'started_at' => null,
            'sent_to_subcontractor_at' => null,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'subcontractor_cost' => null,
        ]);
        $printId = $production->order_item_print_id;
        $accountCount = CurrentAccountTransaction::query()->count();

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/assignment'), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
                'production_unit_name' => '',
                'assigned_to' => '',
                'cliche_required' => '0',
                'return_to' => 'subcontract_assignment',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-assignment'));

        $fresh = $production->fresh();
        $this->assertSame($production->id, $fresh->id);
        $this->assertSame($printId, $fresh->order_item_print_id);
        $this->assertSame($partner->id, $fresh->production_company_id);
        $this->assertSame(OrderItemPrintProduction::STATUS_PENDING, $fresh->production_status);
        $this->assertSame($accountCount, CurrentAccountTransaction::query()->count());
    }

    public function test_send_to_subcontractor_requires_company_and_readiness_then_uses_existing_row(): void
    {
        $partner = $this->createPartnerCompany('M13D Gönderim Fason');
        $production = $this->createExternalProductionForShow(null, [
            'production_company_id' => null,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'started_at' => null,
            'sent_to_subcontractor_at' => null,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
        ]);
        $production = $this->prepareProductionForReadyState($production);
        $printId = $production->order_item_print_id;

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'sent_to_subcontractor',
                'return_to' => 'subcontract_assignment',
            ])
            ->assertSessionHasErrors('action');

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/assignment'), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
                'production_unit_name' => '',
                'assigned_to' => '',
                'cliche_required' => '0',
                'return_to' => 'subcontract_assignment',
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/status'), [
                'action' => 'sent_to_subcontractor',
                'return_to' => 'subcontract_assignment',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'));

        $fresh = $production->fresh();
        $this->assertSame($printId, $fresh->order_item_print_id);
        $this->assertSame(OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR, $fresh->production_status);
        $this->assertNotNull($fresh->sent_to_subcontractor_at);
    }

    public function test_reassignment_requires_reason_preserves_partial_quantities_and_terminal_jobs_are_blocked(): void
    {
        $first = $this->createPartnerCompany('M13D Eski Fason');
        $second = $this->createPartnerCompany('M13D Yeni Fason');
        $production = $this->createExternalProductionForShow($first, [
            'production_status' => OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            'completed_quantity' => 35,
            'remaining_quantity' => 65,
            'started_at' => now()->subHour(),
        ]);

        $payload = [
            'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
            'production_company_id' => $second->id,
            'production_unit_name' => '',
            'assigned_to' => '',
            'cliche_required' => '0',
            'return_to' => 'subcontract_assignment',
        ];

        $this->actingAs($this->adminUser, 'web')
            ->from($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-assignment'))
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/assignment'), $payload)
            ->assertSessionHasErrors('production_note');

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/assignment'), $payload + [
                'production_note' => 'Kapasite nedeniyle firma değişti',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-tracking'));

        $fresh = $production->fresh(['workForm.activityLogs']);
        $this->assertSame($second->id, $fresh->production_company_id);
        $this->assertSame(35.0, (float) $fresh->completed_quantity);
        $this->assertSame(65.0, (float) $fresh->remaining_quantity);
        $this->assertTrue($fresh->workForm->activityLogs->contains('action_type', 'production_subcontractor_reassigned'));

        $terminal = $this->createExternalProductionForShow($first, [
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'completed_quantity' => 100,
            'remaining_quantity' => 0,
        ]);

        $this->actingAs($this->adminUser, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $terminal->id . '/assignment'), array_merge($payload, [
                'production_note' => 'Terminal değişiklik denemesi',
                'return_to' => 'show',
            ]))
            ->assertSessionHasErrors('production_note');
    }

    public function test_compact_assignment_surface_uses_single_primary_action_and_no_right_summary(): void
    {
        $partner = $this->createPartnerCompany('M13D Compact Hazır Fason');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'sent_to_subcontractor_at' => null,
            'subcontractor_cost' => null,
        ]);
        $production = $this->prepareProductionForReadyState($production);

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-assignment'))
            ->assertOk()
            ->assertSee('Atanan firma')
            ->assertSee('Fasona Gönder')
            ->assertSee('Firmayı Değiştir')
            ->assertSee('İş Detaylarını Göster')
            ->assertDontSee('Kısa Özet')
            ->assertDontSee('Exact baskı işi')
            ->assertDontSee('Hazırlık durumu');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'pd-subcontract-assignment__btn pd-subcontract-assignment__btn--primary'));
        $this->assertStringNotContainsString('pd-subcontract-assignment__aside', $html);
    }

    public function test_compact_assignment_sent_state_shows_tracking_only_without_send_or_company_list(): void
    {
        $partner = $this->createPartnerCompany('M13D Compact Gönderildi');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'sent_to_subcontractor_at' => now(),
            'subcontractor_cost' => null,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-assignment'))
            ->assertOk()
            ->assertSee('Fason Takibi Aç')
            ->assertDontSee('>Fasona Gönder<', false)
            ->assertDontSee('>Fasona Ata<', false)
            ->assertDontSee('Fason firma seçimi')
            ->assertDontSee('pd-subcontract-assignment__company-row', false);

        $this->assertSame(1, substr_count($response->getContent(), 'Fason Takibi Aç'));
    }

    public function test_compact_assignment_completed_state_is_read_only_with_single_record_cta(): void
    {
        $partner = $this->createPartnerCompany('M13D Compact Tamamlandı');
        $production = $this->createExternalProductionForShow($partner, [
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'completed_quantity' => 100,
            'remaining_quantity' => 0,
            'sent_to_subcontractor_at' => now()->subHour(),
            'subcontractor_cost' => null,
        ]);

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-assignment'))
            ->assertOk()
            ->assertSee('Fason işi tamamlandı')
            ->assertSee('Kaydı Aç')
            ->assertDontSee('>Fasona Gönder<', false)
            ->assertDontSee('>Fasona Ata<', false)
            ->assertDontSee('Firmayı Değiştir')
            ->assertDontSee('pd-subcontract-assignment__company-row', false);
    }

    public function test_subcontract_assignment_has_only_one_assign_button_and_disabled_without_company(): void
    {
        $this->createPartnerCompany('M13F1 Görünür Fason');
        $production = $this->createExternalProductionForShow(null, [
            'production_company_id' => null,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
            'subcontractor_cost' => null,
        ]);

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-assignment'))
            ->assertOk()
            ->assertSee('Seçilen Firmaya Ata')
            ->assertSee('Firma seçilmedi')
            ->assertSee('pd-subcontract-assignment__sticky-action', false)
            ->assertSee('pd-subcontract-assignment__selected-company', false)
            ->assertDontSee('>Fasona Ata<', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'Seçilen Firmaya Ata'));
        $this->assertMatchesRegularExpression('/pd-subcontract-assignment__submit[^>]*disabled/', $html);
    }

    public function test_production_detail_internal_shows_transfer_link_to_operator_panel(): void
    {
        $production = $this->createInternalProductionForShow([
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'completed_quantity' => 0,
            'remaining_quantity' => 100,
        ]);

        $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id))
            ->assertOk()
            ->assertSee('Fasona Devret')
            ->assertSee(route('admin.productions.operator', $production) . '#route-transfer-panel', false);
    }
    private function createRoleCompany(string $legalName, string $roleKey, string $status): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => $legalName,
            'status' => $status,
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => $roleKey,
        ]);

        return $company->fresh('companyRoles');
    }

    private function createForeignRoleCompany(string $legalName): Company
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'M13D Foreign Tenant',
            'legal_name' => 'M13D Foreign Tenant Ltd.',
            'slug' => 'm13d-foreign-tenant',
            'panel_subdomain' => 'm13d-foreign-tenant',
            'status' => 'active',
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => $legalName,
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'role_key' => 'print_fason',
        ]);

        return $company;
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::PRODUCTION_SHOW_HOST . $path;
    }
}
