<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CurrentAccountTransaction;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\FinanceSummaryService;
use App\Services\Notifications\NotificationEventService;
use App\Services\OrderPaymentCurrentAccountSyncService;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceNotificationIntegrationTest extends TestCase
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

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Finans Musteri',
            ],
            [
                'email' => 'finance-customer@example.test',
                'phone' => '05323334455',
                'mobile' => '05323334455',
                'is_primary' => true,
            ]
        );

        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
    }

    public function test_finance_payment_notifications_emit_safely_and_do_not_break_payment_workflow(): void
    {
        $financeUser = $this->createTenantUserWithRole('finance.notification@prodelya.local', 'finance');
        $order = $this->createOrder('SP-FIN-NOTIF-001');
        $paymentService = app(OrderPaymentService::class);

        $payment = $paymentService->createPayment($order, [
            'payment_type' => 'tahsilat',
            'amount' => 1000,
            'currency' => 'TL',
            'payment_method' => 'havale',
            'payment_reference' => 'FIN-REF-001',
            'payment_note' => 'Ödeme notu file_path geçmemeli',
            'paid_at' => '2026-06-18 10:00:00',
            'due_date' => '2026-06-20 18:00:00',
        ], $this->adminUser);

        $receivedLogs = NotificationLog::query()
            ->where('notification_key', 'payment_received')
            ->where('related_id', $payment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $receivedLogs);
        $this->assertTrue($receivedLogs->contains(fn (NotificationLog $log) => $log->channel === 'internal' && $log->status === NotificationLog::STATUS_SENT));
        $this->assertTrue($receivedLogs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($receivedLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $financeUser->email));
        $this->assertTrue($receivedLogs->every(fn (NotificationLog $log) => $log->audience_type === 'finance'));
        $this->assertLogsAreFinanceSafe($receivedLogs);

        $receivedPayload = $receivedLogs->map(fn (NotificationLog $log) => (string) $log->message_preview)->implode("\n");
        $this->assertStringContainsString('1000', $receivedPayload);
        $this->assertStringContainsString('TL', $receivedPayload);
        $this->assertStringContainsString('Havale', $receivedPayload);

        $transaction = CurrentAccountTransaction::query()
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $payment->id)
            ->firstOrFail();
        $this->assertSame(CurrentAccountTransaction::DIRECTION_CREDIT, $transaction->direction);

        $summary = app(FinanceSummaryService::class)->summarizeOrder($order->fresh(['payments', 'deliveries.workForm.attachments', 'customer']));
        $this->assertSame(1000.0, (float) data_get($summary, 'net_paid_total'));
        $this->assertGreaterThanOrEqual(0.0, (float) data_get($summary, 'balance_due'));

        UserRole::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('user_id', $financeUser->id)
            ->delete();

        $cancelled = $paymentService->cancelPayment($payment->fresh(), $this->adminUser, 'İptal nedeni raw token yok');
        $this->assertNotNull($cancelled->cancelled_at);

        $cancelledLogs = NotificationLog::query()
            ->where('notification_key', 'payment_cancelled')
            ->where('related_id', $payment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $cancelledLogs);
        $this->assertTrue($cancelledLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $this->adminUser->email));
        $this->assertLogsAreFinanceSafe($cancelledLogs);

        $this->assertTrue($transaction->fresh()->isCancelled());

        $public = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $order->fresh()->workForms()->firstOrFail()->public_tracking_token));

        $public->assertOk();
        $public->assertDontSee('1000,00 TL');
        $public->assertDontSee('Bakiye var');
        $public->assertDontSee('Ödeme bekliyor');

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new \RuntimeException('finance notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $failureOrder = $this->createOrder('SP-FIN-NOTIF-002');
        $failedPayment = app(OrderPaymentService::class)->createPayment($failureOrder, [
            'payment_type' => 'tahsilat',
            'amount' => 750,
            'currency' => 'TL',
            'payment_method' => 'nakit',
            'payment_reference' => 'FIN-REF-FAIL',
            'paid_at' => '2026-06-18 11:00:00',
        ], $this->adminUser);

        $this->assertNotNull($failedPayment->id);
        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => OrderPaymentCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $failedPayment->id,
        ]);

        $this->assertSame(0, NotificationLog::query()->where('notification_key', 'payment_due_reminder')->count());
    }

    private function assertLogsAreFinanceSafe($logs): void
    {
        $serialized = $logs->map(function (NotificationLog $log): string {
            return (string) $log->subject
                . "\n"
                . (string) $log->message_preview
                . "\n"
                . json_encode($log->meta_json, JSON_UNESCAPED_UNICODE);
        })->implode("\n");

        foreach ([
            'group_code',
            'file_path',
            'physical_path',
            'pdh_raw',
            'raw_xml',
            'raw_json',
            'smtp_password',
            'token',
            'storage/app',
            'C:\\',
            '/var/',
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

    private function createOrder(string $documentNumber): \App\Models\Order
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
                'notes' => 'Finance notification payload',
                'items' => [[
                    'product_name' => 'Finance Notification Ürünü',
                    'product_code' => $documentNumber . '-ITEM',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '0',
                ]],
            ])
            ->assertRedirect();

        $quote = \App\Models\Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return \App\Models\Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail()
            ->fresh(['payments', 'workForms', 'deliveries.workForm.attachments', 'customer']);
    }
}
