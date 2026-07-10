<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class QuoteArchivedStatusesAuditTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpQuoteOrderListFixtures();
    }

    public function test_archived_quote_view_collects_closed_cancelled_rejected_and_expired_records_only(): void
    {
        $cancelled = $this->createQuote(['document_number' => 'TK-ARCHIVE-CANCELLED', 'status' => 'cancelled']);
        $rejectedStatus = $this->createQuote(['document_number' => 'TK-ARCHIVE-STATUS-REJECTED', 'status' => 'rejected']);
        $rejected = $this->createQuote([
            'document_number' => 'TK-ARCHIVE-REJECTED',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_REJECTED,
        ]);
        $expired = $this->createQuote([
            'document_number' => 'TK-ARCHIVE-EXPIRED',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_EXPIRED,
        ]);
        $active = $this->createQuote(['document_number' => 'TK-ARCHIVE-ACTIVE']);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index', ['filter' => 'archived']));

        $response->assertOk();
        $response->assertSee($cancelled->document_number);
        $response->assertSee($rejectedStatus->document_number);
        $response->assertSee($rejected->document_number);
        $response->assertSee($expired->document_number);
        $response->assertDontSee($active->document_number);
    }
}
