<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionPhotosGalleryLayoutTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_photos_tab_shows_read_only_recent_gallery(): void
    {
        $production = $this->createInternalProductionForShow();
        $this->uploadProductionPhoto($production, 'uretim-fotografi.jpg', 'İlk üretim kaydı');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=fotograflar');

        $response->assertOk();
        $response->assertSee('Fotoğraflar');
        $response->assertSee('salt okunur');
        $response->assertSee('Son Yüklenen Fotoğraflar');
        $response->assertSee('İlk üretim kaydı');
        $response->assertSee('Görüntüle');
        $response->assertSee('İndir');
    }
}
