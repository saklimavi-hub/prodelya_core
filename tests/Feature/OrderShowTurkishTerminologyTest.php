<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowTurkishTerminologyTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_order_show_uses_turkish_labels_without_broken_characters(): void
    {
        $order = $this->createConvertedOrderForShow();

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', $order));

        $response->assertOk()
            ->assertSee('Genel Özet')
            ->assertSee('İş Formu')
            ->assertSee('Üretim')
            ->assertSee('Teslimat')
            ->assertSee('Geçmiş')
            ->assertDontSee('Musteri')
            ->assertDontSee('Siparis')
            ->assertDontSee('Tedarikci')
            ->assertDontSee('Islem')
            ->assertDontSee('Order ID')
            ->assertDontSee('payload', false)
            ->assertDontSee('raw', false);
    }
}
