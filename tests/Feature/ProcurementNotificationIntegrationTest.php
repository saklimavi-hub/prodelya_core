<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Notifications\NotificationEventService;
use App\Services\ProcurementWorkflowService;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProcurementNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_procurement_notifications_emit_safely_and_do_not_break_workflow(): void
    {
        $procurementUser = $this->createTenantUserWithRole('procurement.notification@prodelya.local', 'supplier_operator');
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-NOTIF-A');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-NOTIF-001');

        $requestService = app(SupplierProcurementRequestService::class);
        $requestRecord = $requestService->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $requestCreatedLogs = NotificationLog::query()
            ->where('notification_key', 'procurement_request_created')
            ->where('related_type', $requestRecord->getMorphClass())
            ->where('related_id', $requestRecord->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $requestCreatedLogs);
        $this->assertTrue($requestCreatedLogs->contains(fn (NotificationLog $log) => $log->channel === 'internal' && $log->status === NotificationLog::STATUS_SENT));
        $this->assertTrue($requestCreatedLogs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($requestCreatedLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $procurementUser->email));
        $this->assertFalse($requestCreatedLogs->contains(fn (NotificationLog $log) => $log->recipient_type === 'supplier'));
        $this->assertLogsAreSafe($requestCreatedLogs);

        $requestRecord = $requestService->markRequested($requestRecord->fresh(), $this->adminUser);
        $requestRecord = $requestService->markSupplierOrdered($requestRecord->fresh(), $this->adminUser);

        $orderedLogs = NotificationLog::query()
            ->where('notification_key', 'procurement_ordered')
            ->where('related_id', $procurement->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $orderedLogs);
        $this->assertLogsAreSafe($orderedLogs);

        UserRole::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('user_id', $procurementUser->id)
            ->delete();

        $requestItem = $requestRecord->fresh('items')->items->firstOrFail();
        $requestRecord = $requestService->markPartiallyReceived($requestRecord->fresh(), [
            $requestItem->id => 30,
        ], $this->adminUser);

        $partialLogs = NotificationLog::query()
            ->where('notification_key', 'procurement_partially_received')
            ->where('related_id', $procurement->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $partialLogs);
        $this->assertTrue($partialLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $this->adminUser->email));
        $this->assertLogsAreSafe($partialLogs);

        $requestRecord = $requestService->markCompleted($requestRecord->fresh(), $this->adminUser);

        $receivedLogs = NotificationLog::query()
            ->where('notification_key', 'procurement_received')
            ->where('related_id', $procurement->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $receivedLogs);
        $this->assertLogsAreSafe($receivedLogs);

        $cancelProcurement = $this->createProcurement($supplier, $source, 'SP-PROC-NOTIF-002');
        $cancelled = app(ProcurementWorkflowService::class)->cancel($cancelProcurement->fresh(), $this->adminUser, 'Iptal notu');
        $this->assertSame(OrderItemProcurement::STATUS_CANCELLED, $cancelled->procurement_status);

        $cancelLogs = NotificationLog::query()
            ->where('notification_key', 'procurement_cancelled')
            ->where('related_id', $cancelProcurement->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $cancelLogs);
        $this->assertLogsAreSafe($cancelLogs);

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new \RuntimeException('procurement notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $failureProcurement = $this->createProcurement($supplier, $source, 'SP-PROC-NOTIF-003');
        $failureWorkflow = app(ProcurementWorkflowService::class);
        $failedOrdered = $failureWorkflow->markSupplierOrdered($failureProcurement->fresh(), $this->adminUser, 'Failure safe');

        $this->assertSame(OrderItemProcurement::STATUS_SUPPLIER_ORDERED, $failedOrdered->procurement_status);
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
            'purchase_total',
            'purchase_unit_price',
            'unit_price',
            'line_total',
            'vat_total',
            'grand_total',
            'supplier_cost',
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

    private function createSupplierWithAccess(string $code): array
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'can_request_purchase' => true,
                'can_use_in_quotes' => true,
                'visible_in_catalog' => true,
                'export_allowed' => false,
                'granted_at' => now(),
            ]
        );

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);

        return [$supplier, $source];
    }

    private function createProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber): OrderItemProcurement
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $orderNumber,
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
            'product_source' => 'supplier_feed',
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_id' => null,
            'supplier_source_id' => $source->id,
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => $supplier->code . ' Ürün',
                'product_code' => $supplier->code . '-001',
                'supplier_name' => $supplier->name,
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 20,
                'line_total' => 2000,
                'vat_total' => 400,
                'pdh_raw' => ['secret' => 'hidden'],
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 40,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => 20,
            'line_total' => 2000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order'])->procurement;
    }
}
