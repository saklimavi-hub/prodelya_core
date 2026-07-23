<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphicIndexSimplifiedActionsTest extends TestCase
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

    public function test_graphics_index_shows_only_exact_print_rows_and_hides_no_print_work(): void
    {
        $printedWorkForm = $this->createConvertedWorkForm(withPrint: true);
        $noPrintWorkForm = $this->createConvertedWorkForm(withPrint: false);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $response->assertOk();
        $response->assertSee('Grafik İşleri');
        $response->assertSee($printedWorkForm->work_form_number);
        $response->assertSee((string) data_get($printedWorkForm->order_snapshot, 'document_number'));
        $response->assertSee('1a');
        $response->assertSee('1b');
        $response->assertSee('UV Baskı');
        $response->assertSee('Lazer');
        $response->assertSee('Görsel Yükle');
        $response->assertDontSee('Düzenle');
        $response->assertDontSee('>Üretime Hazır İşaretle<', false);
        $response->assertDontSee($noPrintWorkForm->work_form_number);
        $response->assertDontSee((string) data_get($noPrintWorkForm->order_snapshot, 'document_number'));
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('KDV');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('price_snapshot', false);
    }

    private function createConvertedWorkForm(bool $withPrint): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-13',
                'valid_until' => '2026-06-20',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => $withPrint ? 'Printed graphics list payload' : 'No-print graphics list payload',
                'items' => [[
                    'product_name' => $withPrint ? 'Grafik Listesi Baskılı Ürün' : 'Grafik Listesi Baskısız Ürün',
                    'product_code' => $withPrint ? 'GRAPH-LIST-PRINT-001' : 'GRAPH-LIST-NOPRINT-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '12.50',
                    'discount_rate' => '0',
                    'unit_price' => '12.50',
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
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Gövde baskı',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '100',
                            'print_unit_price' => '1.50',
                        ],
                    ] : [],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return OrderItemWorkForm::query()->latest('id')->firstOrFail();
    }
}
