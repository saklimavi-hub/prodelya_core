<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHomeInformationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_public_home_promotes_trial_demo_and_support_ctas_in_expected_hierarchy(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.home'));

        $response->assertOk();
        $response->assertSee('Promosyon, baskı ve sipariş operasyonlarını tek panelden yönetin.');
        $response->assertSee('1 Ay Ücretsiz Dene');
        $response->assertSee('Demo Talep Et');
        $response->assertSee('Paketleri İncele');
        $response->assertSee('Özellikleri Gör');
        $response->assertSee('Abone Firma Girişi');
        $response->assertSee('Ücretsiz deneme talebinde ödeme alınmaz');
        $response->assertSee('Kredi kartı gerekmez');
        $response->assertDontSee(route('customer.login'), false);
        $response->assertDontSee('Sonraki Faz');
    }

    public function test_public_home_explains_modules_without_promoting_customer_portal_as_primary_entry(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.home'));

        $response->assertOk();
        $response->assertSee('Promosyon Teklifleri');
        $response->assertSee('Sipariş Yönetimi');
        $response->assertSee('Product Data Hub');
        $response->assertSee('Grafik');
        $response->assertSee('Tedarik');
        $response->assertSee('Üretim');
        $response->assertSee('Teslimat ve İş Formu');
        $response->assertSee('Bildirim Merkezi');
        $response->assertSee('Talep Merkezi');
        $response->assertSee('Müşteri Portalı ana giriş değildir');
    }

    public function test_public_home_cleans_up_public_facing_terminology_and_placeholder_legal_copy(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.home'));

        $response->assertOk();
        $response->assertSee('Mevcut paket kayıtlarıyla uyumlu');
        $response->assertSee('Yasal sayfalar yakında yayımlanacaktır.');
        $response->assertDontSee('package kayıtlarıyla uyumlu');
        $response->assertDontSee('Tenant');
        $response->assertDontSee('XML/API/CSV');
        $response->assertDontSee('Public teklif onayı');
        $response->assertDontSee('Grafik preview');
        $response->assertDontSee('Super Admin Yönetir');
    }
}
