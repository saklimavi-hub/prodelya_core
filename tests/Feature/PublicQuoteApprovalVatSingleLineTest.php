<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPublicQuoteApprovalFixtures;
use Tests\TestCase;

class PublicQuoteApprovalVatSingleLineTest extends TestCase
{
    use BuildsPublicQuoteApprovalFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicQuoteApprovalFixtures();
    }

    public function test_public_quote_approval_vat_is_grouped_into_single_rate_lines(): void
    {
        $context = $this->createPublicApprovalContext('TK-PUBLIC-VAT-001', [
            'vat_breakdown_json' => [
                ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                ['rate' => 20, 'total' => 80, 'scope' => 'print'],
            ],
            'vat_total' => 320,
            'subtotal' => 1600,
            'grand_total' => 1920,
            'product_total' => 1200,
            'print_total' => 400,
        ], [[
            'product_name' => 'KDV Tek Satır Ürünü',
            'product_code' => 'KDV-001',
            'quantity' => 100,
            'unit_price' => 12,
            'line_total' => 1200,
            'has_print' => true,
            'print_total' => 400,
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                    ['rate' => 20, 'total' => 80, 'scope' => 'print'],
                ],
            ],
            'prints' => [[
                'print_type' => 'UV Baskı',
                'print_option' => 'Çift Taraf Baskı',
                'print_quantity' => 100,
                'print_unit_price' => 4,
                'print_total' => 400,
                'note' => 'KDV tek satır baskı',
            ]],
        ]]);

        $response = $this->get($this->quoteApprovalShowUrl($context['request']))->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), 'KDV %20'));
        $response->assertSee('320,00 TL');
    }
}
