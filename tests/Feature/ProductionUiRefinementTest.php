<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionUiRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_index_hides_qc_ui_and_renders_team_grouping_with_separate_status_columns(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();

        $this->prepareProductionForStart($productions['Tek taraf'], $graphics['1a'], 'ready-final.jpg');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'));

        $response->assertOk();
        $response->assertDontSee('QC Bekleyen');
        $response->assertDontSee('Grafik Hazır, Tedarik Bekliyor');
        $response->assertSee('Üretime Hazır');
        $response->assertSee('Tedarik Bekliyor');
        $response->assertSee('Tedarik bekliyor');
        $response->assertSee('pp-row-team', false);
        $response->assertSee('data-order-team=', false);
        $response->assertSee('pp-badge-green', false);
        $response->assertSee('pp-badge-red', false);
    }

    public function test_show_hides_qc_actions_and_empty_print_meta_and_keeps_large_preview_links(): void
    {
        ['productions' => $productions] = $this->createMultiPrintWorkForm();
        $production = $productions['Tek taraf']->fresh(['orderItemPrint']);

        $production->orderItemPrint->forceFill([
            'print_location' => null,
            'print_size' => null,
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production->fresh()));

        $response->assertOk();
        $response->assertDontSee('QC Uygun');
        $response->assertDontSee('Kalite Kontrol');
        $response->assertSee('Grafiğe Git');
        $response->assertSee('Tedariğe Git');
        $response->assertSee('İş Formu');
        $response->assertSee('Siparişi Aç');
        $response->assertSee('min-height: 420px', false);
        $response->assertSee('object-fit: contain', false);
        $response->assertDontSee('Baskı Yeri');
        $response->assertDontSee('Baskı Ölçüsü');
    }

    public function test_show_only_renders_preparation_panel_when_required(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();

        $withPreparation = $productions['Gövde']->fresh();
        $this->prepareProductionForStart($withPreparation, $graphics['1b'], 'prep-final.jpg');
        $withPreparation->forceFill([
            'cliche_required' => true,
            'cliche_status' => OrderItemPrintProduction::CLICHE_WAITING,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $withPreparationResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $withPreparation->fresh()));

        $withPreparationResponse->assertOk();
        $withPreparationResponse->assertSee('Klişe / Kalıp Kontrolü');
        $withPreparationResponse->assertSee('Klişe Bekliyor');

        $withoutPreparationResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $productions['Tek taraf']->fresh()));

        $withoutPreparationResponse->assertOk();
        $withoutPreparationResponse->assertDontSee('Klişe / Kalıp Kontrolü');
    }

    private function createMultiPrintWorkForm(): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-REFINE-' . random_int(1000, 9999),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'customer_supplied',
            'product_name' => 'UI Refinement Product',
            'product_code' => 'UI-REFINE-001',
            'quantity' => 120,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'UI Refinement Product',
                'product_code' => 'UI-REFINE-001',
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'has_print' => true,
            'print_total' => 120,
            'status' => 'pending',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'print_size' => 'Standart',
            'print_quantity' => 120,
            'status' => 'draft',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'Lazer',
            'print_option' => 'Gövde',
            'print_location' => 'Kapak',
            'print_size' => '40 x 15 mm',
            'print_quantity' => 120,
            'status' => 'draft',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();
        $workForm = $workForm->fresh(['procurement']);

        $productions = OrderItemPrintProduction::query()
            ->where('work_form_id', $workForm->id)
            ->with(['workForm.procurement', 'orderItemPrint.graphicOperation'])
            ->get()
            ->keyBy(fn (OrderItemPrintProduction $production) => $production->orderItemPrint->print_option);

        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');

        return [
            'workForm' => $workForm,
            'productions' => $productions,
            'graphics' => $graphics,
        ];
    }

    private function prepareProductionForStart(
        OrderItemPrintProduction $production,
        OrderItemPrintGraphic $graphic,
        string $fileName
    ): void {
        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Production UI refinement test graphic',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm->procurement;
        $this->assertNotNull($procurement);

        $procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $production->workForm->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_PENDING,
                    'procurement_status_label' => 'Tedarik Bekliyor',
                    'public_status_label' => 'Ürün bekleniyor',
                    'received_quantity' => 0,
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }
}
