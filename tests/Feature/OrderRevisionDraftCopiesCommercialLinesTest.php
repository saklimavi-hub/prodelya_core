<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class OrderRevisionDraftCopiesCommercialLinesTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_revision_draft_copies_product_and_print_commercial_lines(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRevisionDraft($sourceOrder);
        $sourceItem = $sourceOrder->items->firstOrFail();
        $draftItem = $draft->items->firstOrFail();
        $draftPrint = $draftItem->prints->firstOrFail();

        $this->assertSame($sourceOrder->customer_company_id, $draft->customer_company_id);
        $this->assertSame($sourceItem->product_name, $draftItem->product_name);
        $this->assertSame((string) $sourceItem->quantity, (string) $draftItem->quantity);
        $this->assertSame((string) $sourceItem->unit_price, (string) $draftItem->unit_price);
        $this->assertSame((string) $sourceItem->line_total, (string) $draftItem->line_total);
        $this->assertSame($sourceItem->description, $draftItem->description);
        $this->assertSame($sourceItem->prints->first()->print_type, $draftPrint->print_type);
        $this->assertSame($sourceItem->prints->first()->print_option, $draftPrint->print_option);
        $this->assertSame((string) $sourceItem->prints->first()->print_total, (string) $draftPrint->print_total);
        $this->assertTrue($draft->shouldShowPrintPriceDetailsToCustomer());
    }
}
