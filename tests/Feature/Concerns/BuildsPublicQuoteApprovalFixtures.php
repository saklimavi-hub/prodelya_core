<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\QuoteApprovalService;

trait BuildsPublicQuoteApprovalFixtures
{
    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUpPublicQuoteApprovalFixtures(): void
    {
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );
    }

    protected function createPublicApprovalContext(
        string $documentNumber = 'TK-PUBLIC-TEMPLATE-001',
        array $quoteOverrides = [],
        ?array $items = null,
        array $recipientData = []
    ): array {
        $quote = $this->createPublicQuote($documentNumber, $quoteOverrides, $items);
        $request = app(QuoteApprovalService::class)->sendToCustomer($quote, array_merge([
            'contact_email' => 'public-approval@example.test',
        ], $recipientData), $this->adminUser);

        return [
            'quote' => $quote->fresh(),
            'request' => $request->fresh(),
        ];
    }

    protected function createPublicQuote(string $documentNumber, array $quoteOverrides = [], ?array $items = null): Order
    {
        $items ??= $this->defaultPublicApprovalItems();
        $productTotal = collect($items)->sum(fn (array $item) => (float) ($item['line_total'] ?? 0));
        $printTotal = collect($items)->sum(fn (array $item) => (float) ($item['print_total'] ?? 0));
        $vatBreakdown = $quoteOverrides['vat_breakdown_json'] ?? $this->deriveVatBreakdown($items);
        $vatTotal = collect($vatBreakdown)->sum(fn (array $row) => (float) ($row['total'] ?? 0));
        $subtotal = $quoteOverrides['subtotal'] ?? ($productTotal + $printTotal);
        $grandTotal = $quoteOverrides['grand_total'] ?? ($subtotal + $vatTotal);

        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-07-01',
            'valid_until' => '2026-07-08',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'notes' => 'Müşteriye gösterilebilir güvenli teklif notu',
            'currency' => 'TL',
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'grand_total' => $grandTotal,
            'product_total' => $productTotal,
            'print_total' => $printTotal,
            'vat_breakdown_json' => $vatBreakdown,
            'show_print_price_details_to_customer' => $quoteOverrides['show_print_price_details_to_customer'] ?? true,
            'created_by' => $this->adminUser->id,
        ], $quoteOverrides));

        foreach ($items as $itemData) {
            $item = OrderItem::query()->create(array_merge([
                'tenant_account_id' => $this->tenant->id,
                'order_id' => $quote->id,
                'item_type' => 'product',
                'product_source' => 'manual',
                'product_name' => 'Public Approval Ürünü',
                'product_code' => 'PQA-001',
                'quantity' => 100,
                'unit' => 'Adet',
                'description' => 'Müşteriye görünür ürün açıklaması',
                'product_snapshot' => [
                    'display_name' => 'Public Approval Ürünü',
                    'group_code' => 'SECRET-GROUP',
                    'projection' => ['hidden' => true],
                ],
                'price_snapshot' => [
                    'product_total' => 1200,
                    'vat_rate' => 20,
                    'vat_breakdown' => [
                        ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                    ],
                    'supplier_cost' => 99,
                    'purchase_price' => 88,
                    'profit' => 77,
                    'margin' => 66,
                    'raw' => ['hidden' => true],
                    'payload' => ['hidden' => true],
                ],
                'stock_snapshot' => ['visible_stock_quantity' => 500],
                'list_price' => 12,
                'discount_rate' => 0,
                'unit_price' => 12,
                'line_total' => 1200,
                'has_print' => false,
                'print_total' => 0,
                'status' => 'pending',
            ], collect($itemData)->except('prints')->all()));

            foreach ($itemData['prints'] ?? [] as $print) {
                $item->prints()->create(array_merge([
                    'tenant_account_id' => $this->tenant->id,
                    'order_id' => $quote->id,
                    'order_item_id' => $item->id,
                    'print_type' => 'UV Baskı',
                    'print_option' => 'Çift Taraf Baskı',
                    'print_quantity' => 100,
                    'print_unit_price' => 4,
                    'print_total' => 400,
                    'note' => 'Müşteriye görünür baskı notu',
                    'status' => 'draft',
                ], $print));
            }
        }

        return $quote->fresh(['items.prints']);
    }

    protected function quoteApprovalShowUrl(QuoteApprovalRequest $request): string
    {
        return route('public.quotes.approval.show', ['token' => $request->token]);
    }

    private function defaultPublicApprovalItems(): array
    {
        return [
            [
                'product_name' => 'Public Approval Ana Ürün',
                'product_code' => 'PQA-001',
                'quantity' => 100,
                'unit_price' => 12,
                'line_total' => 1200,
                'has_print' => true,
                'print_total' => 400,
                'price_snapshot' => [
                    'product_total' => 1200,
                    'vat_rate' => 20,
                    'vat_breakdown' => [
                        ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                        ['rate' => 20, 'total' => 80, 'scope' => 'print'],
                    ],
                    'supplier_cost' => 99,
                    'purchase_price' => 88,
                    'profit' => 77,
                    'margin' => 66,
                    'group_code' => 'SECRET-GROUP',
                    'meta_json' => ['hidden' => true],
                    'payload' => ['hidden' => true],
                ],
                'prints' => [[
                    'print_type' => 'UV Baskı',
                    'print_option' => 'Çift Taraf Baskı',
                    'print_quantity' => 100,
                    'print_unit_price' => 4,
                    'print_total' => 400,
                    'note' => 'Müşteriye görünür baskı notu',
                ]],
            ],
            [
                'product_name' => 'Public Approval Yardımcı Ürün',
                'product_code' => 'PQA-002',
                'quantity' => 50,
                'unit_price' => 8,
                'line_total' => 400,
                'has_print' => false,
                'print_total' => 0,
                'price_snapshot' => [
                    'product_total' => 400,
                    'vat_rate' => 20,
                    'vat_breakdown' => [
                        ['rate' => 20, 'total' => 80, 'scope' => 'product'],
                    ],
                    'supplier_cost' => 50,
                    'group_code' => 'SECRET-GROUP-2',
                ],
            ],
        ];
    }

    private function deriveVatBreakdown(array $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            foreach ((array) data_get($item, 'price_snapshot.vat_breakdown', []) as $slice) {
                $rows[] = [
                    'rate' => (float) data_get($slice, 'rate', 0),
                    'total' => (float) data_get($slice, 'total', 0),
                    'scope' => (string) data_get($slice, 'scope', 'general'),
                ];
            }
        }

        return $rows;
    }
}
