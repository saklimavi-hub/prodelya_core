<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Notifications\NotificationEventService;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\ProductionWorkflowService;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
    }

    public function test_production_notifications_emit_safely_and_do_not_break_workflow(): void
    {
        $productionUser = $this->createTenantUserWithRole('production.notification@prodelya.local', 'production');

        ['production' => $production] = $this->createReadyProduction('SP-PROD-NOTIF-001');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $production), [
                'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
                'production_unit_name' => 'UV Hattı 1',
                'assigned_to' => $this->adminUser->id,
                'production_note' => 'Başlatma notu C:\\secret\\path.ai ve group_code olmamali',
            ])
            ->assertRedirect(route('admin.productions.operator', $production));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'assign_internal',
                'production_unit_name' => 'UV Hattı 1',
                'note' => 'Başlatma notu C:\\secret\\path.ai ve group_code olmamali',
            ])
            ->assertRedirect(route('admin.productions.operator', $production));

        $startedLogs = NotificationLog::query()
            ->where('notification_key', 'production_started')
            ->where('related_id', $production->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $startedLogs);
        $this->assertTrue($startedLogs->contains(fn (NotificationLog $log) => $log->channel === 'internal' && $log->status === NotificationLog::STATUS_SENT));
        $this->assertTrue($startedLogs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($startedLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $productionUser->email));
        $this->assertLogsAreSafe($startedLogs);

        UserRole::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('user_id', $productionUser->id)
            ->delete();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production->fresh()), [
                'action' => 'partial',
                'partial_quantity' => '25',
                'note' => 'Kısmi tamamlandı / fiziksel yol görünmemeli',
            ])
            ->assertRedirect(route('admin.productions.operator', $production));

        $partialLogs = NotificationLog::query()
            ->where('notification_key', 'production_partially_completed')
            ->where('related_id', $production->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $partialLogs);
        $this->assertTrue($partialLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $this->adminUser->email));
        $this->assertLogsAreSafe($partialLogs);

        $partialLogPayload = $partialLogs->map(fn (NotificationLog $log) => (string) $log->message_preview)->implode("\n");
        $this->assertStringContainsString('25', $partialLogPayload);
        $this->assertStringContainsString('75', $partialLogPayload);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production->fresh()), [
                'action' => 'issue',
                'note' => 'Baskı yüzeyinde sorun var. file_path veya maliyet yazma.',
            ])
            ->assertRedirect(route('admin.productions.operator', $production));

        $problemLogs = NotificationLog::query()
            ->where('notification_key', 'production_problem_reported')
            ->where('related_id', $production->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $problemLogs);
        $this->assertLogsAreSafe($problemLogs);

        ['production' => $completedProduction] = $this->createReadyProduction('SP-PROD-NOTIF-002');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $completedProduction), [
                'action' => 'completed',
                'note' => 'Tamamlandı',
            ])
            ->assertRedirect(route('admin.productions.show', $completedProduction));

        $completedLogs = NotificationLog::query()
            ->where('notification_key', 'production_completed')
            ->where('related_id', $completedProduction->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $completedLogs);
        $this->assertLogsAreSafe($completedLogs);

        $beforeRenderCount = NotificationLog::query()->count();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'))
            ->assertOk();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production->fresh()))
            ->assertOk();

        $this->assertSame($beforeRenderCount, NotificationLog::query()->count());
        $this->assertSame(0, NotificationLog::query()->where('notification_key', 'production_ready')->count());

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new \RuntimeException('production notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        ['production' => $failureProduction] = $this->createReadyProduction('SP-PROD-NOTIF-003');

        app(ProductionWorkflowService::class)->updateAssignment($failureProduction->fresh(), [
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_unit_name' => 'UV Hattı 2',
            'assigned_to' => $this->adminUser->id,
        ], $this->adminUser, 'Failure safe assignment');

        $failedStarted = app(ProductionWorkflowService::class)->assignInternal(
            $failureProduction->fresh(),
            $this->adminUser,
            'UV Hattı 2',
            'Failure safe start'
        );

        $this->assertSame(OrderItemPrintProduction::STATUS_INTERNAL, $failedStarted->production_status);

        $publicResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $production->fresh('workForm')->workForm->public_tracking_token));

        $publicResponse->assertOk();
        $publicResponse->assertDontSee('group_code', false);
        $publicResponse->assertDontSee('file_path', false);
        $publicResponse->assertDontSee('physical_path', false);
    }

    private function assertLogsAreSafe($logs): void
    {
        $serialized = $logs->map(function (NotificationLog $log): string {
            return (string) $log->subject
                . "\n"
                . (string) $log->message_preview
                . "\n"
                . json_encode($log->meta_json, JSON_UNESCAPED_UNICODE);
        })->implode("\n");

        foreach ([
            'subcontractor_cost',
            'supplier_cost',
            'unit_price',
            'print_unit_price',
            'print_total',
            'line_total',
            'vat_total',
            'grand_total',
            'balance',
            'profit',
            'group_code',
            'file_path',
            'physical_path',
            'pdh_raw',
            'raw_xml',
            'raw_json',
            'storage/app',
            'C:\\',
            '/var/',
            'KDV',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    private function createTenantUserWithRole(string $email, string $roleKey): User
    {
        $user = User::query()->create([
            'name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }

    private function createReadyProduction(string $documentNumber): array
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-18',
                'valid_until' => '2026-06-25',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Production notification payload',
                'items' => [[
                    'product_name' => 'Production Notification Urunu',
                    'product_code' => $documentNumber . '-PRD',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '125',
                    'discount_rate' => '0',
                    'unit_price' => '125',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '100',
                        'print_unit_price' => '15',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $production = OrderItemPrintProduction::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->with(['workForm.procurement', 'orderItemPrint.graphicOperation'])
            ->latest('id')
            ->firstOrFail();

        $this->prepareProductionForStart($production, 'production-ready-' . $production->id . '.jpg');

        return [
            'production' => $production->fresh(['workForm', 'orderItemPrint.graphicOperation', 'orderItem', 'order']),
            'quote' => $quote->fresh(),
        ];
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
                'note' => 'Production final görsel C:\\raw\\design.ai',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
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
                    'procurement_status_label' => 'Tamamı Geldi',
                    'public_status_label' => 'Ürün üretime hazır',
                    'received_quantity' => 100,
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }
}
