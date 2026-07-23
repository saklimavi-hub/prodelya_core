<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalQuotePriceDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private Company $company;
    private CustomerPortalUser $portalUser;
    private string $tenantHost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->tenant->forceFill([
            'panel_subdomain' => 'portal-price-demo',
            'slug' => 'portal-price-demo',
            'status' => 'active',
        ])->save();

        $this->company->forceFill(['portal_enabled' => true])->save();

        $contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Portal Fiyat Yetkilisi',
            'email' => 'portal-price@example.test',
            'mobile' => '05323334455',
            'is_primary' => true,
        ]);

        $this->portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $contact->id,
            'name' => 'Portal Price User',
            'email' => 'portal-price-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->tenantHost = 'portal-price-demo.prodelya_core.test';

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => 'customer_login',
            ],
            ['is_enabled' => true]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => 'portal_quotes',
            ],
            ['is_enabled' => true]
        );
        TenantSetting::setValue($this->tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    public function test_customer_portal_quote_uses_customer_facing_prices_and_hides_internal_fields(): void
    {
        $quote = $this->createQuote('TK-PORTAL-FIYAT-001', false);

        $response = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get('http://' . $this->tenantHost . '/musteri-portal/teklifler/' . $quote->id);

        $response->assertOk()
            ->assertSee('Baskı Dahil Birim Fiyat')
            ->assertSee('Baskı Dahil Satır Toplamı')
            ->assertSee('15,00 TL')
            ->assertSee('1.500,00 TL')
            ->assertDontSee('Baskı Birim Fiyatı:')
            ->assertDontSee('Baskı Toplamı:')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('profit')
            ->assertDontSee('group_code');
    }

    public function test_customer_portal_quote_shows_separate_product_and_print_prices_when_breakdown_is_visible(): void
    {
        $quote = $this->createQuote('TK-PORTAL-FIYAT-002', true);

        $response = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get('http://' . $this->tenantHost . '/musteri-portal/teklifler/' . $quote->id);

        $response->assertOk()
            ->assertSee('Ürün Birim Fiyatı')
            ->assertSee('Ürün Toplamı')
            ->assertSee('5,00 TL')
            ->assertSee('500,00 TL')
            ->assertSee('Ürün + Baskı Toplamı')
            ->assertSee('1.500,00 TL')
            ->assertSee('Baskı Birim Fiyatı: 10,00 TL')
            ->assertSee('Baskı Toplamı: 1.000,00 TL');
    }

    private function createQuote(string $documentNumber, bool $showPrintPriceDetails): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->company->id,
            'status' => 'draft',
            'workflow_status' => 'draft',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_WAITING,
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'currency' => 'TL',
            'subtotal' => 1500,
            'vat_total' => 300,
            'grand_total' => 1800,
            'product_total' => 500,
            'print_total' => 1000,
            'show_print_price_details_to_customer' => $showPrintPriceDetails,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Portal Fiyat Ürünü',
            'product_code' => 'PF-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Portal müşteri ürünü',
            'price_snapshot' => [
                'supplier_cost' => 2,
                'profit' => 5,
                'group_code' => 'SECRET-GROUP',
            ],
            'unit_price' => 5,
            'line_total' => 500,
            'has_print' => true,
            'print_total' => 1000,
            'status' => 'active',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'print_unit_price' => 10,
            'print_total' => 1000,
            'note' => 'Baskı notu',
            'status' => 'draft',
        ]);

        return $quote;
    }
}
