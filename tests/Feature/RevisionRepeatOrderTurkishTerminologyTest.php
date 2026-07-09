<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RevisionRepeatOrderTurkishTerminologyTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_turkish_labels_are_rendered_correctly(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRevisionDraft($sourceOrder);

        $this->getAs($this->adminUser, route('admin.orders.show', $sourceOrder))
            ->assertOk()
            ->assertSee('Revizyon Oluştur')
            ->assertSee('Tekrar Sipariş Oluştur');

        $this->getAs($this->adminUser, route('admin.promotion-quotes.show', $draft))
            ->assertOk()
            ->assertSee('Revize 1')
            ->assertSee('Kaynak Sipariş')
            ->assertSee('Bu kayıt eski siparişten kopyalanmıştır. Fiyat, stok ve baskı bilgilerini kontrol ederek devam edin.');
    }
}
