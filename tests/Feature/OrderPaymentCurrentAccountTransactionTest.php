<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountTransactionService;
use App\Services\FinanceSummaryService;
use App\Services\OrderPaymentCurrentAccountSyncService;
use App\Services\OrderPaymentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPaymentCurrentAccountTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private User $financeUser;
    private User $productionUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
        $this->financeUser = $this->createUserWithRole('finance', 'finance-payment-current-account');
        $this->productionUser = $this->createUserWithRole('production', 'production-payment-current-account');
    }

    public function test_order_payments_sync_into_current_account_transactions_safely_and_idempotently(): void
    {
        $order = $this->createOrder('SP-OPCAT-001');
        $paymentService = app(OrderPaymentService::class);
        $syncService = app(OrderPaymentCurrentAccountSyncService::class);
        $summaryService = app(FinanceSummaryService::class);
        $transactionSummaryService = app(CurrentAccountTransactionService::class);

        $payment = $paymentService->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 1000,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => '2026-06-16 10:00:00',
        ], $this->adminUser);

        $transaction = CurrentAccountTransaction::query()
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $payment->id)
            ->firstOrFail();

        $this->assertSame(CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT, $transaction->transaction_type);
        $this->assertSame(CurrentAccountTransaction::DIRECTION_CREDIT, $transaction->direction);
        $this->assertSame('1000.00', (string) $transaction->amount);
        $this->assertSame('TL', $transaction->currency);

        $account = CurrentAccount::query()->findOrFail($transaction->current_account_id);
        $this->assertSame($this->tenant->id, $account->tenant_account_id);
        $this->assertTrue($account->hasRole(CurrentAccountRole::ROLE_CUSTOMER));

        $syncService->syncPayment($payment->fresh(['order', 'customerCompany.companyRoles', 'creator', 'updater']));
        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $payment->id)
            ->count());

        $summary = $summaryService->summarizeOrder($order->fresh(['payments', 'deliveries.workForm.attachments', 'customer']));
        $this->assertSame(1000.0, (float) data_get($summary, 'net_paid_total'));

        $accountSummary = $transactionSummaryService->getAccountSummary($account->fresh());
        $this->assertSame(0.0, $accountSummary['currencies']['TL']['debit_total']);
        $this->assertSame(1000.0, $accountSummary['currencies']['TL']['credit_total']);
        $this->assertSame(-1000.0, $accountSummary['currencies']['TL']['balance']);

        $refund = $paymentService->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_REFUND,
            'amount' => 250,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_CASH,
            'paid_at' => '2026-06-16 12:00:00',
        ], $this->adminUser);

        $refundTransaction = CurrentAccountTransaction::query()
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $refund->id)
            ->firstOrFail();

        $this->assertSame(CurrentAccountTransaction::TYPE_REFUND, $refundTransaction->transaction_type);
        $this->assertSame(CurrentAccountTransaction::DIRECTION_DEBIT, $refundTransaction->direction);

        $paymentService->cancelPayment($payment, $this->adminUser, 'İptal test notu');
        $cancelledTransaction = $transaction->fresh();
        $this->assertTrue($cancelledTransaction->isCancelled());

        $syncService->syncPayment($payment->fresh(['order', 'customerCompany.companyRoles', 'creator', 'updater']));
        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $payment->id)
            ->count());

        $afterCancelSummary = $transactionSummaryService->getAccountSummary($account->fresh());
        $this->assertSame(250.0, $afterCancelSummary['currencies']['TL']['debit_total']);
        $this->assertSame(0.0, $afterCancelSummary['currencies']['TL']['credit_total']);
        $this->assertSame(250.0, $afterCancelSummary['currencies']['TL']['balance']);
    }

    public function test_backfill_command_dry_run_and_real_run_work_and_public_surface_stays_safe(): void
    {
        $order = $this->createOrder('SP-OPCAT-002');

        $rawPayment = OrderPayment::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'customer_company_id' => $this->customer->id,
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 600,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_CASH,
            'paid_at' => now(),
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $this->artisan('prodelya:sync-order-payments-to-current-accounts', [
            '--tenant' => $this->tenant->id,
            '--payment' => $rawPayment->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => OrderPaymentCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $rawPayment->id,
        ]);

        $this->artisan('prodelya:sync-order-payments-to-current-accounts', [
            '--tenant' => $this->tenant->id,
            '--payment' => $rawPayment->id,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => OrderPaymentCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $rawPayment->id,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
        ]);

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Payment Tenant',
            'legal_name' => 'Foreign Payment Tenant Ltd.',
            'slug' => 'foreign-payment-tenant',
            'panel_subdomain' => 'foreign-payment-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Foreign Customer Co',
            'status' => 'active',
        ]);
        $foreignCompany->companyRoles()->create([
            'tenant_account_id' => $foreignTenant->id,
            'role_key' => 'customer',
        ]);

        $foreignPayment = OrderPayment::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'customer_company_id' => $foreignCompany->id,
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 700,
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        app(OrderPaymentCurrentAccountSyncService::class)->syncPayment($foreignPayment);

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => OrderPaymentCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $foreignPayment->id,
        ]);

        $account = CurrentAccount::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('links', fn ($q) => $q->where('link_type', 'company')->where('link_id', $this->customer->id))
            ->firstOrFail();

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.show', $account))
            ->assertRedirect(route('admin.companies.show', $this->customer));

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', $this->customer))
            ->assertOk()
            ->assertDontSee('Cari Hareketler')
            ->assertDontSee('600,00 TL');

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh();
        $public = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $public->assertOk();
        $public->assertDontSee('600,00 TL');
        $public->assertDontSee('current_account_transactions', false);
        $public->assertDontSee('group_code', false);
        $public->assertDontSee('file_path', false);
    }

    private function createOrder(string $documentNumber): Order
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'grand_total' => 2400,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Payment Sync Product',
            'product_code' => 'PAY-SYNC-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => false,
            'status' => 'pending',
        ]);

        return $order->fresh(['customer', 'payments']);
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Payment Sync User',
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
