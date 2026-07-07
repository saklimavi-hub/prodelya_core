<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\TenantAccount;
use App\Models\TenantDeliveryType;
use App\Models\User;
use App\Services\TenantDeliveryTypeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteToOrderDeliveryTypeCarryoverTest extends TestCase
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
        $this->tenant = $this->adminUser->preferredTenant() ?? TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()->where('tenant_account_id', $this->tenant->id)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
    }

    public function test_quote_to_order_keeps_delivery_type_id_label_and_work_form_snapshots(): void
    {
        $type = app(TenantDeliveryTypeService::class)->ensureDefaultsForTenant($this->tenant)
            ->firstWhere('name', 'Ambar');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-07-01',
                'valid_until' => '2026-07-08',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type_id' => $type?->id,
                'items' => [[
                    'product_name' => 'Carryover Ürünü',
                    'product_code' => 'CARRY-001',
                    'quantity' => '25',
                    'unit' => 'Adet',
                    'list_price' => '20',
                    'discount_rate' => '0',
                    'unit_price' => '20',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '0',
                    'prints' => [],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $order->load('workForms');
        $workForm = $order->workForms->firstOrFail();

        $this->assertSame($type?->id, $order->delivery_type_id);
        $this->assertSame('Ambar', $order->delivery_type);
        $this->assertSame('Ambar', data_get($workForm->order_snapshot, 'delivery_type'));
        $this->assertSame('Ambar', data_get($workForm->delivery_snapshot, 'delivery_type'));
    }
}
