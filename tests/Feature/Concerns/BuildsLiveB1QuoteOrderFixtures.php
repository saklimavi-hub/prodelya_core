<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;

trait BuildsLiveB1QuoteOrderFixtures
{
    protected string $centralHost = 'prodelya_core.test';

    protected User $adminUser;
    protected TenantAccount $tenant;
    protected Company $customer;
    protected ?Company $partner = null;

    protected function setUpLiveB1Fixtures(): void
    {
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
        $this->partner = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('status', 'active')
            ->whereKeyNot($this->customer->id)
            ->orderBy('id')
            ->first();
    }

    protected function createQuoteViaHttp(array $overrides = []): Order
    {
        $withPrint = (bool) ($overrides['with_print'] ?? true);
        $documentNumber = (string) ($overrides['document_number'] ?? ('TK-LIVE-B1-' . random_int(1000, 9999)));
        $productCode = (string) ($overrides['product_code'] ?? 'LIVE-B1-001');
        $productName = (string) ($overrides['product_name'] ?? 'LIVE B1 Test Ürünü');
        $quantity = (string) ($overrides['quantity'] ?? '100');
        $listPrice = (string) ($overrides['list_price'] ?? '25');
        $discountRate = (string) ($overrides['discount_rate'] ?? '0');
        $unitPrice = (string) ($overrides['unit_price'] ?? '25');
        $printCount = (int) ($overrides['print_count'] ?? ($withPrint ? 1 : 0));
        $currency = (string) ($overrides['currency'] ?? 'TL');
        $invoiceStatus = (string) ($overrides['invoice_status'] ?? 'fatura');

        $prints = [];
        for ($i = 0; $i < $printCount; $i++) {
            $prints[] = [
                'print_type' => $i === 0 ? 'UV Baskı' : 'Lazer',
                'print_option' => $i === 0 ? 'Tek taraf baskılı' : 'Tek taraf lazer',
                'production_type' => $i === 0 ? 'İç üretim' : 'Dış üretim / Fason',
                'subcontractor_company_id' => $i === 0 ? null : $this->partner?->id,
                'print_quantity' => $quantity,
                'print_unit_price' => (string) ($i === 0 ? '5' : '10'),
                'note' => 'LIVE-B1 baskı ' . ($i + 1),
            ];
        }

        $payload = [
            'customer_company_id' => $overrides['customer_company_id'] ?? $this->customer->id,
            'quote_date' => $overrides['quote_date'] ?? '2026-07-16',
            'valid_until' => $overrides['valid_until'] ?? '2026-07-23',
            'invoice_status' => $invoiceStatus,
            'currency' => $currency,
            'delivery_type' => $overrides['delivery_type'] ?? 'Kargo',
            'notes' => $overrides['notes'] ?? ('LIVE-B1 fixture ' . $documentNumber),
            'items' => [[
                'product_name' => $productName,
                'product_code' => $productCode,
                'quantity' => $quantity,
                'unit' => $overrides['unit'] ?? 'Adet',
                'list_price' => $listPrice,
                'discount_rate' => $discountRate,
                'unit_price' => $unitPrice,
                'manual_unit_price' => '1',
                'vat_rate' => $overrides['vat_rate'] ?? '20',
                'has_print' => $withPrint ? '1' : '0',
                'prints' => $prints,
            ]],
        ];

        if (($overrides['customer_approval_status'] ?? null) === Order::CUSTOMER_APPROVAL_APPROVED) {
            $payload['customer_approval_status'] = Order::CUSTOMER_APPROVAL_APPROVED;
        }

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->post('/admin/promotion-quotes', $payload);

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        if (($overrides['approve_quote'] ?? true) === true) {
            $quote->forceFill([
                'status' => 'approved',
                'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
                'approved_at' => now(),
            ])->save();
        }

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        return $quote->fresh(['items.prints']);
    }

    protected function convertQuote(Order $quote): Order
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return Order::query()
            ->orders()
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();
    }

    protected function createUserWithRole(string $roleKey, ?string $email = null): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $user = User::factory()->create([
            'email' => $email ?? ($roleKey . '.' . random_int(1000, 9999) . '@prodelya.local'),
            'password' => 'password',
        ]);

        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }

    protected function setTenantProcessDepth(string $depth): void
    {
        TenantSetting::setValue($this->tenant->id, 'process_depth', $depth, 'string');
    }

    protected function createForeignTenantQuote(): Order
    {
        $foreignTenant = TenantAccount::query()->whereKeyNot($this->tenant->id)->first();
        if (! $foreignTenant) {
            $foreignTenant = TenantAccount::query()->create([
                'name' => 'Foreign Tenant Ltd.',
                'company_name' => 'Foreign Tenant Ltd.',
                'slug' => 'foreign-tenant-live-b1',
                'status' => 'active',
                'subdomain' => 'foreign-live-b1',
            ]);
        }

        $foreignCustomer = Company::query()->firstOrCreate([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Foreign Customer Ltd.',
        ], [
            'name' => 'Foreign Customer Ltd.',
            'status' => 'active',
        ]);

        return Order::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-FOREIGN-' . random_int(1000, 9999),
            'customer_company_id' => $foreignCustomer->id,
            'status' => 'approved',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'subtotal' => 100,
            'vat_total' => 20,
            'grand_total' => 120,
            'product_total' => 100,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);
    }
}
