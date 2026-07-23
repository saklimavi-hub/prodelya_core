<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionFinalUiTest extends TestCase
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

    public function test_productions_index_renders_per_print_job_pool_without_financial_or_technical_leaks(): void
    {
        ['workForm' => $workForm] = $this->createMultiPrintWorkForm(includeNoPrintItem: true);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'));

        $response->assertOk();
        $response->assertSee('Üretim / Fason');
        $response->assertSee('Havuz Özeti');
        $response->assertSee($workForm->work_form_number);
        $response->assertSee('UI Final Multi Product');
        $response->assertSee('UV Baskı');
        $response->assertSee('Lazer');
        $response->assertSee('Grafiği Gör');
        $response->assertDontSee('No Print Hidden Item');
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('KDV', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
    }

    public function test_production_show_uses_per_print_final_graphic_and_large_control_panels(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();

        $this->prepareProductionForStart($productions['Tek taraf'], $graphics['1a'], 'final-a-preview.jpg');
        $this->prepareProductionForStart($productions['Gövde'], $graphics['1b'], 'final-b-preview.jpg');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $productions['Tek taraf']->fresh()) . '?tab=genel');

        $response->assertOk();
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('Süreç durumu');
        $response->assertSee('Kompakt üretim özeti');
        $response->assertSee('Fotoğraflar');
        $response->assertSee(route('admin.work-forms.show', $productions['Tek taraf']->workForm), false);
        $response->assertSee(route('admin.orders.show', $productions['Tek taraf']->order), false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_production_show_surfaces_graphic_and_procurement_blockers_and_hides_preparation_section_when_not_needed(): void
    {
        ['productions' => $productions] = $this->createMultiPrintWorkForm();
        $production = $productions['Tek taraf']->fresh();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production) . '?tab=genel');

        $response->assertOk();
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('Sıradaki İşlem');
        $response->assertSee('Grafik Bekliyor');
        $response->assertSee('Tedarik Bekliyor');
        $response->assertDontSee('Klişe / Kalıp Kontrolü');
    }

    public function test_production_show_displays_preparation_section_when_required_and_blocks_start(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();
        $production = $productions['Gövde']->fresh();

        $this->prepareProductionForStart($production, $graphics['1b'], 'laser-final.jpg');

        $production->forceFill([
            'cliche_required' => true,
            'cliche_status' => OrderItemPrintProduction::CLICHE_WAITING,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production->fresh()) . '?tab=genel');

        $response->assertOk();
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('Sıradaki İşlem');
        $response->assertDontSee('Bu baskı için gerekli ara eleman hazır olmadan baskıya başlanmaz.');
    }

    private function createMultiPrintWorkForm(bool $includeNoPrintItem = false): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-FINAL-' . random_int(1000, 9999),
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
            'product_name' => 'UI Final Multi Product',
            'product_code' => 'UI-FINAL-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'UI Final Multi Product',
                'product_code' => 'UI-FINAL-001',
                'warning_badges' => [],
                'group_code' => 'FINAL-HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 55,
                'line_total' => 1100,
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 33,
                'local_stock_quantity' => 0,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 70,
            'discount_rate' => 5,
            'unit_price' => 55,
            'line_total' => 1100,
            'has_print' => true,
            'print_total' => 100,
            'status' => 'pending',
        ]);

        if ($includeNoPrintItem) {
            OrderItem::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'order_id' => $order->id,
                'item_type' => 'product',
                'product_source' => 'manual',
                'product_name' => 'No Print Hidden Item',
                'product_code' => 'NO-PRINT-001',
                'quantity' => 10,
                'unit' => 'Adet',
                'has_print' => false,
                'status' => 'pending',
            ]);
        }

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'print_quantity' => 100,
            'note' => 'Logo baskı',
            'status' => 'draft',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'Lazer',
            'print_option' => 'Gövde',
            'print_location' => 'Kapak',
            'print_color' => 'Tek Renk',
            'print_size' => '45 x 18 mm',
            'print_quantity' => 100,
            'note' => 'İsim baskı',
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
            'order' => $order,
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
                'note' => 'Production final UI test graphic',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm->procurement;
        $this->assertNotNull($procurement);

        $procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $production->workForm->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'procurement_status_label' => 'Tamamı Geldi',
                    'public_status_label' => 'Ürün üretime hazır',
                    'received_quantity' => 100,
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }
}
