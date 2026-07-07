<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderPaymentCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class StatementActionTenantPermissionTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_source_payment_actions_stay_tenant_scoped_and_foreign_cancel_is_forbidden(): void
    {
        $customer = $this->createCustomerCompany('Tenant Action Müşteri');
        $order = $this->createOrder($customer, 'SP-TENANT-ACTION-001', 7300);
        $debit = $this->syncOrderDebit($order);
        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 2000);

        $payment = $order->fresh()->payments()->latest('id')->firstOrFail();
        $paymentTransaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $payment->id)
            ->firstOrFail();

        $foreignTenant = \App\Models\TenantAccount::query()->create([
            'name' => 'Foreign Statement Action Tenant',
            'legal_name' => 'Foreign Statement Action Tenant Ltd.',
            'slug' => 'foreign-statement-action-tenant',
            'panel_subdomain' => 'foreign-statement-action-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->tenant = $foreignTenant;
        $foreignFinanceUser = $this->createTenantUserWithRoles(
            'foreign-statement-action-finance@example.test',
            ['tenant_owner', 'finance']
        );

        $this->actingAs($foreignFinanceUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $foreignTenant->panel_subdomain . '.prodelya_core.test'])
            ->post('http://' . $foreignTenant->panel_subdomain . '.prodelya_core.test/admin/current-account-transactions/' . $paymentTransaction->id . '/cancel', [
                'cancellation_reason' => 'Foreign current account cancel denemesi',
            ])
            ->assertForbidden();

        $this->actingAs($foreignFinanceUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $foreignTenant->panel_subdomain . '.prodelya_core.test'])
            ->patch(route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $payment]), [
                'cancel_note' => 'Foreign payment cancel denemesi',
            ])
            ->assertForbidden();

        $this->assertFalse($paymentTransaction->fresh()->isCancelled());
        $this->assertNotNull($debit);
    }
}
