<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountTransactionService;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\ProductionWorkflowService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubcontractorProductionCurrentAccountTransactionTest extends TestCase
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

        Storage::fake('public');
        Storage::fake('local');

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
        $this->financeUser = $this->createUserWithRole('finance', 'finance-subcontractor-current-account');
        $this->productionUser = $this->createUserWithRole('production', 'production-subcontractor-current-account');
    }

    public function test_outsourced_production_cost_syncs_updates_and_cancels_safely(): void
    {
        $partner = $this->createPartnerCompany();
        $production = $this->createProduction('SP-SUB-TX-001', $partner, OrderItemPrintProduction::TYPE_OUTSOURCED);

        $production->forceFill([
            'subcontractor_cost' => 1250.50,
            'subcontractor_cost_currency' => 'TRY',
            'updated_by' => $this->adminUser->id,
        ])->save();

        $transaction = app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        $this->assertNotNull($transaction);
        $this->assertSame(CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT, $transaction->transaction_type);
        $this->assertSame(CurrentAccountTransaction::DIRECTION_DEBIT, $transaction->direction);
        $this->assertSame('1250.50', (string) $transaction->amount);
        $this->assertSame('TRY', $transaction->currency);

        $account = CurrentAccount::query()->findOrFail($transaction->current_account_id);
        $this->assertTrue($account->hasRole(CurrentAccountRole::ROLE_SUBCONTRACTOR));

        app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $production->id)
            ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
            ->count());

        $production->forceFill([
            'subcontractor_cost' => 1500,
            'subcontractor_cost_currency' => 'USD',
            'updated_by' => $this->adminUser->id,
        ])->save();

        $updated = app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        $this->assertSame($transaction->id, $updated->id);
        $this->assertSame('1500.00', (string) $updated->amount);
        $this->assertSame('USD', $updated->currency);

        $summary = app(CurrentAccountTransactionService::class)->getAccountSummary($account->fresh());
        $this->assertSame(1500.0, $summary['currencies']['USD']['debit_total']);
        $this->assertSame(1500.0, $summary['currencies']['USD']['balance']);

        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'updated_by' => $this->adminUser->id,
        ])->save();

        app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        $this->assertTrue($updated->fresh()->isCancelled());
    }

    public function test_command_visibility_and_tenant_guards_work_for_subcontractor_transactions(): void
    {
        $partner = $this->createPartnerCompany();
        $production = $this->createProduction('SP-SUB-TX-002', $partner, OrderItemPrintProduction::TYPE_OUTSOURCED);

        $this->prepareProductionForStart($production, 'subcontractor-sync-ready.jpg');

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-assignment', $production), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
                'production_unit_name' => '',
                'assigned_to' => '',
                'cliche_required' => '0',
                'subcontractor_cost' => '875.25',
                'subcontractor_cost_currency' => 'EUR',
                'subcontractor_cost_note' => 'Fason yaldiz hizmeti',
            ])
            ->assertRedirect(route('admin.productions.subcontract-assignment', $production));

        $transaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $production->id)
            ->firstOrFail();

        $this->assertSame(CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT, $transaction->transaction_type);
        $this->assertSame('875.25', (string) $transaction->amount);
        $this->assertSame('EUR', $transaction->currency);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.transactions.index', $transaction->currentAccount))
            ->assertOk()
            ->assertSee('Fason Üretim Borcu')
            ->assertSee('875,25 EUR');

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production))
            ->assertOk()
            ->assertDontSee('Fason Maliyet Bilgisi')
            ->assertDontSee('875.25')
            ->assertDontSee('875,25');

        $public = $this->get(route('public.work-forms.track', $production->fresh('workForm')->workForm->public_tracking_token));
        $public->assertOk();
        $public->assertDontSee('Fason Üretim Borcu');
        $public->assertDontSee('875,25 EUR');
        $public->assertDontSee('group_code', false);
        $public->assertDontSee('file_path', false);
        $public->assertDontSee('physical_path', false);

        $transaction->delete();

        $this->artisan('prodelya:sync-subcontractor-productions-to-current-accounts', [
            '--tenant' => $this->tenant->id,
            '--production' => $production->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $production->id,
        ]);

        $this->artisan('prodelya:sync-subcontractor-productions-to-current-accounts', [
            '--tenant' => $this->tenant->id,
            '--production' => $production->id,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $production->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
        ]);

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Production Tenant',
            'legal_name' => 'Foreign Production Tenant Ltd.',
            'slug' => 'foreign-production-tenant',
            'panel_subdomain' => 'foreign-production-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Foreign Fason Company',
            'short_name' => 'Foreign Fason',
            'status' => 'active',
        ]);
        CompanyRole::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
            'role_key' => 'print_fason',
        ]);

        $foreignLinkedProduction = $this->createProduction('SP-SUB-TX-003', $partner, OrderItemPrintProduction::TYPE_OUTSOURCED);
        $foreignLinkedProduction->forceFill([
            'production_company_id' => $foreignCompany->id,
            'subcontractor_cost' => 250,
            'subcontractor_cost_currency' => 'TRY',
            'updated_by' => $this->adminUser->id,
        ])->save();

        $result = app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($foreignLinkedProduction->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        $this->assertNull($result);
        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $foreignLinkedProduction->id,
        ]);

        app(ProductionWorkflowService::class)->cancel($production->fresh(), $this->adminUser, 'Fason üretim iptal edildi.');
        $this->assertTrue(CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $production->id)
            ->latest('id')
            ->firstOrFail()
            ->isCancelled());
    }

    private function createProduction(string $orderNumber, Company $partner, string $productionType): OrderItemPrintProduction
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
            'product_source' => 'manual',
            'product_name' => 'Subcontractor Test Product',
            'product_code' => 'SUB-TX-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => true,
            'status' => 'pending',
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Logo',
            'production_type' => $productionType === OrderItemPrintProduction::TYPE_INTERNAL ? 'İç üretim' : 'Dış üretim / Fason',
            'subcontractor_company_id' => $partner->id,
            'print_quantity' => 100,
            'print_unit_price' => 10,
            'print_total' => 1000,
            'cliche_status' => 'bekleniyor',
            'status' => 'draft',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();

        return $print->fresh(['production.workForm', 'production.orderItemPrint', 'production.orderItem'])->production->fresh([
            'workForm',
            'order',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]);
    }

    private function prepareProductionForStart(OrderItemPrintProduction $production, string $fileName): void
    {
        $production = $production->fresh(['workForm.procurement', 'orderItemPrint.graphicOperation']);
        $graphic = $production->orderItemPrint?->graphicOperation;
        $this->assertNotNull($graphic);

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Subcontractor final görsel',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm?->procurement;
        $this->assertNotNull($procurement);
        $procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $production->workForm?->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'public_status_label' => 'Ürün üretime hazır',
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }

    private function createPartnerCompany(): Company
    {
        $existing = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereKeyNot($this->customer->id)
            ->whereHas('companyRoles', fn ($query) => $query->whereIn('role_key', ['print_fason', 'production_partner']))
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Subcontractor Partner',
            'short_name' => 'Sub Partner',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => 'print_fason',
        ]);

        return $company;
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Subcontractor User',
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
