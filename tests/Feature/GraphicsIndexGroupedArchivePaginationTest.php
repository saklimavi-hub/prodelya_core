<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphicsIndexGroupedArchivePaginationTest extends TestCase
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

    public function test_graphics_index_groups_exact_rows_by_order(): void
    {
        $workForm = $this->createConvertedWorkForm('GRAPH-GRP-001', 2);

        $response = $this->graphicsGet();

        $response->assertOk();
        $response->assertSee('pd-graphic-order-group', false);
        $response->assertSee($workForm->order_snapshot['document_number'] ?? '');
        $response->assertSee('1a');
        $response->assertSee('1b');
        $response->assertSee('Exact SKU: GRAPH-GRP-001');
    }

    public function test_action_waiting_excludes_fully_completed_groups_but_keeps_partially_completed_group_active(): void
    {
        $completed = $this->createConvertedWorkForm('GRAPH-GRP-COMPLETE', 1);
        $completed->printGraphics()->update(['status' => OrderItemPrintGraphic::STATUS_PRODUCTION_READY]);

        $partial = $this->createConvertedWorkForm('GRAPH-GRP-PARTIAL', 2);
        $partialGraphics = $partial->printGraphics()->orderBy('sequence_code')->get()->values();
        $partialGraphics[0]->update(['status' => OrderItemPrintGraphic::STATUS_PRODUCTION_READY]);
        $partialGraphics[1]->update(['status' => OrderItemPrintGraphic::STATUS_WAITING_VISUAL]);

        $response = $this->graphicsGet();

        $response->assertOk();
        $response->assertSee($partial->order_snapshot['document_number'] ?? '');
        $response->assertDontSee($completed->order_snapshot['document_number'] ?? '');
        $response->assertSee('step=upload', false);
    }

    public function test_completed_tab_shows_only_fully_completed_groups_with_single_group_open_action(): void
    {
        $completed = $this->createConvertedWorkForm('GRAPH-GRP-ARCHIVE', 2);
        $completed->printGraphics()->update(['status' => OrderItemPrintGraphic::STATUS_PRODUCTION_READY]);

        $active = $this->createConvertedWorkForm('GRAPH-GRP-ACTIVE', 1);
        $active->printGraphics()->update(['status' => OrderItemPrintGraphic::STATUS_WAITING_VISUAL]);

        $response = $this->graphicsGet(['queue' => 'completed']);

        $response->assertOk();
        $response->assertSee('Tamamlananlar');
        $response->assertSee($completed->order_snapshot['document_number'] ?? '');
        $response->assertDontSee($active->order_snapshot['document_number'] ?? '');
        $response->assertSee('step=summary', false);
        $response->assertDontSee('step=upload', false);
    }

    public function test_graphics_index_paginates_by_order_group_and_preserves_filters(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $workForm = $this->createConvertedWorkForm('GRAPH-PAG-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1);
            $workForm->printGraphics()->update(['status' => OrderItemPrintGraphic::STATUS_WAITING_VISUAL]);
        }

        $firstPage = $this->graphicsGet(['per_page' => 10, 'q' => 'GRAPH-PAG']);
        $firstPage->assertOk();
        $firstPage->assertSee('Toplam 11');
        $firstPage->assertSee('1-10');
        $firstPage->assertSee('per_page=10', false);
        $firstPage->assertSee('q=GRAPH-PAG', false);

        $secondPage = $this->graphicsGet(['per_page' => 10, 'page' => 2, 'q' => 'GRAPH-PAG']);
        $secondPage->assertOk();
        $secondPage->assertSee('Toplam 11');
        $secondPage->assertSee('11-11');
    }
    public function test_graphics_index_pagination_uses_turkish_labels(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $workForm = $this->createConvertedWorkForm('GRAPH-TUR-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1);
            $workForm->printGraphics()->update(['status' => OrderItemPrintGraphic::STATUS_WAITING_VISUAL]);
        }

        $response = $this->graphicsGet(['per_page' => 10, 'q' => 'GRAPH-TUR']);

        $response->assertOk();
        $response->assertSee('Geri');
        $response->assertSee('İleri');
        $response->assertDontSee('Previous');
        $response->assertDontSee('Next');
        $response->assertSee('per_page=10', false);
        $response->assertSee('q=GRAPH-TUR', false);
    }
    private function graphicsGet(array $query = [])
    {
        return $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index', $query));
    }

    private function createConvertedWorkForm(string $productCode, int $printCount): OrderItemWorkForm
    {
        $customer = Company::query()->orderBy('id')->firstOrFail();

        $prints = [];
        for ($i = 1; $i <= $printCount; $i++) {
            $prints[] = [
                'print_type' => 'UV BaskÄ±',
                'print_option' => 'Varyant ' . $i,
                'production_type' => 'Ä°Ã§ Ã¼retim',
                'print_quantity' => '10',
                'print_unit_price' => '5',
            ];
        }

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-20',
                'valid_until' => '2026-07-27',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Grouped graphics index regression payload',
                'items' => [[
                    'product_name' => 'Grafik Gruplu Liste Test ÃœrÃ¼nÃ¼',
                    'product_code' => $productCode,
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'list_price' => '42',
                    'discount_rate' => '0',
                    'unit_price' => '42',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => $prints,
                ]],
            ])->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
    }
}
