<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Support\Carbon;

trait BuildsQuoteOrderListFixtures
{
    private const CENTRAL_HOST = 'prodelya_core.test';

    protected User $adminUser;
    protected TenantAccount $tenant;
    protected Company $customer;

    protected function setUpQuoteOrderListFixtures(): void
    {
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    protected function tenantUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }

    protected function createQuote(array $overrides = [], ?Carbon $createdAt = null): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-' . fake()->unique()->numerify('LIST####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => '2026-07-04',
            'valid_until' => '2026-07-11',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'product_total' => 1000,
            'print_total' => 250,
            'subtotal' => 1250,
            'vat_total' => 250,
            'grand_total' => 1500,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        OrderItem::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Liste Teklif Ürünü',
            'product_code' => 'QL-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'line_total' => 1000,
            'unit_price' => 10,
            'has_print' => true,
            'print_total' => 250,
            'status' => 'pending',
            'product_snapshot' => [
                'group_code' => 'HIDDEN-GROUP',
                'file_path' => '/secret/quote-path',
            ],
            'price_snapshot' => [
                'raw' => 'hidden',
            ],
        ]);

        if ($createdAt) {
            $quote->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        return $quote->fresh();
    }

    protected function createConvertedQuote(array $quoteOverrides = [], array $orderOverrides = [], ?Carbon $createdAt = null): array
    {
        $quote = $this->createQuote(array_merge([
            'status' => 'approved',
            'workflow_status' => 'quote_converted',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
        ], $quoteOverrides), $createdAt);

        $order = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-' . fake()->unique()->numerify('LIST####'),
            'source_quote_id' => $quote->id,
            'source_quote_number' => $quote->document_number,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-07-04',
            'valid_until' => '2026-07-14',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'product_total' => 3000,
            'print_total' => 600,
            'subtotal' => 3600,
            'vat_total' => 720,
            'grand_total' => 4320,
            'created_by' => $this->adminUser->id,
        ], $orderOverrides));

        return [$quote->fresh(), $order->fresh()];
    }

    protected function createOrder(array $overrides = [], array $workflow = [], ?Carbon $createdAt = null): Order
    {
        $order = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-' . fake()->unique()->numerify('LIST####'),
            'source_quote_number' => 'TK-' . fake()->unique()->numerify('REF####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-07-04',
            'valid_until' => '2026-07-14',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'product_total' => 6000,
            'print_total' => 500,
            'subtotal' => 6500,
            'vat_total' => 1300,
            'grand_total' => 7800,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Liste Sipariş Ürünü',
            'product_code' => 'OL-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Liste sipariş satırı',
            'product_snapshot' => [
                'group_code' => 'HIDDEN-GROUP-CODE',
                'file_path' => '/secret/order-path',
                'transaction_id' => 'hidden-transaction-id',
            ],
            'price_snapshot' => [
                'projection' => 'hidden',
            ],
            'line_total' => 6000,
            'unit_price' => 60,
            'has_print' => true,
            'print_total' => 500,
            'status' => 'pending',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source_quote_number' => $order->source_quote_number,
            'work_form_number' => 'WF-' . fake()->unique()->numerify('####'),
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => fake()->uuid(),
            'graphic_snapshot' => ['status' => $workflow['graphic_status'] ?? 'bekliyor'],
            'production_snapshot' => [],
            'delivery_snapshot' => [],
            'procurement_snapshot' => [],
            'created_by' => $this->adminUser->id,
        ]);

        OrderItemProcurement::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'requires_procurement' => true,
            'fulfillment_source' => OrderItemProcurement::FULFILLMENT_SUPPLIER,
            'procurement_status' => $workflow['procurement_status'] ?? OrderItemProcurement::STATUS_PENDING,
            'requested_quantity' => 100,
            'received_quantity' => ($workflow['procurement_status'] ?? null) === OrderItemProcurement::STATUS_FULLY_RECEIVED ? 100 : 0,
            'remaining_quantity' => ($workflow['procurement_status'] ?? null) === OrderItemProcurement::STATUS_FULLY_RECEIVED ? 0 : 100,
            'created_by' => $this->adminUser->id,
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
            'print_unit_price' => 5,
            'print_total' => 500,
            'status' => 'pending',
        ]);

        OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'work_form_id' => $workForm->id,
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => $workflow['production_status'] ?? OrderItemPrintProduction::STATUS_PENDING,
            'planned_quantity' => 100,
            'completed_quantity' => ($workflow['production_status'] ?? null) === OrderItemPrintProduction::STATUS_COMPLETED ? 100 : 0,
            'remaining_quantity' => ($workflow['production_status'] ?? null) === OrderItemPrintProduction::STATUS_COMPLETED ? 0 : 100,
            'qc_status' => OrderItemPrintProduction::QC_WAITING,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItemWorkFormDelivery::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'delivery_status' => $workflow['delivery_status'] ?? OrderItemWorkFormDelivery::STATUS_PENDING,
            'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
            'planned_quantity' => 100,
            'delivered_quantity' => ($workflow['delivery_status'] ?? null) === OrderItemWorkFormDelivery::STATUS_DELIVERED ? 100 : 0,
            'remaining_quantity' => ($workflow['delivery_status'] ?? null) === OrderItemWorkFormDelivery::STATUS_DELIVERED ? 0 : 100,
            'financial_warning' => OrderItemWorkFormDelivery::WARNING_NONE,
            'created_by' => $this->adminUser->id,
        ]);

        $paymentMode = $workflow['payment_mode'] ?? null;

        if ($paymentMode === 'partial') {
            OrderPayment::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'order_id' => $order->id,
                'customer_company_id' => $this->customer->id,
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => 3000,
                'currency' => 'TL',
                'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
                'paid_at' => '2026-07-05 10:00:00',
                'created_by' => $this->adminUser->id,
            ]);
        }

        if ($paymentMode === 'pending') {
            OrderPayment::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'order_id' => $order->id,
                'customer_company_id' => $this->customer->id,
                'payment_type' => OrderPayment::TYPE_COLLECTION,
                'amount' => 7800,
                'currency' => 'TL',
                'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
                'due_date' => '2026-07-06 10:00:00',
                'created_by' => $this->adminUser->id,
            ]);
        }

        if ($createdAt) {
            $order->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        return $order->fresh();
    }

    protected function createForeignTenantFixtures(): array
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Dış Tenant',
            'legal_name' => 'Dış Tenant Ltd. Şti.',
            'slug' => 'dis-tenant-' . fake()->unique()->numerify('###'),
            'panel_subdomain' => 'distenant' . fake()->unique()->numerify('###'),
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Tenant Dışı Müşteri',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-FOREIGN-001',
            'customer_company_id' => $company->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => '2026-07-04',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1000,
            'vat_total' => 200,
            'grand_total' => 1200,
            'product_total' => 800,
            'print_total' => 200,
            'created_by' => $this->adminUser->id,
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-FOREIGN-001',
            'source_quote_number' => 'TK-FOREIGN-001',
            'customer_company_id' => $company->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-07-04',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 2000,
            'vat_total' => 400,
            'grand_total' => 2400,
            'product_total' => 1800,
            'print_total' => 200,
            'created_by' => $this->adminUser->id,
        ]);

        return [$tenant, $company, $quote, $order];
    }

    protected function createUserWithRole(string $roleKey): User
    {
        $user = User::query()->create([
            'name' => 'Liste Rol ' . ucfirst($roleKey),
            'email' => 'liste-rol-' . $roleKey . '-' . fake()->unique()->numerify('###') . '@prodelya.local',
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
