<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountManualTransactionCreateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->financeUser = $this->createFinanceUser();
    }

    public function test_finance_user_can_open_form_and_create_supported_manual_transactions(): void
    {
        [$account, $company] = $this->createLinkedAccount('Manuel Fis Cari', [CurrentAccountRole::ROLE_CUSTOMER, CurrentAccountRole::ROLE_SUPPLIER]);
        $order = $this->createOrder($company, 'SP-MANUAL-1001');

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'))
            ->assertOk()
            ->assertSee('Borç / Alacak / Tahsilat / Ödeme Fişi')
            ->assertSee('Belge No')
            ->assertSee('Sipariş Bağlantısı');

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                'amount' => 1250,
                'currency' => 'TL',
                'transaction_date' => '2026-07-03',
                'due_date' => '2026-07-12',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'payment_method' => 'havale',
                'document_number' => 'MF-001',
                'order_id' => $order->id,
                'description' => 'Müşteri borç fişi',
                'internal_note' => 'İç not 1',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
                'amount' => 250,
                'currency' => 'TL',
                'transaction_date' => '2026-07-04',
                'status' => CurrentAccountTransaction::STATUS_CLOSED,
                'payment_method' => 'nakit',
                'document_number' => 'TH-001',
                'description' => 'Müşteri tahsilatı',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
                'amount' => 400,
                'currency' => 'TL',
                'transaction_date' => '2026-07-05',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'description' => 'Tedarikçi borç fişi',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
                'amount' => 150,
                'currency' => 'TL',
                'transaction_date' => '2026-07-06',
                'status' => CurrentAccountTransaction::STATUS_CLOSED,
                'payment_method' => 'kredi_karti',
                'description' => 'Tedarikçi ödemesi',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $transactions = CurrentAccountTransaction::query()
            ->where('current_account_id', $account->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $transactions);
        $this->assertSame(CurrentAccountTransaction::DIRECTION_DEBIT, $transactions[0]->direction);
        $this->assertSame(CurrentAccountTransaction::DIRECTION_CREDIT, $transactions[1]->direction);
        $this->assertSame($order->id, $transactions[0]->source_id);
        $this->assertSame('MF-001', data_get($transactions[0]->meta_json, 'manual.document_number'));
        $this->assertSame('havale', data_get($transactions[0]->meta_json, 'manual.payment_method'));
        $this->assertSame('İç not 1', data_get($transactions[0]->meta_json, 'manual.internal_note'));
        $this->assertSame($order->document_number, data_get($transactions[0]->meta_json, 'manual.linked_order_number'));

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'))
            ->assertOk()
            ->assertSee('Müşteri borç fişi')
            ->assertSee('Müşteri tahsilatı')
            ->assertSee('Tedarikçi borç fişi')
            ->assertSee('Tedarikçi ödemesi')
            ->assertSee('MF-001')
            ->assertSee('SP-MANUAL-1001');
    }

    public function test_manual_transaction_form_validates_required_fields_and_positive_amount(): void
    {
        [$account] = $this->createLinkedAccount('Validasyon Cari', [CurrentAccountRole::ROLE_CUSTOMER]);

        $this->actingAs($this->financeUser, 'web')
            ->from($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'))
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => '',
                'amount' => 0,
                'currency' => 'TL',
                'transaction_date' => '',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
            ])
            ->assertSessionHasErrors(['transaction_type', 'amount', 'transaction_date']);
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Manual Transaction Finance',
            'email' => 'manual-transaction-finance@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $financeRole->id,
        ]);

        return $user;
    }

    private function createLinkedAccount(string $displayName, array $roles): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, $roles);

        return [$account->fresh(['roles', 'primaryCompanyLink']), Company::query()->findOrFail($company->id)];
    }

    private function createOrder(Company $company, string $documentNumber): Order
    {
        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $company->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->financeUser->id,
        ]);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
