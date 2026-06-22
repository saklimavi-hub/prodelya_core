<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use App\Services\FinanceSummaryService;
use App\Services\WorkFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoPrintOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_no_print_quote_conversion_skips_graphic_and_production_flow_but_keeps_procurement_delivery_and_finance_ready(): void
    {
        $quote = $this->createQuoteViaHttp(withPrint: false);

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
            ->with(['procurement', 'delivery', 'printProductions', 'orderItem'])
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($workForm);
        $this->assertNotNull($workForm->procurement);
        $this->assertNotNull($workForm->delivery);
        $this->assertSame('gerekli_degil', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame('gerekli_degil', data_get($workForm->graphic_snapshot, 'approval_status'));
        $this->assertSame('gerekli_degil', data_get($workForm->production_snapshot, 'status'));
        $this->assertSame('gerekli_degil', data_get($workForm->production_snapshot, 'qc_status'));
        $this->assertCount(0, $workForm->printProductions);
        $this->assertSame(0, OrderItemPrintProduction::query()->where('order_id', $order->id)->count());

        $summary = app(FinanceSummaryService::class)->summarizeOrder($order->fresh('payments', 'customer'));

        $this->assertSame($order->document_number, data_get($summary, 'order_number'));
        $this->assertSame('odeme_bekliyor', data_get($summary, 'payment_status'));
        $this->assertSame('odeme_bekliyor', data_get($summary, 'delivery_financial_warning'));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.show', $order))
            ->assertOk()
            ->assertSee($order->document_number);

        $graphicsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $graphicsIndex->assertOk();
        $graphicsIndex->assertDontSee($workForm->work_form_number);
        $graphicsIndex->assertDontSee((string) data_get($workForm->product_snapshot, 'product_name'));

        $workFormShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $workFormShow->assertOk();
        $workFormShow->assertSee('Grafik Gerekli Değil');
        $workFormShow->assertSee('Üretim Gerekli Değil');
        $workFormShow->assertDontSee('Kendi üretim');
        $workFormShow->assertDontSee('unit_price', false);
        $workFormShow->assertDontSee('grand_total', false);
        $workFormShow->assertDontSee('group_code', false);

        $publicTracking = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicTracking->assertOk();
        $publicTracking->assertDontSee('Grafik bekliyor');
        $publicTracking->assertDontSee('Üretim bekliyor');
        $publicTracking->assertSee('Grafik gerekli değil');
        $publicTracking->assertSee('Üretim gerekli değil');
        $publicTracking->assertDontSee('unit_price', false);
        $publicTracking->assertDontSee('grand_total', false);
        $publicTracking->assertDontSee('group_code', false);

        $pdfHtml = app(WorkFormPdfService::class)->renderHtml($workForm->fresh(['tenant', 'attachments', 'activityLogs.attachment']));

        $this->assertStringContainsString('Grafik Gerekli Değil', $pdfHtml);
        $this->assertStringContainsString('Üretim Gerekli Değil', $pdfHtml);
        $this->assertStringNotContainsString('unit_price', $pdfHtml);
        $this->assertStringNotContainsString('grand_total', $pdfHtml);
        $this->assertStringNotContainsString('group_code', $pdfHtml);
    }

    public function test_printed_quote_still_creates_graphic_and_production_records_per_print_line(): void
    {
        $quote = $this->createQuoteViaHttp(withPrint: true);

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
            ->with(['orderItem.prints', 'printProductions'])
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('bekliyor', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertCount(2, $workForm->orderItem->prints);
        $this->assertCount(2, $workForm->printProductions);
        $this->assertSame(
            $workForm->orderItem->prints->count(),
            OrderItemPrintProduction::query()->where('work_form_id', $workForm->id)->count()
        );

        $graphicsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $graphicsIndex->assertOk();
        $graphicsIndex->assertSee($workForm->work_form_number);
        $graphicsIndex->assertSee((string) data_get($workForm->product_snapshot, 'product_name'));
    }

    private function createQuoteViaHttp(bool $withPrint): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $itemPayload = [
            'product_name' => $withPrint ? 'Baskılı Test Kupa' : 'Baskısız Test Termos',
            'product_code' => $withPrint ? 'NOPRINT-PRINTED-001' : 'NOPRINT-001',
            'quantity' => '100',
            'unit' => 'Adet',
            'list_price' => '12.50',
            'discount_rate' => '10',
            'unit_price' => '11.25',
            'manual_unit_price' => '1',
            'vat_rate' => '20',
            'has_print' => $withPrint ? '1' : '0',
            'prints' => $withPrint ? [
                [
                    'print_type' => 'UV Baskı',
                    'print_option' => 'Tek taraf baskılı',
                    'production_type' => 'İç üretim',
                    'print_quantity' => '100',
                    'print_unit_price' => '2.50',
                    'note' => 'Logo baskı',
                ],
                [
                    'print_type' => 'Lazer',
                    'print_option' => 'İsim baskı',
                    'production_type' => 'Dış üretim / Fason',
                    'subcontractor_company_id' => $partner->id,
                    'print_quantity' => '100',
                    'print_unit_price' => '1.50',
                    'note' => 'Kişiselleştirme',
                ],
            ] : [],
        ];

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-13',
                'valid_until' => '2026-06-20',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => $withPrint ? 'Baskılı regression payload' : 'Baskısız regression payload',
                'items' => [$itemPayload],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        return $quote;
    }
}
