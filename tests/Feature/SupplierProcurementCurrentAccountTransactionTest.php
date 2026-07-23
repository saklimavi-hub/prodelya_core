<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProcurementRequestItem;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use App\Services\CurrentAccountTransactionService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierProcurementCurrentAccountTransactionTest extends TestCase
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
        $this->financeUser = $this->createUserWithRole('finance', 'finance-supplier-procurement-current-account');
        $this->productionUser = $this->createUserWithRole('production', 'production-supplier-procurement-current-account');
    }

    public function test_supplier_procurement_items_sync_into_supplier_debit_transactions_and_handle_updates_exclusions_and_cancel(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPCATX-A');
        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SPCATX-001');

        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $item = $request->items->firstOrFail();

        $request = app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => 9.20,
            'discount_rate' => 45,
            'note' => 'Cari borç test kalemi',
        ]], $this->adminUser);

        $item = $request->items->firstOrFail();
        $transaction = CurrentAccountTransaction::query()
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->firstOrFail();

        $this->assertSame(CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT, $transaction->transaction_type);
        $this->assertSame(CurrentAccountTransaction::DIRECTION_DEBIT, $transaction->direction);
        $this->assertSame('506.00', (string) $transaction->amount);
        $this->assertSame('TL', $transaction->currency);

        $account = CurrentAccount::query()->findOrFail($transaction->current_account_id);
        $this->assertTrue($account->hasRole(CurrentAccountRole::ROLE_SUPPLIER));
        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        app(SupplierProcurementCurrentAccountSyncService::class)->syncRequestItem($item->fresh(['request.supplier.tenants', 'procurement', 'order']));
        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
            ->count());

        $request = app(SupplierProcurementRequestService::class)->updateRequestItems($request->fresh(), [[
            'id' => $item->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => 8.50,
            'discount_rate' => 10,
            'purchase_unit_price' => 7.65,
            'use_calculated_price' => 0,
            'note' => 'Guncel tutar',
        ]], $this->adminUser);

        $updatedTransaction = $transaction->fresh();
        $this->assertSame($transaction->id, $updatedTransaction->id);
        $this->assertSame('765.00', (string) $updatedTransaction->amount);

        $summary = app(CurrentAccountTransactionService::class)->getAccountSummary($account->fresh());
        $this->assertSame(765.0, $summary['currencies']['TL']['debit_total']);
        $this->assertSame(0.0, $summary['currencies']['TL']['credit_total']);
        $this->assertSame(765.0, $summary['currencies']['TL']['balance']);

        app(SupplierProcurementRequestService::class)->updateRequestItems($request->fresh(), [[
            'id' => $item->id,
            'included' => 0,
            'requested_quantity' => 100,
            'purchase_list_price' => 8.50,
            'discount_rate' => 10,
        ]], $this->adminUser);

        $this->assertDatabaseMissing('supplier_procurement_request_items', [
            'id' => $item->id,
        ]);
        $this->assertTrue($updatedTransaction->fresh()->isCancelled());

        $cancelledSummary = app(CurrentAccountTransactionService::class)->getAccountSummary($account->fresh());
        $this->assertSame(0, $cancelledSummary['transaction_count']);

        $procurementB = $this->createSupplierProcurement($supplier, $source, 'SP-SPCATX-002');
        $requestB = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurementB->id],
            $this->adminUser
        );
        $itemB = $requestB->items->firstOrFail();
        $requestB = app(SupplierProcurementRequestService::class)->updateRequestItems($requestB, [[
            'id' => $itemB->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => 7.00,
            'discount_rate' => 0,
        ]], $this->adminUser);
        $itemB = $requestB->items->firstOrFail();

        $txB = CurrentAccountTransaction::query()
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $itemB->id)
            ->firstOrFail();

        app(SupplierProcurementRequestService::class)->cancelRequest($requestB->fresh(), $this->adminUser);
        $this->assertTrue($txB->fresh()->isCancelled());

        $procurementC = $this->createSupplierProcurement($supplier, $source, 'SP-SPCATX-003');
        $requestC = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurementC->id],
            $this->adminUser
        );
        $itemC = $requestC->items->firstOrFail();

        app(SupplierProcurementRequestService::class)->updateRequestItems($requestC, [[
            'id' => $itemC->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => null,
            'discount_rate' => 0,
            'purchase_unit_price' => '',
        ]], $this->adminUser);

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $itemC->id,
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'code' => $supplier->code,
        ]);
    }

    public function test_existing_supplier_link_is_reused_backfill_command_supports_dry_run_and_public_surfaces_stay_safe(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPCATX-B');

        $linkedAccount = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Mevcut Tedarikçi Carisi',
            'legal_name' => 'Mevcut Tedarikçi Carisi Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);
        app(CurrentAccountSyncService::class)->syncRoles($linkedAccount, [CurrentAccountRole::ROLE_SUPPLIER]);
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $linkedAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
        ]);

        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SPCATX-004');
        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );
        $item = $request->items->firstOrFail();

        app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => 10.00,
            'discount_rate' => 0,
        ]], $this->adminUser);

        $transaction = CurrentAccountTransaction::query()
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->firstOrFail();
        $this->assertSame($linkedAccount->id, $transaction->current_account_id);

        $transaction->delete();

        $this->artisan('prodelya:sync-procurements-to-current-accounts', [
            '--tenant' => $this->tenant->id,
            '--item' => $item->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $item->id,
        ]);

        $this->artisan('prodelya:sync-procurements-to-current-accounts', [
            '--tenant' => $this->tenant->id,
            '--item' => $item->id,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $item->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
        ]);

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Foreign Supplier Tenant',
            'legal_name' => 'Foreign Supplier Tenant Ltd.',
            'slug' => 'foreign-supplier-tenant',
            'panel_subdomain' => 'foreign-supplier-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignRequest = SupplierProcurementRequest::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'supplier_id' => $supplier->id,
            'request_number' => 'TS-FOREIGN-0001',
            'request_date' => '2026-06-16',
            'status' => SupplierProcurementRequest::STATUS_DRAFT,
        ]);

        $foreignItem = SupplierProcurementRequestItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_procurement_request_id' => $foreignRequest->id,
            'order_item_procurement_id' => $procurement->id,
            'order_id' => $procurement->order_id,
            'order_item_id' => $procurement->order_item_id,
            'work_form_id' => $procurement->work_form_id,
            'product_code' => 'FOREIGN-LINK',
            'product_name' => 'Foreign Link Item',
            'requested_quantity' => 10,
            'unit' => 'Adet',
            'received_quantity' => 0,
            'remaining_quantity' => 10,
            'purchase_total' => 50,
        ]);

        app(SupplierProcurementCurrentAccountSyncService::class)->syncRequestItem($foreignItem->fresh(['request.supplier.tenants', 'order']));

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => $foreignItem->id,
        ]);

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $request->fresh()))
            ->assertOk()
            ->assertDontSee('Alış Liste Fiyatı')
            ->assertDontSee('purchase_total', false)
            ->assertDontSee('Tedarik Alış Borcu');

        $workForm = $procurement->fresh('workForm')->workForm;
        $public = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $public->assertOk();
        $public->assertDontSee('Tedarik Alış Borcu');
        $public->assertDontSee('1.000,00 TL');
        $public->assertDontSee('group_code', false);
        $public->assertDontSee('file_path', false);
        $public->assertDontSee('physical_path', false);
    }

    private function createSupplierWithAccess(string $code): array
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'can_request_purchase' => true,
                'can_use_in_quotes' => true,
                'visible_in_catalog' => true,
                'export_allowed' => false,
                'granted_at' => now(),
            ]
        );

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);

        return [$supplier, $source];
    }

    private function createSupplierProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber): OrderItemProcurement
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
            'product_source' => 'supplier_feed',
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_id' => null,
            'supplier_source_id' => $source->id,
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => $supplier->code . ' Ürün',
                'product_code' => $supplier->code . '-001',
                'supplier_name' => $supplier->name,
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 20,
                'line_total' => 2000,
                'vat_total' => 400,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 40,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => 20,
            'line_total' => 2000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm', 'procurement.order'])->procurement;
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Supplier Procurement User',
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
