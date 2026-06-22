<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Notifications\NotificationEventService;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\WorkFormAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GraphicNotificationIntegrationTest extends TestCase
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

    public function test_graphic_upload_revision_and_production_ready_emit_safe_notifications_without_breaking_workflow(): void
    {
        $productionUser = $this->createTenantUserWithRole('production.notification@prodelya.local', 'production');
        $graphicUser = $this->createTenantUserWithRole('graphic.notification@prodelya.local', 'graphic');

        $workForm = $this->createConvertedWorkForm('GR-NOTIF-001');
        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with(['orderItemPrint', 'workForm'])
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');

        $uploadResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $workForm), [
                'attachment_type' => 'graphic_visual',
                'visibility' => 'internal',
                'note' => 'Gizli yol C:\\secret\\final.ai ve group_code hidden olmamali',
                'order_item_print_graphic_id' => $graphics['1a']->id,
                'file' => UploadedFile::fake()->image('graphic-final.webp'),
            ]);

        $uploadResponse->assertRedirect();

        $uploadLogs = NotificationLog::query()
            ->where('notification_key', 'graphic_visual_uploaded')
            ->where('related_id', $graphics['1a']->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $uploadLogs);
        $this->assertTrue($uploadLogs->contains(fn (NotificationLog $log) => $log->channel === 'internal' && $log->status === NotificationLog::STATUS_SENT));
        $this->assertTrue($uploadLogs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($uploadLogs->every(fn (NotificationLog $log) => $log->notification_key === 'graphic_visual_uploaded'));
        $this->assertTrue($uploadLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $productionUser->email));
        $this->assertLogsAreSafe($uploadLogs);

        $revisioned = app(OrderItemPrintGraphicWorkflowService::class)->requestRevision(
            $graphics['1a']->fresh(),
            'Revize lazim. file_path veya maliyet yok.',
            $this->adminUser
        );

        $this->assertSame(OrderItemPrintGraphic::STATUS_REVISION_REQUESTED, $revisioned->status);

        $revisionLogs = NotificationLog::query()
            ->where('notification_key', 'graphic_revision_requested')
            ->where('related_id', $graphics['1a']->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $revisionLogs);
        $this->assertTrue($revisionLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $graphicUser->email));
        $this->assertLogsAreSafe($revisionLogs);

        UserRole::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('user_id', $productionUser->id)
            ->delete();

        $attachmentService = app(WorkFormAttachmentService::class);
        $graphicWorkflow = app(OrderItemPrintGraphicWorkflowService::class);
        $secondGraphic = $graphics['1b']->fresh();

        $attachmentService->attachGraphicVisualToPrintGraphic(
            $secondGraphic,
            UploadedFile::fake()->image('ready-final.jpg'),
            ['visibility' => 'internal', 'note' => 'Ready final'],
            $this->adminUser
        );

        $graphicWorkflow->markApproved($secondGraphic->fresh(), $this->adminUser);
        $readyGraphic = $graphicWorkflow->markProductionReady($secondGraphic->fresh(), $this->adminUser);

        $this->assertSame(OrderItemPrintGraphic::STATUS_PRODUCTION_READY, $readyGraphic->status);

        $readyLogs = NotificationLog::query()
            ->where('notification_key', 'graphic_production_ready')
            ->where('related_id', $secondGraphic->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $readyLogs);
        $this->assertTrue($readyLogs->contains(fn (NotificationLog $log) => $log->channel === 'internal' && $log->status === NotificationLog::STATUS_SENT));
        $this->assertTrue($readyLogs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($readyLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $this->adminUser->email));
        $this->assertLogsAreSafe($readyLogs);

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new \RuntimeException('graphic notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $failureWorkForm = $this->createConvertedWorkForm('GR-NOTIF-002');
        $failureGraphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $failureWorkForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $failedAttachment = app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $failureGraphic,
            UploadedFile::fake()->image('failure-safe.jpg'),
            ['visibility' => 'internal', 'note' => 'Failure safe upload'],
            $this->adminUser
        );

        $this->assertNotNull($failedAttachment->id);
        $this->assertSame(OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED, $failureGraphic->fresh()->status);

        $publicResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $workForm->fresh()->public_tracking_token));

        $publicResponse->assertOk();
        $publicResponse->assertDontSee('file_path', false);
        $publicResponse->assertDontSee('physical_path', false);
        $publicResponse->assertDontSee('group_code', false);
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

        $this->assertStringNotContainsString('print_unit_price', $serialized);
        $this->assertStringNotContainsString('print_total', $serialized);
        $this->assertStringNotContainsString('setup_cost', $serialized);
        $this->assertStringNotContainsString('subcontractor_cost', $serialized);
        $this->assertStringNotContainsString('supplier_cost', $serialized);
        $this->assertStringNotContainsString('group_code', $serialized);
        $this->assertStringNotContainsString('file_path', $serialized);
        $this->assertStringNotContainsString('physical_path', $serialized);
        $this->assertStringNotContainsString('pdh_raw', $serialized);
        $this->assertStringNotContainsString('storage/app', $serialized);
        $this->assertStringNotContainsString('C:\\', $serialized);
        $this->assertStringNotContainsString('/var/', $serialized);
        $this->assertStringNotContainsString('1440', $serialized);
        $this->assertStringNotContainsString('KDV', $serialized);
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

    private function createConvertedWorkForm(string $productCode): OrderItemWorkForm
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
                'notes' => 'Graphic notification payload',
                'items' => [[
                    'product_name' => 'Graphic Notification Urunu',
                    'product_code' => $productCode,
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                        ],
                        [
                            'print_type' => 'Serigrafi',
                            'print_option' => 'Gövde',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '12',
                        ],
                    ],
                ]],
            ])
            ->assertRedirect();

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
