<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\TenantAccount;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderFinancePermissionTenantIsolationTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_finance_summary_is_blocked_for_unauthorized_users_and_foreign_tenants(): void
    {
        $customer = $this->createCustomerCompany('İzin Test Müşteri');
        $order = $this->createOrder($customer, 'SP-OFS-PERM-001', 18000);
        $this->syncOrderDebit($order);

        $this->actingAs($this->limitedUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id))
            ->assertForbidden();

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Yabancı Tenant',
            'legal_name' => 'Yabancı Tenant Ltd.',
            'slug' => 'yabanci-tenant',
            'panel_subdomain' => 'yabanci-tenant',
            'status' => 'active',
        ]);

        $foreignCustomer = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Yabancı Müşteri',
            'status' => 'active',
        ]);
        $foreignCustomer->companyRoles()->create([
            'tenant_account_id' => $foreignTenant->id,
            'role_key' => 'customer',
        ]);

        $foreignOrder = Order::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-OFS-PERM-FOREIGN',
            'customer_company_id' => $foreignCustomer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'grand_total' => 2000,
        ]);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $foreignOrder->id))
            ->assertForbidden();
    }
}
