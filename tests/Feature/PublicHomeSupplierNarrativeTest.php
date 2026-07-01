<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHomeSupplierNarrativeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_public_home_uses_non_technical_product_hub_narrative_with_supplier_examples(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.home'));

        $response->assertOk();
        $response->assertSee('Promosyon ürünleri tedarikçilerinden gelen ürün bilgilerini tek katalogda toplayın.');
        $response->assertSee('Etkin Promosyon');
        $response->assertSee('Akdeniz Promosyon');
        $response->assertSee('İlpen Promosyon');
        $response->assertSee('Yeni Nesil Promosyon');
        $response->assertSee('Pozitron Promosyon');
        $response->assertSee('Elma-Soylu Takvim');
        $response->assertDontSee('group_code');
        $response->assertDontSee('supplier_cost');
        $response->assertDontSee('projection');
        $response->assertDontSee('raw');
        $response->assertDontSee('Super Admin Yönetir');
    }
}
