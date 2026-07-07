<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use App\Services\CurrentAccountTransactionService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountTransactionUiTest extends TestCase
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
        $this->financeUser = $this->createUserWithRole('finance', 'finance-current-account-transactions');
        $this->productionUser = $this->createUserWithRole('production', 'production-current-account-transactions');
    }

    public function test_authorized_users_can_view_transaction_index_and_show_block_while_unauthorized_users_cannot(): void
    {
        $account = $this->createAccount('UI Transaction Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        app(CurrentAccountTransactionService::class)->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1500,
            'currency' => 'TRY',
            'transaction_date' => '2026-06-16',
            'description' => 'UI debit kaydı',
        ], $this->adminUser);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-account-transactions.index'))
            ->assertOk()
            ->assertSee('Cari Hareketler')
            ->assertSee('UI Transaction Cari');

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-account-transactions.index'))
            ->assertForbidden();

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.show', $account))
            ->assertOk()
            ->assertSee('Cari Hareketler')
            ->assertSee('Bakiye')
            ->assertSee('Yeni Hareket Ekle')
            ->assertSee(route('admin.current-accounts.transactions.index', $account), false);

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.show', $account))
            ->assertOk()
            ->assertDontSee('Cari Hareketler')
            ->assertDontSee('Yeni Hareket Ekle')
            ->assertDontSee('1.500,00 TRY');
    }

    public function test_manual_transaction_form_and_cancel_flow_work_and_public_tracking_stays_clean(): void
    {
        $account = $this->createAccount('Cari Form Test', [CurrentAccountRole::ROLE_CUSTOMER]);

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-accounts.transactions.store', $account), [
                'transaction_type' => CurrentAccountTransaction::TYPE_OPENING_BALANCE,
                'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
                'amount' => 875,
                'currency' => 'EUR',
                'transaction_date' => '2026-06-16',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'document_number' => 'TX-UI-001',
                'description' => 'GIZLI-CARI-HAREKET-NOTU',
            ]);

        $transaction = CurrentAccountTransaction::query()->where('description', 'GIZLI-CARI-HAREKET-NOTU')->firstOrFail();
        $response->assertRedirect(route('admin.current-accounts.transactions.index', $account));

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.transactions.index', $account))
            ->assertOk()
            ->assertSee('GIZLI-CARI-HAREKET-NOTU')
            ->assertSee('875,00 EUR');

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-accounts.transactions.store', $account), [
                'transaction_type' => CurrentAccountTransaction::TYPE_ADJUSTMENT,
                'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
                'amount' => 100,
                'currency' => 'TRY',
                'transaction_date' => '2026-06-16',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-account-transactions.cancel', $transaction), [
                'cancellation_reason' => 'Manuel test iptali',
            ])
            ->assertRedirect(route('admin.current-accounts.transactions.index', $account));

        $cancelled = $transaction->fresh();
        $this->assertSame(CurrentAccountTransaction::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('Manuel test iptali', $cancelled->cancellation_reason);

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-account-transactions.cancel', $transaction), [
                'cancellation_reason' => 'Yetkisiz iptal',
            ])
            ->assertForbidden();

        $workForm = $this->createPublicTrackingWorkForm();

        $public = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $public->assertOk();
        $public->assertDontSee('GIZLI-CARI-HAREKET-NOTU');
        $public->assertDontSee('875,00 EUR');
        $public->assertDontSee('group_code', false);
        $public->assertDontSee('file_path', false);
        $public->assertDontSee('physical_path', false);
    }

    private function createAccount(string $displayName, array $roles): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);

        return $account->fresh(['roles']);
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Transaction User',
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

    private function createPublicTrackingWorkForm()
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-CA-TX-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Transaction Public Test Product',
            'product_code' => 'CA-TX-PUBLIC-001',
            'quantity' => 5,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => false,
            'status' => 'pending',
        ]);

        return app(WorkFormCreationService::class)
            ->createForOrder($order, $this->adminUser)
            ->first()
            ->fresh();
    }
}
