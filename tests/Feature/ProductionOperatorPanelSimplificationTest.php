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

class ProductionOperatorPanelSimplificationTest extends TestCase
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

    public function test_operator_panel_is_simplified_and_keeps_single_photo_area(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();
        $production = $productions['Tek taraf']->fresh();

        $this->prepareProductionForStart($production, $graphics['1a'], 'operator-final.jpg');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production->fresh()) . '?tab=genel');

        $response->assertOk();
        $response->assertDontSee('Üretime Başlama Kontrol Kartları');
        $response->assertDontSee('QC Uygun');
        $response->assertDontSee('Mobil Fotoğraf Alanı');
        $response->assertSee('Fotoğraf Ekle');
        $response->assertSee('Üretim Durumu Adımları');
        $response->assertSee('Hızlı Bakış');
        $response->assertSee('Siparişi Aç');
        $response->assertSee('İş Formu');
        $response->assertSee('UV Baskı');
    }

    public function test_operator_panel_hides_empty_print_meta_and_only_shows_preparation_when_needed(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();

        $plainProduction = $productions['Tek taraf']->fresh(['orderItemPrint']);
        $plainProduction->orderItemPrint->forceFill([
            'print_location' => null,
            'print_size' => null,
        ])->save();

        $plainResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $plainProduction->fresh()) . '?tab=genel');

        $plainResponse->assertOk();
        $plainResponse->assertDontSee('Baskı Yeri');
        $plainResponse->assertDontSee('Baskı Ölçüsü');
        $plainResponse->assertDontSee('Klişe / Kalıp Kontrolü');

        $preparedProduction = $productions['Gövde']->fresh();
        $this->prepareProductionForStart($preparedProduction, $graphics['1b'], 'prepared-final.jpg');
        $preparedProduction->forceFill([
            'cliche_required' => true,
            'cliche_status' => OrderItemPrintProduction::CLICHE_WAITING,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $preparedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $preparedProduction->fresh()) . '?tab=genel');

        $preparedResponse->assertOk();
        $preparedResponse->assertSee('Önemli Notlar');
        $preparedResponse->assertSee('Bu baskı için gerekli ara eleman hazır olmadan baskıya başlanmaz.');
    }

    private function createMultiPrintWorkForm(): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-OP-' . random_int(1000, 9999),
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
            'product_name' => 'Operator Panel Product',
            'product_code' => 'OP-001',
            'quantity' => 80,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'Operator Panel Product',
                'product_code' => 'OP-001',
                'group_code' => 'HIDDEN',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'has_print' => true,
            'print_total' => 80,
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
            'print_quantity' => 80,
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
            'print_quantity' => 80,
            'status' => 'draft',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();

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
                'note' => 'Operator panel test graphic',
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
