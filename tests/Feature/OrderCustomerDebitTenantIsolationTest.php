<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitTenantIsolationTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_foreign_tenant_customer_cannot_be_used_for_order_customer_debit_sync(): void
    {
        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Debit Tenant',
            'legal_name' => 'Foreign Debit Tenant Ltd.',
            'slug' => 'foreign-debit-tenant',
            'panel_subdomain' => 'foreign-debit-tenant',
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

        $order = $this->createOrder($this->createCustomerCompany('Asıl Müşteri'), 'SP-FOREIGN-001', 4200, [
            'customer_company_id' => $foreignCompany->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sipariş müşterisi bu tenant için geçerli değil.');

        try {
            $this->syncOrderDebit($order);
        } finally {
            $this->assertDatabaseMissing('current_account_transactions', [
                'tenant_account_id' => $this->tenant->id,
                'source_type' => OrderCurrentAccountDebitSyncService::SOURCE_TYPE,
                'source_id' => $order->id,
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            ]);
        }
    }
}
