<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkFormCreationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private ?string $expectedSubcontractorCompanyName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_conversion_creates_one_work_form_for_each_order_item(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $order->load(['items.workForm', 'workForms']);

        $this->assertCount(2, $order->items);
        $this->assertCount(2, $order->workForms);
        $this->assertNotNull($order->items[0]->workForm);
        $this->assertNotNull($order->items[1]->workForm);
    }

    public function test_same_item_print_lines_are_grouped_inside_single_work_form_snapshot(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $workForm = OrderItemWorkForm::query()
            ->where('order_id', $order->id)
            ->where('item_sequence', 1)
            ->firstOrFail();

        $printSnapshot = $workForm->print_snapshot ?? [];

        $this->assertCount(2, $printSnapshot);
        $this->assertSame('1a', $printSnapshot[0]['sequence']);
        $this->assertSame('1b', $printSnapshot[1]['sequence']);
        $this->assertSame('UV Baskı', $printSnapshot[0]['print_type']);
        $this->assertSame('Lazer', $printSnapshot[1]['print_type']);
        $this->assertSame('Tek taraf baskılı', $printSnapshot[0]['print_option']);
        $this->assertSame('Dış üretim / Fason', $printSnapshot[1]['production_type']);
        $this->assertSame($this->expectedSubcontractorCompanyName, $printSnapshot[1]['subcontractor_company_name']);
    }

    public function test_work_form_number_token_and_sequences_are_generated(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $forms = OrderItemWorkForm::query()
            ->where('order_id', $order->id)
            ->orderBy('item_sequence')
            ->get();

        $this->assertCount(2, $forms);
        $this->assertMatchesRegularExpression('/^IF-\d{4}-\d{4}$/', $forms[0]->work_form_number);
        $this->assertMatchesRegularExpression('/^IF-\d{4}-\d{4}$/', $forms[1]->work_form_number);
        $this->assertNotSame($forms[0]->work_form_number, $forms[1]->work_form_number);
        $this->assertSame(1, $forms[0]->item_sequence);
        $this->assertSame(2, $forms[1]->item_sequence);
        $this->assertNotEmpty($forms[0]->public_tracking_token);
        $this->assertNotEmpty($forms[1]->public_tracking_token);
        $this->assertNotSame($forms[0]->public_tracking_token, $forms[1]->public_tracking_token);
        $this->assertSame($quote->id, $forms[0]->source_quote_id);
        $this->assertSame($quote->document_number, $forms[0]->source_quote_number);
    }

    public function test_work_form_snapshots_are_financially_sanitized(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $forms = OrderItemWorkForm::query()->where('order_id', $order->id)->get();
        $forbiddenKeys = [
            'unit_price',
            'list_price',
            'discount_rate',
            'line_total',
            'print_unit_price',
            'print_total',
            'subtotal',
            'vat_total',
            'grand_total',
            'product_total',
            'price_snapshot',
            'vat_breakdown',
            'vat_breakdown_json',
            'cost',
            'margin',
            'profit',
        ];

        foreach ($forms as $form) {
            foreach ([
                $form->order_snapshot,
                $form->customer_snapshot,
                $form->product_snapshot,
                $form->print_snapshot,
                $form->graphic_snapshot,
                $form->production_snapshot,
                $form->delivery_snapshot,
            ] as $snapshot) {
                $this->assertSnapshotHasNoForbiddenKeys($snapshot, $forbiddenKeys);
            }
        }
    }

    public function test_work_form_initial_snapshots_have_expected_defaults(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $forms = OrderItemWorkForm::query()
            ->where('order_id', $order->id)
            ->orderBy('item_sequence')
            ->get();

        $printedItemForm = $forms[0];
        $nonPrintedItemForm = $forms[1];

        $this->assertSame('bekliyor', $printedItemForm->graphic_snapshot['status']);
        $this->assertSame('bekliyor', $printedItemForm->graphic_snapshot['approval_status']);
        $this->assertSame('uretim_bekliyor', $printedItemForm->production_snapshot['status']);
        $this->assertSame('bekliyor', $printedItemForm->production_snapshot['qc_status']);
        $this->assertSame('teslimat_bekliyor', $printedItemForm->delivery_snapshot['status']);
        $this->assertSame('Kargo', $printedItemForm->delivery_snapshot['delivery_type']);
        $this->assertSame('kargo', $printedItemForm->delivery_snapshot['delivery_method']);

        $this->assertSame('gerekli_degil', $nonPrintedItemForm->graphic_snapshot['status']);
        $this->assertSame('gerekli_degil', $nonPrintedItemForm->graphic_snapshot['approval_status']);
        $this->assertSame('gerekli_degil', $nonPrintedItemForm->production_snapshot['status']);
        $this->assertSame('gerekli_degil', $nonPrintedItemForm->production_snapshot['qc_status']);
    }

    public function test_product_snapshot_contains_expected_non_financial_fields(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $form = OrderItemWorkForm::query()
            ->where('order_id', $order->id)
            ->where('item_sequence', 1)
            ->firstOrFail();

        $this->assertSame('Smoke Test Kalem', $form->product_snapshot['product_name']);
        $this->assertSame('SMOKE-001', $form->product_snapshot['product_code']);
        $this->assertSame(100.0, (float) $form->product_snapshot['quantity']);
        $this->assertSame('Adet', $form->product_snapshot['unit']);
        $this->assertArrayHasKey('image_url', $form->product_snapshot);
        $this->assertSame('-', $form->product_snapshot['variant_name']);
    }

    public function test_work_form_service_does_not_create_second_active_form_for_same_item(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $service = app(WorkFormCreationService::class);
        $firstRun = $service->createForOrder($order, $this->adminUser);
        $secondRun = $service->createForOrder($order, $this->adminUser);

        $this->assertCount(2, $firstRun);
        $this->assertCount(2, $secondRun);
        $this->assertSame(2, OrderItemWorkForm::query()->where('order_id', $order->id)->count());
    }

    public function test_conversion_rolls_back_when_work_form_creation_fails(): void
    {
        $quote = $this->createQuoteViaHttp();

        OrderItemWorkForm::creating(static function (): void {
            throw new \RuntimeException('Simulated work form failure.');
        });

        try {
            $response = $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post("/admin/orders/convert/{$quote->id}");

            $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
            $response->assertSessionHasErrors(['error' => 'Siparise donusturme sirasinda hata olustu.']);
            $this->assertSame(0, Order::query()->where('document_type', 'order')->where('source_quote_id', $quote->id)->count());
            $this->assertSame(0, OrderItemWorkForm::query()->where('source_quote_id', $quote->id)->count());
        } finally {
            OrderItemWorkForm::flushEventListeners();
            OrderItemWorkForm::clearBootedModels();
        }
    }

    private function createQuoteViaHttp(): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();
        $this->expectedSubcontractorCompanyName = $partner->legal_name;

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Work form smoke payload',
                'items' => [
                    [
                        'product_name' => 'Smoke Test Kalem',
                        'product_code' => 'SMOKE-001',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '8.60',
                        'discount_rate' => '45',
                        'unit_price' => '4.70',
                        'manual_unit_price' => '1',
                        'vat_rate' => '10',
                        'has_print' => '1',
                        'prints' => [
                            [
                                'print_type' => 'UV Baskı',
                                'print_option' => 'Tek taraf baskılı',
                                'production_type' => 'İç üretim',
                                'print_quantity' => '100',
                                'print_unit_price' => '5',
                                'note' => 'test baskı',
                            ],
                            [
                                'print_type' => 'Lazer',
                                'print_option' => 'Tek taraf lazer',
                                'production_type' => 'Dış üretim / Fason',
                                'subcontractor_company_id' => $partner->id,
                                'print_quantity' => '100',
                                'print_unit_price' => '10',
                                'note' => 'test ikinci baskı',
                            ],
                        ],
                    ],
                    [
                        'product_name' => 'Smokeless Defter',
                        'product_code' => 'SMOKE-002',
                        'quantity' => '50',
                        'unit' => 'Adet',
                        'list_price' => '12.00',
                        'discount_rate' => '10',
                        'unit_price' => '10.80',
                        'manual_unit_price' => '0',
                        'vat_rate' => '20',
                        'has_print' => '0',
                        'prints' => [],
                    ],
                ],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        return $quote;
    }

    private function assertSnapshotHasNoForbiddenKeys(mixed $payload, array $forbiddenKeys): void
    {
        if (!is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            $this->assertNotContains((string) $key, $forbiddenKeys, "Forbidden key [{$key}] leaked into work form snapshot.");
            $this->assertSnapshotHasNoForbiddenKeys($value, $forbiddenKeys);
        }
    }
}
