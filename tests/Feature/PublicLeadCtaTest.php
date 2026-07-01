<?php

namespace Tests\Feature;

use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLeadCtaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_trial_and_demo_pages_keep_public_lead_messaging_clear(): void
    {
        $trial = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.register-interest'));

        $trial->assertOk();
        $trial->assertSee('ödeme alınmaz');
        $trial->assertSee('Kredi kartı gerekmez');
        $trial->assertSee(route('marketing.demo-request'), false);
        $trial->assertDontSee('XML/API/CSV');
        $trial->assertDontSee('projection');
        $trial->assertDontSee('Tenant API');
        $trial->assertDontSee('public müşteri onay');
        $trial->assertDontSee('preview');

        $demo = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.demo-request'));

        $demo->assertOk();
        $demo->assertSee('Demo ücretsiz bir tanıtım görüşmesidir');
        $demo->assertSee('Promosyon teklif ve sipariş akışı');
        $demo->assertSee('Özel kurulum / kendi sunucuma kurulum görüşmesi');
        $demo->assertSee(route('marketing.register-interest'), false);
    }

    public function test_public_home_does_not_show_zero_try_for_zero_priced_packages(): void
    {
        Package::query()->create([
            'key' => 'enterprise-zero',
            'name' => 'Enterprise Zero',
            'description' => 'Kurumsal paket',
            'status' => 'active',
            'is_public' => true,
            'monthly_price' => 0,
            'currency' => 'TRY',
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('marketing.home'));

        $response->assertOk();
        $response->assertSee('Enterprise Zero');
        $response->assertSee('Teklif Al');
        $response->assertDontSee('0,00 TRY');
        $response->assertDontSee('Enterprise Zero</div>
                <h3>0,00', false);
    }
}
