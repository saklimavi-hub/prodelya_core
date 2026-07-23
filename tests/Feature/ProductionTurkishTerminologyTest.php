<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionTurkishTerminologyTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_production_tabs_use_turkish_labels_and_hide_broken_terms(): void
    {
        $internalProduction = $this->createInternalProductionForShow();
        $externalProduction = $this->createExternalProductionForShow();

        $legacyInternal = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $internalProduction) . '?tab=ic-uretim');
        $legacyInternal->assertRedirect(route('admin.productions.operator', $internalProduction));

        $internalResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.operator', $internalProduction));

        $internalResponse->assertOk();

        foreach ([
            'Üretim',
            'İç Üretim Operatörü',
            'Sipariş',
            'Operatör',
            'Grafiği Aç',
        ] as $expected) {
            $internalResponse->assertSee($expected);
        }

        $legacyExternal = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $externalProduction) . '?tab=dis-uretim');
        $legacyExternal->assertRedirect(route('admin.productions.subcontract-tracking', $externalProduction));

        $externalResponse = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.subcontract-tracking', $externalProduction));

        $externalResponse->assertOk();
        $externalResponse->assertSee('Fason Takibi');

        $externalHtml = $externalResponse->getContent();

        foreach ([
            'Uretim',
            'Dis Uretim',
            'Ic Uretim',
            'Musteri',
            'Siparis',
            'source_type',
            'current_account_id',
            'tenant_id',
            'meta_json',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $externalHtml);
        }
    }
}
