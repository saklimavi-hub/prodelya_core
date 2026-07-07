<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowTurkishTerminologyTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_uses_turkish_labels_and_hides_technical_english_terms(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-TR-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();

        foreach ([
            'Görsel',
            'Müşteri',
            'Sipariş',
            'Üretime Hazır',
            'Revize',
            'Dosya Seç',
        ] as $expected) {
            $response->assertSee($expected);
        }

        foreach ([
            'Musteri',
            'Siparis',
            'Gorsel',
            'Display Path',
            'source_type',
            'file_path',
            'payload',
            'meta_json',
        ] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
    }
}
