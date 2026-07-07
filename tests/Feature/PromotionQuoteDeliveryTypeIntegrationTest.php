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

class PromotionQuoteDeliveryTypeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    private function tenant(): TenantAccount
    {
        return $this->adminUser->preferredTenant() ?? TenantAccount::query()->firstOrFail();
    }

    public function test_create_screen_uses_select_and_quote_save_preserves_delivery_type_id_and_label(): void
    {
        $tenant = $this->tenant();
        $service = app(TenantDeliveryTypeService::class);
        $service->ensureDefaultsForTenant($tenant);
        $defaultType = $service->defaultForTenant($tenant->id);
        $customer = Company::query()->where('tenant_account_id', $tenant->id)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $create->assertOk()
            ->assertSee('name="delivery_type_id"', false)
            ->assertSee('Ofis Teslim');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-01',
                'valid_until' => '2026-07-08',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'delivery_type_id' => $defaultType?->id,
                'items' => [[
                    'product_name' => 'Delivery Type Ürünü',
                    'product_code' => 'DT-001',
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'unit_price' => '25',
                    'list_price' => '25',
                    'discount_rate' => '0',
                    'manual_unit_price' => '1',
                    'vat_rate' => '0',
                    'has_print' => '0',
                    'prints' => [],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $this->assertSame($defaultType?->id, $quote->delivery_type_id);
        $this->assertSame($defaultType?->name, $quote->delivery_type);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $edit->assertOk()
            ->assertSee('name="delivery_type_id"', false)
            ->assertSee($defaultType?->name ?? '');
    }

    public function test_passive_or_other_tenant_delivery_type_cannot_be_used_for_new_quote_and_legacy_string_fallback_survives(): void
    {
        $tenant = $this->tenant();
        $service = app(TenantDeliveryTypeService::class);
        $service->ensureDefaultsForTenant($tenant);
        $customer = Company::query()->where('tenant_account_id', $tenant->id)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $passive = TenantDeliveryType::query()->create([
            'tenant_account_id' => $tenant->id,
            'name' => 'Pasif Tip',
            'code' => 'pasif-tip',
            'is_active' => false,
            'is_default' => false,
            'sort_order' => 999,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.promotion-quotes.create'))
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-01',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'delivery_type_id' => $passive->id,
                'items' => [[
                    'product_name' => 'Pasif Tip Ürünü',
                    'quantity' => '1',
                    'unit' => 'Adet',
                    'unit_price' => '10',
                    'manual_unit_price' => '1',
                    'has_print' => '0',
                    'prints' => [],
                ]],
            ])
            ->assertSessionHasErrors('delivery_type_id');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-01',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'delivery_type' => 'Legacy Kurye',
                'items' => [[
                    'product_name' => 'Legacy Tip Ürünü',
                    'quantity' => '1',
                    'unit' => 'Adet',
                    'unit_price' => '10',
                    'manual_unit_price' => '1',
                    'has_print' => '0',
                    'prints' => [],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $this->assertNull($quote->delivery_type_id);
        $this->assertSame('Legacy Kurye', $quote->delivery_type);
    }
}
