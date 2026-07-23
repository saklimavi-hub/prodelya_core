<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicSummaryCompactTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_keeps_summary_compact_without_repeated_large_blocks(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-SUMMARY-001');

        $response = $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm));

        $response->assertOk();
        $response->assertSee('Genel Durum');
        $response->assertSee('Sıradaki İş');
        $response->assertDontSee('Kısayol Özeti');
        $response->assertDontSee('Sipariş, müşteri, ürün ve çalışma klasörü tek bakışta burada görünür.');
        $this->assertSame(1, substr_count($response->getContent(), 'Grafik Özeti'));
    }
}
