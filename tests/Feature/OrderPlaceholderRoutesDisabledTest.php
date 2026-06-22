<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPlaceholderRoutesDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
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
    }

    public function test_orders_create_redirects_to_index_instead_of_showing_placeholder_form(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.create'));

        $response->assertRedirect(route('admin.orders.index'));
        $response->assertSessionHas('info');
    }

    public function test_orders_store_does_not_create_record(): void
    {
        $beforeCount = Order::query()->count();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.store'), [
                'document_number' => 'SP-MANUAL-0001',
                'customer_company_id' => $this->customer->id,
            ]);

        $response->assertRedirect(route('admin.orders.index'));
        $response->assertSessionHasErrors('order');
        $this->assertSame($beforeCount, Order::query()->count());
        $this->assertDatabaseMissing('orders', ['document_number' => 'SP-MANUAL-0001']);
    }

    public function test_orders_edit_redirects_to_show_instead_of_showing_placeholder_screen(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.edit', $order));

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('info');
    }

    public function test_orders_update_does_not_overwrite_order_or_items(): void
    {
        $order = $this->createOrder();
        $item = $order->items()->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.orders.update', $order), [
                'document_number' => 'SP-UPDATED-9999',
                'notes' => 'Should not persist',
            ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHasErrors('order');

        $order->refresh();
        $item->refresh();

        $this->assertSame('SP-PLACEHOLDER-0001', $order->document_number);
        $this->assertSame('Placeholder disable test order', $order->notes);
        $this->assertSame('PLACEHOLDER-ITEM-001', $item->product_code);
    }

    public function test_orders_destroy_does_not_delete_order(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->delete(route('admin.orders.destroy', $order));

        $response->assertRedirect(route('admin.orders.index'));
        $response->assertSessionHasErrors('order');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'document_number' => 'SP-PLACEHOLDER-0001']);
    }

    public function test_orders_index_and_show_continue_to_work(): void
    {
        $order = $this->createOrder();

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $index->assertOk();
        $index->assertSee('Siparişler');
        $index->assertSee($order->document_number);

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.show', $order));

        $show->assertOk();
        $show->assertSee('Sipariş Özeti');
        $show->assertSee($order->document_number);
    }

    public function test_quote_conversion_still_creates_order(): void
    {
        $quote = $this->createQuoteViaHttp('TK-PLACEHOLDER-0001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote));

        $response->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'source_quote_id' => $quote->id,
            'document_type' => 'order',
        ]);
    }

    private function createOrder(): Order
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-PLACEHOLDER-0001',
            'source_quote_number' => 'TK-PLACEHOLDER-0001',
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => '2026-06-15',
            'valid_until' => '2026-06-25',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'product_total' => 1000,
            'print_total' => 250,
            'subtotal' => 1250,
            'vat_total' => 250,
            'grand_total' => 1500,
            'notes' => 'Placeholder disable test order',
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Placeholder Test Ürünü',
            'product_code' => 'PLACEHOLDER-ITEM-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'list_price' => 100,
            'discount_rate' => 0,
            'unit_price' => 100,
            'line_total' => 1000,
            'print_total' => 250,
            'vat_rate' => 20,
            'vat_amount' => 250,
            'total_amount' => 1500,
            'sequence_no' => 1,
            'has_print' => false,
        ]);

        return $order;
    }

    private function createQuoteViaHttp(string $documentNumber): Order
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-15',
                'valid_until' => '2026-06-22',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Order placeholder conversion test',
                'document_number' => $documentNumber,
                'items' => [
                    [
                        'product_name' => 'Placeholder Quote Ürünü',
                        'product_code' => 'PHQ-001',
                        'quantity' => '25',
                        'unit' => 'Adet',
                        'list_price' => '40',
                        'discount_rate' => '0',
                        'unit_price' => '40',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                    ],
                ],
            ])
            ->assertRedirect();

        return Order::query()->quotes()->latest('id')->firstOrFail();
    }
}
