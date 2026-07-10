<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class QuoteListTabCountersTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpQuoteOrderListFixtures();
    }

    public function test_quote_tabs_show_consistent_counts_for_active_converted_archived_and_all_views(): void
    {
        $this->createQuote(['document_number' => 'TK-COUNT-ACTIVE-001']);
        $this->createConvertedQuote(
            ['document_number' => 'TK-COUNT-CONVERTED-001'],
            ['document_number' => 'SP-COUNT-CONVERTED-001']
        );
        $this->createQuote([
            'document_number' => 'TK-COUNT-ARCHIVE-001',
            'status' => 'cancelled',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_CANCELLED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $response->assertOk();
        $response->assertSee('Açık Teklifler <em>1</em>', false);
        $response->assertSee('Siparişe Dönüşenler <em>1</em>', false);
        $response->assertSee('Arşiv <em>1</em>', false);
        $response->assertSee('Tümü <em>3</em>', false);
    }
}
