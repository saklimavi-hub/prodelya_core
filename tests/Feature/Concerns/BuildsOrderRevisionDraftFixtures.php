<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Testing\TestResponse;

trait BuildsOrderRevisionDraftFixtures
{
    private const HOST = 'prodelya_core.test';

    protected TenantAccount $tenant;
    protected TenantAccount $foreignTenant;
    protected User $adminUser;
    protected User $unauthorizedUser;
    protected Company $customer;

    protected function setUpOrderRevisionDraftFixtures(): void
    {
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->unauthorizedUser = User::query()->create([
            'name' => 'Yetkisiz Teklif Kullanıcısı',
            'email' => 'yetkisiz-teklif-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->unauthorizedUser->id,
            'role_id' => Role::query()->where('key', 'production')->firstOrFail()->id,
        ]);

        $this->customer = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Revizyon Test Müşterisi A.Ş.',
            'name' => 'Revizyon Test Müşterisi',
            'status' => 'active',
            'email' => 'musteri@example.test',
            'mobile' => '05320000000',
            'phone' => '02120000000',
            'contact_name' => 'Ayşe Test',
        ]);
        $this->customer->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Revizyon Yabancı Tenant',
            'legal_name' => 'Revizyon Yabancı Tenant Ltd.',
            'slug' => 'revizyon-yabanci-' . uniqid(),
            'panel_subdomain' => 'revizyon-yabanci-' . uniqid(),
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    protected function createSourceOrder(array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-REV-' . strtoupper(substr(uniqid(), -6)),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-07-08',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'subtotal' => 3500,
            'vat_total' => 700,
            'grand_total' => 4200,
            'product_total' => 3000,
            'print_total' => 500,
            'vat_breakdown_json' => [['rate' => 20, 'total' => 700, 'scope' => 'general']],
            'notes' => 'Kaynak sipariş ticari notu',
            'show_print_price_details_to_customer' => true,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $item = OrderItem::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Kaynak Test Ürünü',
            'product_code' => 'REV-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Kaynak açıklama',
            'product_snapshot' => [
                'product_name' => 'Kaynak Test Ürünü',
                'catalog_source' => 'tenant_catalog',
                'group_code' => 'HIDDEN-GROUP-CODE',
                'raw' => ['secret' => 'RAW-HIDDEN'],
            ],
            'price_snapshot' => [
                'product_total' => 3000,
                'print_total' => 500,
                'supplier_cost' => 'SUPPLIER-COST-HIDDEN',
                'margin' => 'MARGIN-HIDDEN',
                'file_path' => '/tmp/private.pdf',
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => 500,
                'projection' => ['secret' => 'PROJECTION-HIDDEN'],
                'payload' => ['secret' => 'PAYLOAD-HIDDEN'],
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 35,
            'discount_rate' => 0,
            'unit_price' => 30,
            'line_total' => 3000,
            'has_print' => true,
            'print_total' => 500,
            'status' => 'pending',
        ]);

        $print = $item->prints()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Ön yüz',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
            'print_unit_price' => 5,
            'print_total' => 500,
            'note' => 'Baskı notu',
            'production_note' => 'Operasyonel baskı notu',
            'status' => 'pending',
        ]);

        if (($overrides['with_operations'] ?? true) === true) {
            $workForm = OrderItemWorkForm::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'work_form_number' => 'WF-' . strtoupper(substr(uniqid(), -6)),
                'item_sequence' => 1,
                'status' => 'active',
                'version' => 1,
                'public_tracking_token' => 'trk-' . uniqid(),
                'graphic_snapshot' => ['status' => 'bekleniyor'],
                'production_snapshot' => ['status' => 'hazirlaniyor'],
                'delivery_snapshot' => ['status' => 'planlandi'],
                'created_by' => $this->adminUser->id,
            ]);

            OrderItemProcurement::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'work_form_id' => $workForm->id,
                'requires_procurement' => true,
                'fulfillment_source' => OrderItemProcurement::FULFILLMENT_SUPPLIER,
                'procurement_status' => OrderItemProcurement::STATUS_PENDING,
                'requested_quantity' => 100,
                'received_quantity' => 0,
                'remaining_quantity' => 100,
                'created_by' => $this->adminUser->id,
            ]);

            OrderItemPrintProduction::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'order_item_print_id' => $print->id,
                'work_form_id' => $workForm->id,
                'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
                'production_status' => OrderItemPrintProduction::STATUS_PENDING,
                'planned_quantity' => 100,
                'completed_quantity' => 0,
                'remaining_quantity' => 100,
                'cliche_required' => false,
                'qc_status' => OrderItemPrintProduction::QC_WAITING,
                'created_by' => $this->adminUser->id,
            ]);

            OrderItemPrintGraphic::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'order_item_print_id' => $print->id,
                'order_item_work_form_id' => $workForm->id,
                'status' => 'waiting_visual',
                'customer_approval_status' => 'waiting',
                'graphic_note' => 'Grafik süreci aktif',
                'created_by' => $this->adminUser->id,
            ]);

            OrderItemWorkFormDelivery::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'work_form_id' => $workForm->id,
                'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
                'planned_quantity' => 100,
                'delivered_quantity' => 0,
                'remaining_quantity' => 100,
                'created_by' => $this->adminUser->id,
            ]);
        }

        if (($overrides['with_finance'] ?? true) === true) {
            OrderPayment::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'customer_company_id' => $order->customer_company_id,
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => 250,
                'currency' => 'TL',
                'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
                'paid_at' => now(),
                'created_by' => $this->adminUser->id,
            ]);

            $account = CurrentAccount::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'display_name' => 'Revizyon Cari',
                'legal_name' => 'Revizyon Cari Ltd.',
                'status' => CurrentAccount::STATUS_ACTIVE,
                'default_currency' => 'TL',
            ]);

            CurrentAccountTransaction::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'current_account_id' => $account->id,
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                'source_type' => 'order',
                'source_id' => $order->id,
                'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
                'amount' => 4200,
                'currency' => 'TL',
                'transaction_date' => '2026-07-08',
                'description' => 'Kaynak sipariş cari hareketi',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'created_by' => $this->adminUser->id,
                'meta_json' => [
                    'manual' => [
                        'linked_order_id' => $order->id,
                        'linked_order_number' => $order->document_number,
                    ],
                ],
            ]);
        }

        return $order->fresh(['items.prints', 'workForms', 'procurements', 'printProductions', 'deliveries', 'payments']);
    }

    protected function createForeignTenantOrder(): Order
    {
        $customer = Company::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'legal_name' => 'Yabancı Müşteri Ltd.',
            'status' => 'active',
        ]);

        return Order::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-FOR-' . strtoupper(substr(uniqid(), -6)),
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
    }

    protected function createRevisionDraft(Order $order, ?User $user = null): Order
    {
        return $this->createQuoteDraftThroughRoute('admin.orders.revision-draft.store', $order, $user ?? $this->adminUser);
    }

    protected function createRepeatDraft(Order $order, ?User $user = null): Order
    {
        return $this->createQuoteDraftThroughRoute('admin.orders.repeat-order-draft.store', $order, $user ?? $this->adminUser);
    }

    protected function postAs(User $user, string $route, array $payload = []): TestResponse
    {
        return $this->actingAs($user)
            ->withServerVariables(['HTTP_HOST' => self::HOST])
            ->post($route, $payload);
    }

    protected function getAs(User $user, string $route): TestResponse
    {
        return $this->actingAs($user)
            ->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get($route);
    }

    private function createQuoteDraftThroughRoute(string $routeName, Order $order, User $user): Order
    {
        $existingIds = Order::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('document_type', 'quote')
            ->pluck('id')
            ->all();

        $this->postAs($user, route($routeName, $order))
            ->assertRedirect();

        return Order::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('document_type', 'quote')
            ->whereNotIn('id', $existingIds)
            ->latest('id')
            ->firstOrFail()
            ->fresh(['sourceOrder', 'items.prints']);
    }
}
