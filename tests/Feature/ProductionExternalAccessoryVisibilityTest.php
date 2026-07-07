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

    public function test_external_tab_shows_accessory_production_without_technical_leaks(): void
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

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=dis-uretim');

        $response->assertOk();
        $response->assertSee('Ara Eleman Üretimi');
        $response->assertSee('Klişe');
        $response->assertSee('Klişe Partneri');
        $response->assertSee('Eşleşme var');
        $response->assertSee('800,00 TRY');
        $response->assertDontSee('assigned_current_account_id', false);
    }
}
