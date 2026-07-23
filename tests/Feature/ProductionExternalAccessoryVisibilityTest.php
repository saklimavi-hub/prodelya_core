<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintSetupRequirement;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionExternalAccessoryVisibilityTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_canonical_external_tracking_hides_accessory_technical_leaks(): void
    {
        $partner = $this->createPartnerCompany('Klişe Partneri');
        $account = app(CurrentAccountSyncService::class)->ensureForCompany($partner);
        $production = $this->createExternalProductionForShow($partner);

        OrderItemPrintSetupRequirement::query()->create([
            'tenant_account_id' => $production->tenant_account_id,
            'order_id' => $production->order_id,
            'order_item_id' => $production->order_item_id,
            'order_item_print_id' => $production->order_item_print_id,
            'setup_type' => OrderItemPrintSetupRequirement::TYPE_CLICHE,
            'status' => OrderItemPrintSetupRequirement::STATUS_PENDING,
            'assigned_company_id' => $partner->id,
            'assigned_current_account_id' => $account->id,
            'cost' => 800,
            'currency' => 'TRY',
            'note' => 'Klişe üretimi başlatıldı',
        ]);

        $production = $production->fresh(['orderItemPrint.setupRequirements.assignedCompany']);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim')
            ->assertRedirect(route('admin.productions.subcontract-tracking', $production));

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.subcontract-tracking', $production));

        $response->assertOk();
        $response->assertDontSee('Ara Eleman Üretimi');
        $response->assertDontSee('Klişe üretimi başlatıldı');
        $response->assertDontSee('assigned_current_account_id', false);
    }
}
