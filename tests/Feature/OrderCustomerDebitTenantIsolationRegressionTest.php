<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\TenantAccount;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitTenantIsolationRegressionTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_foreign_tenant_customer_company_cannot_be_used_for_local_order_debit_sync(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant Debit Scope',
            'legal_name' => 'Other Tenant Debit Scope Ltd.',
            'slug' => 'other-tenant-debit-scope',
            'panel_subdomain' => 'other-tenant-debit-scope',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Foreign Debit Customer',
            'status' => 'active',
        ]);
        $foreignCompany->companyRoles()->create([
            'tenant_account_id' => $otherTenant->id,
            'role_key' => 'customer',
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-FOREIGN-001',
            'customer_company_id' => $foreignCompany->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-07-03',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'grand_total' => 5000,
            'created_by' => $this->financeUser->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sipariş müşterisi bu tenant için geçerli değil.');

        app(OrderCurrentAccountDebitSyncService::class)->syncOrder($order->fresh(['customer.companyRoles', 'payments']), $this->financeUser);
    }
}
