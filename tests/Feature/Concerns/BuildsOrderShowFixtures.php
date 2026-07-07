<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemProcurement;
use App\Models\User;

trait BuildsOrderShowFixtures
{
    private const ORDER_SHOW_HOST = 'prodelya_core.test';

    protected User $orderShowAdminUser;
    protected Company $orderShowCustomer;

    protected function setUpOrderShowFixtures(): void
    {
        $this->orderShowAdminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->orderShowCustomer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
    }

    protected function createConvertedOrderForShow(array $overrides = []): Order
    {
        $defaultHasPrint = (bool) ($overrides['has_print'] ?? false);
        $quantity = (string) ($overrides['quantity'] ?? '100');
        $items = $overrides['items'] ?? [[
            'product_name' => $overrides['product_name'] ?? 'Sekmeli Test Ürünü',
            'product_code' => $overrides['product_code'] ?? 'TAB-001',
            'quantity' => $quantity,
            'unit' => $overrides['unit'] ?? 'Adet',
            'list_price' => '8.60',
            'discount_rate' => '10',
            'unit_price' => '7.74',
            'manual_unit_price' => '1',
            'vat_rate' => '20',
            'has_print' => $defaultHasPrint ? '1' : '0',
            'prints' => $defaultHasPrint ? [[
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf',
                'production_type' => 'İç üretim',
                'print_quantity' => $quantity,
                'print_unit_price' => '1',
                'note' => 'Test baskı',
            ]] : [],
        ]];

        $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->orderShowCustomer->id,
                'quote_date' => '2026-07-01',
                'valid_until' => '2026-07-10',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Sekmeli sipariş detay testi',
                'items' => $items,
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $hasAnyPrint = collect($items)->contains(fn (array $item): bool => (string) ($item['has_print'] ?? '0') === '1');

        if (! $hasAnyPrint) {
            $order->load(['procurements', 'workForms', 'items']);

            foreach ($order->procurements as $procurement) {
                $procurement->forceFill([
                    'procurement_status' => OrderItemProcurement::STATUS_NOT_REQUIRED,
                    'received_quantity' => (float) $procurement->requested_quantity,
                    'remaining_quantity' => 0,
                ])->save();
            }

            foreach ($order->workForms as $workForm) {
                $procurementSnapshot = is_array($workForm->procurement_snapshot) ? $workForm->procurement_snapshot : [];
                $workForm->forceFill([
                    'procurement_snapshot' => array_merge($procurementSnapshot, [
                        'procurement_status' => OrderItemProcurement::STATUS_NOT_REQUIRED,
                        'received_quantity' => (float) ($workForm->orderItem?->quantity ?? 0),
                    ]),
                ])->save();
            }
        }

        return $order;
    }
}
