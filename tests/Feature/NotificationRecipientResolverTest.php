<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Notifications\NotificationRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificationRecipientResolverTest extends TestCase
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

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Ayse Musteri',
            ],
            [
                'email' => 'ayse@example.test',
                'phone' => '05320000000',
                'mobile' => '05320000000',
                'is_primary' => true,
            ]
        );
    }

    public function test_recipient_resolver_handles_customer_admin_role_and_fallback_audiences(): void
    {
        $resolver = app(NotificationRecipientResolver::class);

        $customerRecipients = $resolver->resolve('quote_sent_to_customer', 'customer', $this->customer);
        $this->assertCount(1, $customerRecipients);
        $this->assertSame('customer', $customerRecipients[0]['type']);
        $this->assertSame('ayse@example.test', $customerRecipients[0]['email']);

        $adminRecipients = $resolver->resolveTenantAdmins($this->tenant);
        $this->assertNotEmpty($adminRecipients);
        $this->assertSame($this->adminUser->email, $adminRecipients[0]['email']);

        $graphicUser = $this->createTenantUserWithRole('graphic@example.test', 'graphic');
        $productionUser = $this->createTenantUserWithRole('production@example.test', 'production');
        $deliveryUser = $this->createTenantUserWithRole('delivery@example.test', 'delivery');
        $salesUser = $this->createTenantUserWithRole('sales@example.test', 'sales');

        $graphicRecipients = $resolver->resolve('graphic_visual_uploaded', 'graphic_team', $this->tenant);
        $this->assertTrue(collect($graphicRecipients)->contains(fn (array $recipient) => $recipient['user_id'] === $graphicUser->id));

        $productionRecipients = $resolver->resolve('production_completed', 'production_team', $this->tenant);
        $this->assertTrue(collect($productionRecipients)->contains(fn (array $recipient) => $recipient['user_id'] === $productionUser->id));

        $deliveryRecipients = $resolver->resolve('delivery_completed', 'delivery_team', $this->tenant);
        $this->assertTrue(collect($deliveryRecipients)->contains(fn (array $recipient) => $recipient['user_id'] === $deliveryUser->id));

        $financeRecipients = $resolver->resolve('payment_received', 'finance_team', $this->tenant);
        $this->assertTrue(collect($financeRecipients)->contains(fn (array $recipient) => $recipient['user_id'] === $this->adminUser->id));

        $procurementRecipients = $resolver->resolve('procurement_received', 'procurement_team', $this->tenant);
        $this->assertTrue(collect($procurementRecipients)->contains(fn (array $recipient) => $recipient['user_id'] === $this->adminUser->id));

        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-RCP-001',
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'draft',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $salesUser->id,
        ]);

        $salesOwnerRecipients = $resolver->resolve('quote_created', 'sales_owner', $order);
        $this->assertCount(1, $salesOwnerRecipients);
        $this->assertSame($salesUser->id, $salesOwnerRecipients[0]['user_id']);

        $supplierRecipients = $resolver->resolve('procurement_received', 'supplier_contact', $this->tenant);
        $this->assertNotEmpty($supplierRecipients);
        $this->assertFalse(collect($supplierRecipients)->contains(fn (array $recipient) => $recipient['type'] === 'supplier'));
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
}
