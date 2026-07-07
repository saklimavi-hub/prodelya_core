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

        $internalResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $internalProduction) . '?tab=ic-uretim');

        $internalResponse->assertOk();

        foreach ([
            'Üretim',
            'İç Üretim',
            'Müşteri',
            'Sipariş',
            'İşlem',
            'Kısmi Üretildi',
            'Tamamlandı',
        ] as $expected) {
            $internalResponse->assertSee($expected);
        }

        $externalResponse = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $externalProduction) . '?tab=dis-uretim');

        $externalResponse->assertOk();
        $externalResponse->assertSee('Dış Üretim / Fason');

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
            $externalResponse->assertDontSee($forbidden, false);
        }
    }
}

