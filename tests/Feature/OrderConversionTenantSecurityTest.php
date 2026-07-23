<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderConversionTenantSecurityTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_foreign_tenant_quote_cannot_be_converted_or_leaked_into_local_lists(): void
    {
        $foreignQuote = $this->createForeignTenantQuote();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->post(route('admin.orders.convert.from.quote', $foreignQuote))
            ->assertForbidden();

        $orders = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.orders.index'));

        $orders->assertDontSee($foreignQuote->document_number);
    }
}
