<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InternalOperatorFlowTest extends TestCase
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

    public function test_internal_pool_ready_cta_opens_operator_screen_with_exact_print_identity(): void
    {
        $production = $this->createProductionRecord('1a', [
            'product_name' => 'Operatör Kalemi',
            'product_code' => 'OP-001',
        ]);
        $this->prepareProductionForStart($production, 'operator-final.png');
        $this->createPartnerCompany();
        $production = $production->fresh(['orderItemPrint']);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index', ['route' => 'internal']));

        $response->assertOk();
        $response->assertSee(route('admin.productions.operator', $production), false);
        $response->assertSee(route('admin.productions.operator', $production) . '#route-transfer-panel', false);
        $response->assertSee('pd-production-row__secondary-action', false);
        $response->assertSee('pd-production-btn--primary', false);

        $operator = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));

        $operator->assertOk();
        $operator->assertSee('İç Üretim Operatörü');
        $operator->assertSee('Operatör Kalemi');
        $operator->assertSee('OP-001');
        $operator->assertSee('1a');
        $operator->assertSee('Operatör Seç');
        $operator->assertSee('pd-internal-operator__route-transfer-trigger', false);
        $operator->assertSee('pd-internal-operator__route-transfer-panel', false);
        $operator->assertSee('name="production_type" value="' . OrderItemPrintProduction::TYPE_OUTSOURCED . '"', false);
        $operator->assertSee('name="return_to" value="subcontract_assignment"', false);
        $operator->assertSee('name="route_change_reason"', false);
        $operator->assertDontSee('Üretime Başla');
        $operator->assertSee('data-print-row-id="'.$production->order_item_print_id.'"', false);
        $this->assertSame(1, substr_count($operator->getContent(), 'pd-internal-operator__primary-action'));
        $operator->assertDontSee('price_snapshot', false);
        $operator->assertDontSee('subcontractor_cost', false);
        $operator->assertDontSee('current_account', false);
    }

    public function test_operator_rejects_outsourced_production_jobs(): void
    {
        $production = $this->createProductionRecord('2a');
        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production))
            ->assertNotFound();
    }

    public function test_operator_requires_explicit_assignment_before_start_and_returns_to_operator(): void
    {
        $production = $this->createProductionRecord('1b');
        $this->prepareProductionForStart($production, 'operator-start.png');

        $blocked = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'assign_internal',
                'production_unit_name' => 'UV İç Hat',
                'return_to' => 'operator',
            ]);

        $blocked->assertSessionHasErrors('action');
        $production->refresh();
        $this->assertNull($production->assigned_to);
        $this->assertNull($production->started_at);

        $assignment = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $production), [
                'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
                'assigned_to' => $this->adminUser->id,
                'production_unit_name' => 'UV İç Hat',
                'return_to' => 'operator',
            ]);

        $assignment->assertRedirect(route('admin.productions.operator', $production));
        $production->refresh();
        $this->assertSame($this->adminUser->id, $production->assigned_to);
        $this->assertSame(OrderItemPrintProduction::STATUS_PENDING, $production->production_status);
        $this->assertNull($production->started_at);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'assign_internal',
                'return_to' => 'operator',
            ]);

        $response->assertRedirect(route('admin.productions.operator', $production));

        $production->refresh();
        $this->assertSame(OrderItemPrintProduction::STATUS_INTERNAL, $production->production_status);
        $this->assertSame('UV İç Hat', $production->production_unit_name);
        $this->assertSame($this->adminUser->id, $production->assigned_to);
        $this->assertNotNull($production->started_at);

        $actions = OrderItemWorkFormActivityLog::query()
            ->where('work_form_id', $production->work_form_id)
            ->pluck('action_type')
            ->all();

        $this->assertContains('production_assigned_internal', $actions);
        $this->assertContains('production_started', $actions);
    }

    public function test_operator_result_screen_preserves_quantity_semantics(): void
    {
        $production = $this->createProductionRecord('1c');
        $this->prepareProductionForStart($production, 'operator-result.png');
        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_INTERNAL,
            'assigned_to' => $this->adminUser->id,
            'started_at' => now(),
            'completed_quantity' => 30,
            'remaining_quantity' => 70,
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));

        $response->assertOk();
        $response->assertSee('Üretim Sonucu Gir');
        $response->assertSee('Planlanan');
        $response->assertSee('Tamamlanan');
        $response->assertSee('Kalan');
        $response->assertSee('name="partial_quantity"', false);
        $response->assertSee('name="completed_quantity" value="100"', false);
        $response->assertSee('accept="image/*"', false);
        $response->assertSee('capture="environment"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'pd-internal-operator__primary-action'));
    }

    public function test_operator_completed_job_is_read_only(): void
    {
        $production = $this->createProductionRecord('1d');
        $this->prepareProductionForStart($production, 'operator-complete.png');
        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'assigned_to' => $this->adminUser->id,
            'completed_quantity' => 100,
            'remaining_quantity' => 0,
            'completed_at' => now(),
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));

        $response->assertOk();
        $response->assertSee('Üretim tamamlandı');
        $response->assertSee('Bu exact baskı işi salt okunur.');
        $response->assertDontSee('name="partial_quantity"', false);
        $response->assertDontSee('Fasona Devret');
    }

    public function test_operator_preview_uses_exact_secure_graphic_and_history_labels_are_turkish(): void
    {
        $production = $this->createProductionRecord('1e', [
            'product_name' => 'Grafikli Operatör Ürünü',
            'product_code' => 'OP-GFX-001',
        ]);
        $this->prepareProductionForStart($production, 'operator-exact-final.png');
        $production = $production->fresh(['workForm.activityLogs', 'orderItemPrint.graphicOperation.latestAttachment']);
        $attachment = $production->orderItemPrint?->graphicOperation?->latestAttachment;

        $this->assertNotNull($attachment);

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'work_form_id' => $production->work_form_id,
            'order_id' => $production->order_id,
            'order_item_id' => $production->order_item_id,
            'action_type' => 'Production Partially Completed',
            'note' => '100 adet kısmi üretim girildi.',
            'visibility' => 'internal',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));

        $response->assertOk();
        $response->assertSee(route('admin.work-forms.attachments.preview', $attachment), false);
        $response->assertSee('data-operator-graphic-preview', false);
        $response->assertSee('Kısmi üretim kaydedildi');
        $response->assertDontSee('Production Partially Completed');
        $response->assertDontSee('product image', false);

    }

    public function test_operator_falls_back_to_assignment_activity_creator_for_existing_unassigned_started_jobs(): void
    {
        $production = $this->createProductionRecord('1f');
        $this->prepareProductionForStart($production, 'operator-history-user.png');
        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            'assigned_to' => null,
            'started_at' => now(),
            'completed_quantity' => 100,
            'remaining_quantity' => 150,
            'planned_quantity' => 250,
        ])->save();

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'work_form_id' => $production->work_form_id,
            'order_id' => $production->order_id,
            'order_item_id' => $production->order_item_id,
            'action_type' => 'production_assigned_internal',
            'note' => 'İş iç üretime atandı.',
            'visibility' => 'internal',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));

        $response->assertOk();
        $response->assertSee('<div><span>Operatör</span><strong>'.$this->adminUser->name.'</strong></div>', false);
        $response->assertSee('Kalanı Tamamla');
        $response->assertSee('150 Adet kaldı');
    }

    public function test_partial_route_transfer_preserves_exact_production_row_and_requires_reason(): void
    {
        $production = $this->createProductionRecord('2a');
        $this->prepareProductionForStart($production, 'operator-transfer.png');
        $partner = $this->createPartnerCompany();
        $accountCount = CurrentAccountTransaction::query()->count();

        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            'assigned_to' => $this->adminUser->id,
            'started_at' => now(),
            'completed_quantity' => 25,
            'remaining_quantity' => 75,
            'planned_quantity' => 100,
        ])->save();

        $blocked = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $production), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
            ]);

        $blocked->assertSessionHasErrors('production_note');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $production), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
                'route_change_reason' => 'Operasyon kapasitesi nedeniyle fasona alındı.',
            ]);

        $response->assertRedirect(route('admin.productions.subcontract-assignment', $production));

        $production->refresh();
        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $production->production_type);
        $this->assertSame($partner->id, $production->production_company_id);
        $this->assertNull($production->assigned_to);
        $this->assertSame(25.0, (float) $production->completed_quantity);
        $this->assertSame(75.0, (float) $production->remaining_quantity);
        $this->assertSame(OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED, $production->production_status);
        $this->assertSame($accountCount, CurrentAccountTransaction::query()->count());
        $this->assertSame(1, OrderItemPrintProduction::query()->where('order_item_print_id', $production->order_item_print_id)->count());
        $this->assertDatabaseHas('order_item_print_productions', [
            'id' => $production->id,
            'order_item_print_id' => $production->order_item_print_id,
            'work_form_id' => $production->work_form_id,
        ]);
        $this->assertDatabaseHas('order_item_work_form_activity_logs', [
            'work_form_id' => $production->work_form_id,
            'action_type' => 'production_route_changed',
        ]);
    }

    public function test_completed_or_cancelled_production_cannot_transfer_route(): void
    {
        $production = $this->createProductionRecord('2b');
        $partner = $this->createPartnerCompany();
        $accountCount = CurrentAccountTransaction::query()->count();
        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'completed_quantity' => 100,
            'remaining_quantity' => 0,
            'completed_at' => now(),
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $production), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
                'route_change_reason' => 'Tamamlanan işte rota denenmemeli.',
            ]);

        $response->assertSessionHasErrors('production_note');
        $production->refresh();
        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $production->production_type);
        $this->assertNull($production->production_company_id);
    }

    public function test_operator_photo_upload_returns_to_operator_and_uses_work_form_attachment_store(): void
    {
        $production = $this->createProductionRecord('2c');
        $this->prepareProductionForStart($production, 'operator-photo-graphic.png');
        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'assigned_to' => $this->adminUser->id,
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $production->workForm), [
                'attachment_type' => 'production_photo',
                'visibility' => 'internal',
                'redirect_to' => 'admin.productions.operator',
                'redirect_production_id' => $production->id,
                'note' => 'Operatör fotoğrafı',
                'file' => UploadedFile::fake()->image('operator-production-photo.jpg'),
            ]);

        $response->assertRedirect(route('admin.productions.operator', $production));

        $attachment = OrderItemWorkFormAttachment::query()
            ->where('work_form_id', $production->work_form_id)
            ->where('attachment_type', 'production_photo')
            ->latest('id')
            ->first();

        $this->assertNotNull($attachment);
        $this->assertSame('operator-production-photo.jpg', $attachment->file_name);

        $operator = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.operator', $production));

        $operator->assertOk();
        $operator->assertSee('operator-production-photo.jpg');

        $workForm = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $production->workForm));

        $workForm->assertOk();
        $workForm->assertSee('operator-production-photo.jpg');
    }

    private function createProductionRecord(string $printSequence, array $itemOverrides = []): OrderItemPrintProduction
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

        $item = OrderItem::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'customer_supplied',
            'product_name' => 'Operator Product',
            'product_code' => 'OP-PROD-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'Operator Product',
                'product_code' => 'OP-PROD-001',
            ],
            'price_snapshot' => [
                'unit_price' => 55,
                'line_total' => 1100,
            ],
            'has_print' => true,
            'print_total' => 100,
            'status' => 'pending',
        ], $itemOverrides));

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_sequence' => $printSequence,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'print_quantity' => 100,
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'note' => 'Operator flow baskı',
            'status' => 'draft',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return OrderItemPrintProduction::query()
            ->with(['workForm.procurement', 'order.customer', 'orderItem', 'orderItemPrint.graphicOperation.latestAttachment'])
            ->latest('id')
            ->firstOrFail();
    }

    private function prepareProductionForStart(OrderItemPrintProduction $production, string $fileName): void
    {
        $production = $production->fresh(['workForm.procurement', 'orderItemPrint.graphicOperation']);
        $graphic = $production->orderItemPrint?->graphicOperation;

        $this->assertNotNull($graphic);

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Operator final görseli',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        $graphic = $graphic->fresh();
        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic, $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm?->procurement;
        $this->assertNotNull($procurement);

        $procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $production->workForm?->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'public_status_label' => 'Ürün üretime hazır',
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }

    private function createPartnerCompany(): Company
    {
        $existing = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->active()
            ->whereHas('companyRoles', fn ($query) => $query->whereIn('role_key', ['print_fason', 'production_partner']))
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Operator Flow Fason Partner',
            'short_name' => 'Operator Fason',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => 'print_fason',
        ]);

        return $company;
    }
}
