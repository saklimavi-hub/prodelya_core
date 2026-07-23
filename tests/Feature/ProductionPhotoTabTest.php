<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionPhotoTabTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProductionShowFixtures();
    }

    public function test_photo_tab_renders_read_only_recent_gallery(): void
    {
        $production = $this->createInternalProductionForShow();
        $this->uploadProductionPhoto($production);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::PRODUCTION_SHOW_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=fotograflar');

        $response->assertOk();
        $response->assertSee('Fotoğraflar');
        $response->assertSee('salt okunur');
        $response->assertSee('Son Yüklenen Fotoğraflar');
        $response->assertSee('Görüntüle');
        $response->assertSee('İndir');
    }
}
