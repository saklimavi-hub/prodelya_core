<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
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

class ProductionUiTest extends TestCase
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

    public function test_production_index_renders_real_records_and_sidebar_route_without_financial_or_technical_leaks(): void
    {
        $production = $this->createProductionRecord([
            'product_name' => 'UI Üretim Kalemi',
            'product_code' => 'UI-PRD-001',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'));

        $response->assertOk();
        $response->assertSee('Üretim Yönetimi');
        $response->assertSee(route('admin.productions.index'), false);
        $response->assertSee($production->order->document_number);
        $response->assertSee($production->workForm->work_form_number);
        $response->assertSee($this->customer->legal_name);
        $response->assertSee('UI Üretim Kalemi');
        $response->assertSee('UI-PRD-001');
        $response->assertSee('UV Baskı');
        $response->assertSee('Grafik henüz üretime hazır değil.');
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('KDV', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('print_unit_price', false);
    }

    public function test_production_show_renders_snapshot_assignment_forms_links_and_upload_form(): void
    {
        $production = $this->createProductionRecord([
            'product_name' => 'Detay Üretim Ürünü',
            'product_code' => 'UI-DET-PRD',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production));

        $response->assertOk();
        $response->assertSee('Üretim Detayı');
        $response->assertSee('Detay Üretim Ürünü');
        $response->assertSee('UI-DET-PRD');
        $response->assertDontSee('Üretim Ataması');
        $response->assertDontSee('Üretime Başlama Kontrol Kartları');
        $response->assertSee('Üretim Durumu Adımları');
        $response->assertSee('Kalite Kontrol');
        $response->assertSee('Fotoğraf Ekle');
        $response->assertSee(route('admin.work-forms.show', $production->workForm), false);
        $response->assertSee(route('admin.orders.show', $production->order), false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_tenant_external_production_access_returns_403(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Second Tenant',
            'legal_name' => 'Second Tenant Ltd.',
            'slug' => 'second-tenant-production',
            'panel_subdomain' => 'second-tenant-production',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-OTHER-PRD',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_name' => 'Other Tenant Product',
            'product_code' => 'OTH-PRD-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'has_print' => true,
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 10,
        ]);

        $production = OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'planned_quantity' => 10,
            'remaining_quantity' => 10,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'qc_status' => OrderItemPrintProduction::QC_WAITING,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production))
            ->assertForbidden();
    }

    public function test_assignment_and_status_actions_update_snapshot_version_and_logs(): void
    {
        $partner = $this->createPartnerCompany();
        $production = $this->createProductionRecord([
            'product_name' => 'Aksiyon Üretim Ürünü',
            'product_code' => 'UI-ACT-PRD',
        ]);
        $this->prepareProductionForStart($production, 'ui-act-ready.jpg');
        $production = $production->fresh(['workForm.activityLogs']);

        $initialVersion = $production->workForm->version;

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $production), [
                'production_type' => 'internal',
                'production_unit_name' => 'UV Hattı 1',
                'assigned_to' => $this->adminUser->id,
                'cliche_required' => '1',
                'cliche_status' => OrderItemPrintProduction::CLICHE_READY,
                'production_note' => 'İç üretim hazırlığı tamam.',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $production = $production->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $production->production_type);
        $this->assertSame('UV Hattı 1', $production->production_unit_name);
        $this->assertSame($this->adminUser->id, $production->assigned_to);
        $this->assertSame(OrderItemPrintProduction::CLICHE_READY, $production->cliche_status);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'assign_external',
                'production_company_id' => $partner->id,
                'note' => 'Fason hazırlığı',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'sent_to_subcontractor',
                'note' => 'Fasona çıktı',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'returned_from_subcontractor',
                'note' => 'Fasondan döndü',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'qc_started',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'qc_passed',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'completed',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $production = $production->fresh(['workForm.activityLogs']);

        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $production->production_type);
        $this->assertSame($partner->id, $production->production_company_id);
        $this->assertSame(OrderItemPrintProduction::STATUS_COMPLETED, $production->production_status);
        $this->assertSame(OrderItemPrintProduction::QC_OK, $production->qc_status);
        $this->assertSame((float) $production->planned_quantity, (float) $production->completed_quantity);
        $this->assertSame(0.0, (float) $production->remaining_quantity);
        $this->assertGreaterThan($initialVersion, $production->workForm->version);
        $this->assertSame('Üretim tamamlandı', data_get($production->workForm->production_snapshot, 'public_status_label'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_assigned_internal'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_assigned_external'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_sent_to_subcontractor'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_returned_from_subcontractor'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_qc_started'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_qc_passed'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_completed'));
    }

    public function test_completed_quantity_overflow_is_blocked(): void
    {
        $production = $this->createProductionRecord([
            'product_name' => 'Validation Production',
            'product_code' => 'UI-VAL-PRD',
        ]);
        $this->prepareProductionForStart($production, 'ui-overflow-ready.jpg');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $production))
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'completed',
                'completed_quantity' => '1000',
            ])
            ->assertRedirect(route('admin.productions.show', $production))
            ->assertSessionHasErrors('completed_quantity');

        $production = $production->fresh();
        $this->assertSame(0.0, (float) $production->completed_quantity);
        $this->assertSame((float) $production->planned_quantity, (float) $production->remaining_quantity);
    }

    public function test_production_photo_upload_works_via_existing_work_form_attachment_infrastructure(): void
    {
        $production = $this->createProductionRecord([
            'product_name' => 'Photo Production',
            'product_code' => 'UI-PHOTO-PRD',
        ]);

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production));

        $show->assertOk();
        $show->assertSee('Fotoğraf Ekle');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $production->workForm), [
                'attachment_type' => 'production_photo',
                'visibility' => 'internal',
                'note' => 'UI üretim fotoğrafı',
                'file' => UploadedFile::fake()->image('production-ui.jpg'),
            ])
            ->assertRedirect(route('admin.work-forms.show', $production->workForm));

        $production = $production->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $this->assertSame(1, $production->workForm->productionPhotos()->count());
        $this->assertSame(1, (int) data_get($production->workForm->production_snapshot, 'photo_count'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_photo_added'));
    }

    private function createProductionRecord(array $itemOverrides = []): OrderItemPrintProduction
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-PRD-' . random_int(1000, 9999),
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
            'product_name' => 'UI Production Product',
            'product_code' => 'UI-PROD-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'UI Production Product',
                'product_code' => 'UI-PROD-001',
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
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
        ], $itemOverrides));

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

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return OrderItemPrintProduction::query()
            ->with(['workForm.procurement', 'order', 'order.customer', 'orderItem', 'orderItemPrint.graphicOperation'])
            ->latest('id')
            ->firstOrFail();
    }

    private function prepareProductionForStart(OrderItemPrintProduction $production, string $fileName): void
    {
        $production = $production->fresh(['workForm.procurement', 'orderItemPrint.graphicOperation']);

        /** @var OrderItemPrintGraphic $graphic */
        $graphic = $production->orderItemPrint?->graphicOperation;
        $this->assertNotNull($graphic);

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Test final görseli',
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
            'legal_name' => 'UI Production Partner',
            'short_name' => 'UI Partner',
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
