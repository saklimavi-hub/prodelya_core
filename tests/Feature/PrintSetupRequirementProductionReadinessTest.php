<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemPrintSetupRequirement;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\ProductionReadinessResolver;
use App\Services\TenantPrintSettingSyncService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintSetupRequirementProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private User $productionUser;
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
        $this->productionUser = $this->createUserWithRole('production', 'readiness-production');

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_pending_or_requested_setup_blocks_only_related_print_and_actions(): void
    {
        [
            'workForm' => $workForm,
            'setupProduction' => $setupProduction,
            'plainProduction' => $plainProduction,
            'requirements' => $requirements,
        ] = $this->createConvertedWorkFormWithSetupPrint([
            OrderItemPrintSetupRequirement::TYPE_CLICHE => OrderItemPrintSetupRequirement::STATUS_PENDING,
            OrderItemPrintSetupRequirement::TYPE_FILM => OrderItemPrintSetupRequirement::STATUS_REQUESTED,
        ]);

        $this->prepareProductionForStart($setupProduction, 'setup-blocked-final.jpg');
        $this->prepareProductionForStart($plainProduction, 'plain-ready-final.jpg');

        $resolver = app(ProductionReadinessResolver::class);
        $setupReadiness = $resolver->resolve($setupProduction->fresh([
            'graphicOperation.latestAttachment',
            'orderItemPrint.setupRequirements',
            'workForm.procurement',
            'workForm.attachments',
        ]));
        $plainReadiness = $resolver->resolve($plainProduction->fresh([
            'graphicOperation.latestAttachment',
            'orderItemPrint.setupRequirements',
            'workForm.procurement',
            'workForm.attachments',
        ]));

        $this->assertFalse($setupReadiness['can_start']);
        $this->assertSame('Hazırlık bekleniyor: Klişe, Film', $setupReadiness['blocking_reason_label']);
        $this->assertSame(['Klişe', 'Film'], $setupReadiness['setup_blocking_labels']);
        $this->assertTrue($plainReadiness['can_start']);

        foreach (['assign_internal', 'completed'] as $action) {
            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->from(route('admin.productions.show', $setupProduction))
                ->patch(route('admin.productions.update-status', $setupProduction), [
                    'action' => $action,
                    'production_unit_name' => 'UV Hattı 1',
                ])
                ->assertRedirect(route('admin.productions.show', $setupProduction))
                ->assertSessionHasErrors(['action' => 'Hazırlık bekleniyor: Klişe, Film']);
        }

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $setupProduction))
            ->patch(route('admin.productions.update-status', $setupProduction), [
                'action' => 'partial',
                'partial_quantity' => '10',
            ])
            ->assertRedirect(route('admin.productions.show', $setupProduction))
            ->assertSessionHasErrors(['partial_quantity' => 'Hazırlık bekleniyor: Klişe, Film']);

        $response = $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $setupProduction->fresh()));

        $response->assertOk();
        $response->assertSee('Hazırlık bekliyor');
        $response->assertSee('Sorun Bildir');
        $response->assertSee('Fotoğraf Ekle');
        $response->assertSee('Hazırlık / Ara Eleman');
        $response->assertSee('Klişe');
        $response->assertSee('Film');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);

        $this->assertSame($workForm->id, $setupProduction->work_form_id);
        $this->assertCount(2, $requirements);
    }

    public function test_ready_cancelled_and_not_required_setups_release_readiness_and_actions(): void
    {
        [
            'setupProduction' => $setupProduction,
            'requirements' => $requirements,
        ] = $this->createConvertedWorkFormWithSetupPrint([
            OrderItemPrintSetupRequirement::TYPE_CLICHE => OrderItemPrintSetupRequirement::STATUS_PENDING,
        ]);

        $this->prepareProductionForStart($setupProduction, 'setup-ready-flow.jpg');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.productions.show', $setupProduction))
            ->patch(route('admin.productions.update-status', $setupProduction), [
                'action' => 'assign_internal',
                'production_unit_name' => 'UV Hattı 2',
            ])
            ->assertRedirect(route('admin.productions.show', $setupProduction))
            ->assertSessionHasErrors(['action' => 'Hazırlık bekleniyor: Klişe']);

        $requirement = $requirements->first();
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.print-setup-requirements.ready', $requirement))
            ->assertRedirect();

        $readiness = app(ProductionReadinessResolver::class)->resolve($setupProduction->fresh([
            'graphicOperation.latestAttachment',
            'orderItemPrint.setupRequirements',
            'workForm.procurement',
            'workForm.attachments',
        ]));

        $this->assertTrue($readiness['can_start']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $setupProduction), [
                'action' => 'assign_internal',
                'production_unit_name' => 'UV Hattı 2',
            ])
            ->assertRedirect(route('admin.productions.show', $setupProduction));

        $this->assertSame(
            OrderItemPrintProduction::STATUS_INTERNAL,
            $setupProduction->fresh()->production_status
        );

        [
            'setupProduction' => $cancelledProduction,
            'requirements' => $cancelledRequirements,
        ] = $this->createConvertedWorkFormWithSetupPrint([
            OrderItemPrintSetupRequirement::TYPE_CLICHE => OrderItemPrintSetupRequirement::STATUS_CANCELLED,
        ], 'UV Setup Iptal');
        $this->prepareProductionForStart($cancelledProduction, 'setup-cancelled-flow.jpg');

        $cancelledReadiness = app(ProductionReadinessResolver::class)->resolve($cancelledProduction->fresh([
            'graphicOperation.latestAttachment',
            'orderItemPrint.setupRequirements',
            'workForm.procurement',
            'workForm.attachments',
        ]));
        $this->assertTrue($cancelledReadiness['can_start']);
        $this->assertSame(OrderItemPrintSetupRequirement::STATUS_CANCELLED, $cancelledRequirements->first()->status);

        [
            'setupProduction' => $notRequiredProduction,
            'requirements' => $notRequiredRequirements,
        ] = $this->createConvertedWorkFormWithSetupPrint([
            OrderItemPrintSetupRequirement::TYPE_CLICHE => OrderItemPrintSetupRequirement::STATUS_NOT_REQUIRED,
        ], 'UV Setup Gerekli Degil');
        $this->prepareProductionForStart($notRequiredProduction, 'setup-not-required-flow.jpg');

        $notRequiredReadiness = app(ProductionReadinessResolver::class)->resolve($notRequiredProduction->fresh([
            'graphicOperation.latestAttachment',
            'orderItemPrint.setupRequirements',
            'workForm.procurement',
            'workForm.attachments',
        ]));
        $this->assertTrue($notRequiredReadiness['can_start']);
        $this->assertSame(OrderItemPrintSetupRequirement::STATUS_NOT_REQUIRED, $notRequiredRequirements->first()->status);
    }

    public function test_setup_requirements_override_legacy_cliche_and_legacy_fallback_still_works_without_setup_records(): void
    {
        [
            'setupProduction' => $setupProduction,
            'requirements' => $requirements,
        ] = $this->createConvertedWorkFormWithSetupPrint([
            OrderItemPrintSetupRequirement::TYPE_FILM => OrderItemPrintSetupRequirement::STATUS_READY,
        ], 'UV Setup Override');
        $this->prepareProductionForStart($setupProduction, 'setup-override-ready.jpg');

        $setupProduction->forceFill([
            'cliche_required' => true,
            'cliche_status' => OrderItemPrintProduction::CLICHE_WAITING,
        ])->save();

        $overrideReadiness = app(ProductionReadinessResolver::class)->resolve($setupProduction->fresh([
            'graphicOperation.latestAttachment',
            'orderItemPrint.setupRequirements',
            'workForm.procurement',
            'workForm.attachments',
        ]));

        $this->assertCount(1, $requirements);
        $this->assertTrue($overrideReadiness['can_start']);
        $this->assertNull($overrideReadiness['blocking_reason_label']);

        $legacyProduction = $this->createLegacyProduction();
        $this->prepareProductionForStart($legacyProduction, 'legacy-cliche-blocked.jpg');
        $legacyProduction->forceFill([
            'cliche_required' => true,
            'cliche_status' => OrderItemPrintProduction::CLICHE_WAITING,
        ])->save();

        $legacyReadiness = app(ProductionReadinessResolver::class)->resolve($legacyProduction->fresh([
            'graphicOperation.latestAttachment',
            'orderItemPrint.setupRequirements',
            'workForm.procurement',
            'workForm.attachments',
        ]));

        $this->assertFalse($legacyReadiness['can_start']);
        $this->assertSame('Hazırlık bekleniyor: Klişe', $legacyReadiness['blocking_reason_label']);
        $this->assertSame(['Klişe'], $legacyReadiness['setup_blocking_labels']);
    }

    public function test_work_form_and_public_tracking_show_only_safe_setup_summary(): void
    {
        [
            'workForm' => $workForm,
            'setupProduction' => $setupProduction,
            'requirements' => $requirements,
        ] = $this->createConvertedWorkFormWithSetupPrint([
            OrderItemPrintSetupRequirement::TYPE_CLICHE => OrderItemPrintSetupRequirement::STATUS_PENDING,
        ], 'UV Setup Ozet');

        $this->prepareProductionForStart($setupProduction, 'setup-safe-summary.jpg');

        $requirement = $requirements->first();
        $requirement->forceFill([
            'cost' => 777.77,
            'currency' => 'TRY',
            'note' => 'SETUP-COST-SECRET',
        ])->save();

        $workForm = $workForm->fresh();
        $this->assertStringNotContainsString('777.77', json_encode($workForm->production_snapshot));
        $this->assertSame('Klişe', data_get($workForm->print_snapshot, '0.setup_summary.items.0.setup_type_label'));
        $this->assertStringNotContainsString('777.77', json_encode($workForm->print_snapshot));

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $setupProduction->fresh()))
            ->assertOk()
            ->assertSee('Hazırlık / Ara Eleman')
            ->assertSee('Bekliyor')
            ->assertDontSee('777.77')
            ->assertDontSee('TRY');

        $this->get(route('public.work-forms.track', $workForm->public_tracking_token))
            ->assertOk()
            ->assertDontSee('777.77')
            ->assertDontSee('group_code', false)
            ->assertDontSee('file_path', false)
            ->assertDontSee('physical_path', false);
    }

    private function createConvertedWorkFormWithSetupPrint(array $setupStatuses, string $setupCustomName = 'UV Setup Ayari'): array
    {
        $setupSetting = $this->settingByCode('UV_PRINT');
        $setupSetting->forceFill([
            'custom_name' => $setupCustomName,
            'requires_setup' => true,
            'setup_types' => array_keys($setupStatuses),
        ])->save();

        $plainSetting = $this->settingByCode('LASER_PRINT');
        $plainSetting->forceFill([
            'custom_name' => 'Lazer Setup Yok',
            'requires_setup' => false,
            'setup_types' => [],
        ])->save();

        $quote = $this->makeQuoteWithTwoPrints($setupSetting, $plainSetting);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->firstOrFail();

        $workForm = $order->workForms()->firstOrFail();
        $setupProduction = OrderItemPrintProduction::query()
            ->where('work_form_id', $workForm->id)
            ->whereHas('orderItemPrint', fn ($query) => $query->where('print_type', $setupSetting->displayName()))
            ->firstOrFail();
        $plainProduction = OrderItemPrintProduction::query()
            ->where('work_form_id', $workForm->id)
            ->whereHas('orderItemPrint', fn ($query) => $query->where('print_type', $plainSetting->displayName()))
            ->firstOrFail();

        $requirements = OrderItemPrintSetupRequirement::query()
            ->where('order_item_print_id', $setupProduction->order_item_print_id)
            ->orderBy('setup_type')
            ->get();

        foreach ($requirements as $requirement) {
            if (isset($setupStatuses[$requirement->setup_type])) {
                $requirement->forceFill([
                    'status' => $setupStatuses[$requirement->setup_type],
                    'completed_at' => $setupStatuses[$requirement->setup_type] === OrderItemPrintSetupRequirement::STATUS_READY ? now() : null,
                    'cancelled_at' => $setupStatuses[$requirement->setup_type] === OrderItemPrintSetupRequirement::STATUS_CANCELLED ? now() : null,
                ])->save();
            }
        }

        return [
            'order' => $order,
            'workForm' => $workForm->fresh(),
            'setupProduction' => $setupProduction->fresh(['orderItemPrint.setupRequirements', 'workForm']),
            'plainProduction' => $plainProduction->fresh(['orderItemPrint.setupRequirements', 'workForm']),
            'requirements' => $requirements->fresh(),
        ];
    }

    private function createLegacyProduction(): OrderItemPrintProduction
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'ORD-LEGACY-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Legacy Setup Product',
            'product_code' => 'LEGACY-SETUP-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'has_print' => true,
            'status' => 'pending',
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'Legacy Sıcak Baskı',
            'print_option' => 'Ön yüz',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();

        return OrderItemPrintProduction::query()
            ->where('work_form_id', $workForm->id)
            ->where('order_item_print_id', $print->id)
            ->firstOrFail();
    }

    private function prepareProductionForStart(OrderItemPrintProduction $production, string $fileName): void
    {
        $graphic = $production->graphicOperation()->firstOrFail();

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Setup readiness test graphic',
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
                    'received_quantity' => (float) $production->planned_quantity,
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }

    private function makeQuoteWithTwoPrints(TenantPrintSetting $setupSetting, TenantPrintSetting $plainSetting): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'Q-READY-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = $quote->items()->create([
            'tenant_account_id' => $this->tenant->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Setup Readiness Quote Product',
            'product_code' => 'SETUP-READY-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'line_total' => 1000,
            'has_print' => true,
            'status' => 'pending',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'tenant_print_setting_id' => $setupSetting->id,
            'standard_print_type_id' => $setupSetting->standard_print_type_id,
            'print_type' => $setupSetting->displayName(),
            'print_option' => 'Ön yüz',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'tenant_print_setting_id' => $plainSetting->id,
            'standard_print_type_id' => $plainSetting->standard_print_type_id,
            'print_type' => $plainSetting->displayName(),
            'print_option' => 'Arka yüz',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        return $quote->fresh('items.prints');
    }

    private function settingByCode(string $code): TenantPrintSetting
    {
        return TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Readiness User',
            'email' => $emailPrefix . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }
}
