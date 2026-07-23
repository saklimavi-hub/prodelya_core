<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphicListNextActionRegressionTest extends TestCase
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

    public function test_waiting_visual_row_shows_gorsel_yukle_as_single_primary_action(): void
    {
        $response = $this->renderForStatus(OrderItemPrintGraphic::STATUS_WAITING_VISUAL);

        $response->assertSee('Görsel Yükle');
        $response->assertDontSee('Grafiği Kontrol Et');
        $response->assertDontSee('Üretime Hazırla');
    }

    public function test_visual_uploaded_row_shows_grafigi_kontrol_et(): void
    {
        $response = $this->renderForStatus(OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED, queue: 'control_waiting');

        $response->assertSee('Grafiği Kontrol Et');
        $response->assertDontSee('Revize Yükle');
    }

    public function test_customer_approval_waiting_row_shows_onay_durumunu_ac(): void
    {
        $response = $this->renderForStatus(OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING, queue: 'customer_approval_waiting');

        $response->assertSee('Onay Durumunu Aç');
        $response->assertSee('Müşteri Onayı Bekliyor');
    }

    public function test_revision_requested_row_shows_revize_yukle(): void
    {
        $response = $this->renderForStatus(OrderItemPrintGraphic::STATUS_REVISION_REQUESTED, queue: 'revision_requested');

        $response->assertSee('Revize Yükle');
        $response->assertSee('Revize İstendi');
        $response->assertDontSee('Üretime Hazırla');
    }

    public function test_production_ready_tab_keeps_partially_completed_group_visible(): void
    {
        $response = $this->renderForStatus(OrderItemPrintGraphic::STATUS_PRODUCTION_READY, queue: 'production_ready', keepGroupActive: true);

        $response->assertSee('Üretime Hazır');
        $response->assertSee('Görsel Yükle');
        $response->assertDontSee('Arşiv kaydı');
    }

    public function test_completed_tab_shows_single_open_action_for_fully_completed_group(): void
    {
        $response = $this->renderForStatus(OrderItemPrintGraphic::STATUS_PRODUCTION_READY, queue: 'completed');

        $response->assertSee('Kaydı Aç');
        $response->assertSee('Tamamlandı');
        $response->assertDontSee('Görsel Yükle');
    }

    public function test_approved_row_shows_uretime_hazirla_instead_of_ready_truth(): void
    {
        $response = $this->renderForStatus(OrderItemPrintGraphic::STATUS_APPROVED, queue: 'control_waiting');

        $response->assertSee('Üretime Hazırla');
        $response->assertSee('Onaylandı');
        $response->assertDontSee('Tamamlandı');
    }

    private function renderForStatus(string $status, string $queue = 'action_waiting', bool $keepGroupActive = false)
    {
        $workForm = $this->createConvertedWorkForm($status . '-SKU', $keepGroupActive ? 2 : 1);
        $graphics = $workForm->printGraphics()->orderBy('sequence_code')->get()->values();

        $graphics[0]->update([
            'status' => $status,
            'customer_approval_status' => $status === OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING
                ? OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING
                : OrderItemPrintGraphic::CUSTOMER_APPROVAL_NOT_REQUIRED,
        ]);

        if ($keepGroupActive && isset($graphics[1])) {
            $graphics[1]->update([
                'status' => OrderItemPrintGraphic::STATUS_WAITING_VISUAL,
                'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_NOT_REQUIRED,
            ]);
        }

        return $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index', ['queue' => $queue]));
    }

    private function createConvertedWorkForm(string $productCode, int $printCount): OrderItemWorkForm
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $prints = [];
        for ($i = 1; $i <= $printCount; $i++) {
            $prints[] = [
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf ' . $i,
                'production_type' => 'İç üretim',
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
                'notes' => 'Graphic next action regression payload',
                'items' => [[
                    'product_name' => 'Graphic CTA Ürünü',
                    'product_code' => $productCode,
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'list_price' => '35',
                    'discount_rate' => '0',
                    'unit_price' => '35',
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
