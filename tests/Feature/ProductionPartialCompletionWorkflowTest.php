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

class ProductionPartialCompletionWorkflowTest extends TestCase
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

    public function test_partial_completion_updates_only_target_print_and_creates_logs(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();
        $firstProduction = $productions['Tek taraf']->fresh();
        $secondProduction = $productions['Gövde']->fresh();

        $this->prepareProductionForStart($firstProduction, $graphics['1a'], 'partial-ready-1a.jpg');
        $this->prepareProductionForStart($secondProduction, $graphics['1b'], 'partial-ready-1b.jpg');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $firstProduction), [
                'action' => 'partial',
                'partial_quantity' => '20',
                'note' => 'İlk tur baskı tamamlandı',
            ])
            ->assertRedirect(route('admin.productions.show', $firstProduction));

        $firstProduction = $firstProduction->fresh(['workForm.activityLogs']);
        $secondProduction = $secondProduction->fresh();

        $this->assertSame(20.0, (float) $firstProduction->completed_quantity);
        $this->assertSame(60.0, (float) $firstProduction->remaining_quantity);
        $this->assertSame(OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED, $firstProduction->production_status);
        $this->assertNotNull($firstProduction->started_at);
        $this->assertNull($firstProduction->completed_at);
        $this->assertSame(0.0, (float) $secondProduction->completed_quantity);
        $this->assertSame(80.0, (float) $secondProduction->remaining_quantity);
        $this->assertTrue($firstProduction->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'production_partially_completed'
        ));

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $firstProduction));

        $show->assertOk();
        $show->assertSee('Basılan Adet');
        $show->assertSee('20');
        $show->assertDontSee('group_code', false);
        $show->assertDontSee('file_path', false);
        $show->assertDontSee('physical_path', false);
    }

    public function test_partial_quantity_validations_block_zero_and_overflow(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();
        $production = $productions['Tek taraf']->fresh();
        $this->prepareProductionForStart($production, $graphics['1a'], 'partial-validation.jpg');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $production))
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'partial',
                'partial_quantity' => '0',
            ])
            ->assertRedirect(route('admin.productions.show', $production))
            ->assertSessionHasErrors('partial_quantity');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $production))
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'partial',
                'partial_quantity' => '999',
            ])
            ->assertRedirect(route('admin.productions.show', $production))
            ->assertSessionHasErrors('partial_quantity');

        $production = $production->fresh();
        $this->assertSame(0.0, (float) $production->completed_quantity);
        $this->assertSame(80.0, (float) $production->remaining_quantity);
    }

    public function test_completed_action_finishes_only_target_print_and_sets_completed_at(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();
        $firstProduction = $productions['Tek taraf']->fresh();
        $secondProduction = $productions['Gövde']->fresh();

        $this->prepareProductionForStart($firstProduction, $graphics['1a'], 'complete-ready-1a.jpg');
        $this->prepareProductionForStart($secondProduction, $graphics['1b'], 'complete-ready-1b.jpg');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $secondProduction), [
                'action' => 'completed',
            ])
            ->assertRedirect(route('admin.productions.show', $secondProduction));

        $firstProduction = $firstProduction->fresh();
        $secondProduction = $secondProduction->fresh(['workForm.activityLogs']);

        $this->assertSame(0.0, (float) $firstProduction->completed_quantity);
        $this->assertSame(80.0, (float) $firstProduction->remaining_quantity);
        $this->assertSame(80.0, (float) $secondProduction->completed_quantity);
        $this->assertSame(0.0, (float) $secondProduction->remaining_quantity);
        $this->assertSame(OrderItemPrintProduction::STATUS_COMPLETED, $secondProduction->production_status);
        $this->assertNotNull($secondProduction->started_at);
        $this->assertNotNull($secondProduction->completed_at);
        $this->assertTrue($secondProduction->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'production_completed'
        ));
    }

    public function test_actions_are_blocked_when_readiness_requirements_are_not_met(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();

        $blockedByProcurement = $productions['Tek taraf']->fresh();
        $this->prepareProductionForStart($blockedByProcurement, $graphics['1a'], 'procurement-block.jpg', OrderItemProcurement::STATUS_PENDING);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $blockedByProcurement))
            ->patch(route('admin.productions.update-status', $blockedByProcurement), [
                'action' => 'partial',
                'partial_quantity' => '10',
            ])
            ->assertRedirect(route('admin.productions.show', $blockedByProcurement))
            ->assertSessionHasErrors(['partial_quantity' => 'Ürün tedariki tamamlanmadan üretime başlanamaz.']);

        $blockedByRevision = $productions['Gövde']->fresh(['orderItemPrint.graphicOperation']);
        $blockedByRevision->orderItemPrint->graphicOperation->forceFill([
            'status' => OrderItemPrintGraphic::STATUS_REVISION_REQUESTED,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $blockedByRevision))
            ->patch(route('admin.productions.update-status', $blockedByRevision), [
                'action' => 'completed',
            ])
            ->assertRedirect(route('admin.productions.show', $blockedByRevision))
            ->assertSessionHasErrors(['action' => 'Bu baskı revize bekliyor, üretime başlanamaz.']);

        $missingFinal = $productions['Tek taraf']->fresh(['orderItemPrint.graphicOperation']);
        $missingFinal->orderItemPrint->graphicOperation->forceFill([
            'status' => OrderItemPrintGraphic::STATUS_APPROVED,
            'latest_attachment_id' => null,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $missingFinal))
            ->patch(route('admin.productions.update-status', $missingFinal), [
                'action' => 'completed',
            ])
            ->assertRedirect(route('admin.productions.show', $missingFinal))
            ->assertSessionHasErrors(['action' => 'Final grafik görseli olmadan üretime başlanamaz.']);
    }

    private function createMultiPrintWorkForm(): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-PC-' . random_int(1000, 9999),
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
            'product_name' => 'Partial Workflow Product',
            'product_code' => 'PC-001',
            'quantity' => 80,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'Partial Workflow Product',
                'product_code' => 'PC-001',
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
        string $fileName,
        string $procurementStatus = OrderItemProcurement::STATUS_FULLY_RECEIVED
    ): void {
        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Partial workflow test graphic',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm->procurement;
        $this->assertNotNull($procurement);

        $statusLabel = match ($procurementStatus) {
            OrderItemProcurement::STATUS_FULLY_RECEIVED => 'Tamamı Geldi',
            OrderItemProcurement::STATUS_PENDING => 'Tedarik Bekliyor',
            default => 'Bekliyor',
        };

        $procurement->forceFill([
            'procurement_status' => $procurementStatus,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $production->workForm->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => $procurementStatus,
                    'procurement_status_label' => $statusLabel,
                    'public_status_label' => $procurementStatus === OrderItemProcurement::STATUS_FULLY_RECEIVED
                        ? 'Ürün üretime hazır'
                        : 'Ürün bekleniyor',
                    'received_quantity' => $procurementStatus === OrderItemProcurement::STATUS_FULLY_RECEIVED ? 80 : 0,
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }
}
