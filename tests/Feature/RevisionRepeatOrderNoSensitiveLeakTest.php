<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RevisionRepeatOrderNoSensitiveLeakTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_sensitive_snapshot_fields_do_not_leak_to_new_draft_or_ui(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRevisionDraft($sourceOrder);
        $draftItem = $draft->items->firstOrFail();

        $this->assertNull(data_get($draftItem->product_snapshot, 'group_code'));
        $this->assertNull(data_get($draftItem->product_snapshot, 'raw'));
        $this->assertNull(data_get($draftItem->price_snapshot, 'supplier_cost'));
        $this->assertNull(data_get($draftItem->price_snapshot, 'margin'));
        $this->assertNull(data_get($draftItem->price_snapshot, 'file_path'));
        $this->assertNull(data_get($draftItem->stock_snapshot, 'projection'));
        $this->assertNull(data_get($draftItem->stock_snapshot, 'payload'));

        $this->getAs($this->adminUser, route('admin.promotion-quotes.show', $draft))
            ->assertOk()
            ->assertDontSee('SUPPLIER-COST-HIDDEN')
            ->assertDontSee('MARGIN-HIDDEN')
            ->assertDontSee('HIDDEN-GROUP-CODE')
            ->assertDontSee('RAW-HIDDEN')
            ->assertDontSee('PROJECTION-HIDDEN')
            ->assertDontSee('PAYLOAD-HIDDEN');
    }
}
