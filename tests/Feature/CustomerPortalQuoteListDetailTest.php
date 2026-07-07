<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalQuoteListDetailTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private Company $company;
    private CompanyContact $contact;
    private CustomerPortalUser $portalUser;
    private User $adminUser;
    private string $tenantHost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->tenant->forceFill([
            'panel_subdomain' => 'portal-quotes-demo',
            'slug' => 'portal-quotes-demo',
            'status' => 'active',
        ])->save();

        $this->company->forceFill(['portal_enabled' => true])->save();

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Teklif Portal Yetkilisi',
            'title' => 'Satınalma',
            'email' => 'portal-quote-contact@example.test',
            'phone' => '02123334455',
            'mobile' => '05323334455',
            'is_primary' => true,
        ]);

        $this->portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Portal Quote User',
            'email' => 'portal-quote-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->tenantHost = 'portal-quotes-demo.prodelya_core.test';

        $this->enablePortalModule();
        $this->enablePortalFeature('customer_login');
        $this->enablePortalFeature('portal_quotes');
    }

    public function test_quote_list_and_detail_are_company_scoped_and_show_safe_sales_context(): void
    {
        $quote = $this->createQuote('TK-PORTAL-001', $this->tenant, $this->company, 'Portal Ürün 1');
        $this->createQuote('TK-PORTAL-002', $this->tenant, $this->company, 'Portal Ürün 2');

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Ayni Tenant Baska Musteri',
            'short_name' => 'Ayni Tenant Baska Musteri',
            'email' => 'other-company@example.test',
            'phone' => '02124445566',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        $otherCompanyQuote = $this->createQuote('TK-OTHER-COMPANY-001', $this->tenant, $otherCompany, 'Gizli Ürün');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Portal Quotes Other Tenant',
            'legal_name' => 'Portal Quotes Other Tenant Ltd.',
            'slug' => 'portal-quotes-other-tenant',
            'panel_subdomain' => 'portal-quotes-other-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $otherTenantCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Baska Tenant Musteri',
            'short_name' => 'Baska Tenant Musteri',
            'email' => 'other-tenant-company@example.test',
            'phone' => '02127778899',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        $otherTenantQuote = $this->createQuote('TK-OTHER-TENANT-001', $otherTenant, $otherTenantCompany, 'Baska Tenant Ürün');

        $list = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler?q=Portal'));

        $list->assertOk()
            ->assertSee('TK-PORTAL-001')
            ->assertSee('TK-PORTAL-002')
            ->assertSee('Portal Ürün 1')
            ->assertSee('Teklifi Görüntüle')
            ->assertDontSee('TK-OTHER-COMPANY-001')
            ->assertDontSee('TK-OTHER-TENANT-001');

        $detail = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $quote->id));

        $detail->assertOk()
            ->assertSee('TK-PORTAL-001')
            ->assertSee($this->company->legal_name)
            ->assertSee('Portal Ürün 1')
            ->assertSee('UV Baskı Tek taraf')
            ->assertSee('100')
            ->assertSee('150,00 TL')
            ->assertSee('2.500,00 TL')
            ->assertSee('15.000,00 TL')
            ->assertSee('Müşteriye gösterilebilir teklif notu')
            ->assertDontSee('purchase_total')
            ->assertDontSee('purchase_unit_price')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('subcontractor_cost')
            ->assertDontSee('setup_cost')
            ->assertDontSee('profit')
            ->assertDontSee('balance_due')
            ->assertDontSee('payment_amount')
            ->assertDontSee('finance_warning')
            ->assertDontSee('group_code')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('internal_note')
            ->assertDontSee('notification_logs')
            ->assertDontSee('current_account_transactions');

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $otherCompanyQuote->id))
            ->assertNotFound();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $otherTenantQuote->id))
            ->assertNotFound();
    }

    public function test_quote_feature_guard_and_guest_admin_access_rules_work(): void
    {
        $quote = $this->createQuote('TK-GUARD-001', $this->tenant, $this->company, 'Guard Ürünü');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler'))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.quotes.index'))
            ->assertRedirect(route('customer.login'));

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->where('feature_key', 'portal_quotes')
            ->update(['is_enabled' => false]);

        $closed = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.quotes.index'));

        $this->assertContains($closed->getStatusCode(), [403, 404]);

        $this->enablePortalFeature('portal_quotes');

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $quote->id))
            ->assertOk();
    }

    public function test_quote_approval_helper_respects_feature_and_keeps_public_route_public(): void
    {
        $quote = $this->createQuote('TK-APPROVAL-001', $this->tenant, $this->company, 'Onay Ürünü');

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );

        $approvalRequest = app(QuoteApprovalService::class)->sendToCustomer($quote, [], $this->adminUser);

        $detailWithHelper = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $quote->id));

        $detailWithHelper->assertOk()
            ->assertSee('Bu teklif için onay bağlantısı hazır.')
            ->assertSee('Teklifinizi inceleyip onaylayabilir veya revize isteyebilirsiniz.')
            ->assertSee('Teklifi İncele')
            ->assertSee(route('customer.portal.quotes.approval.open', $quote), false)
            ->assertDontSee($approvalRequest->token);

        $helperRedirect = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $quote->id . '/onay-linki'));

        $helperRedirect->assertRedirect(route('public.quotes.approval.show', ['token' => $approvalRequest->token]));

        $this->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get(route('public.quotes.approval.show', ['token' => $approvalRequest->token]))
            ->assertOk();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'quote_customer_approval')
            ->where('feature_key', 'public_quote_approval')
            ->update(['is_enabled' => false]);

        $detailWithoutHelper = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $quote->id));

        $detailWithoutHelper->assertOk()
            ->assertDontSee('Teklifi İncele')
            ->assertDontSee(route('customer.portal.quotes.approval.open', $quote), false);
    }

    private function createQuote(string $documentNumber, TenantAccount $tenant, Company $company, string $productName): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $company->id,
            'status' => 'draft',
            'workflow_status' => 'draft',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_WAITING,
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'currency' => 'TL',
            'subtotal' => 12500,
            'vat_total' => 2500,
            'grand_total' => 15000,
            'product_total' => 12500,
            'print_total' => 2500,
            'notes' => 'Müşteriye gösterilebilir teklif notu',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $productName,
            'product_code' => $documentNumber . '-CODE',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Portal detay ürünü',
            'price_snapshot' => [
                'purchase_total' => 100,
                'purchase_unit_price' => 1,
                'supplier_cost' => 2,
                'subcontractor_cost' => 3,
                'setup_cost' => 4,
                'profit' => 5,
                'margin' => 6,
                'group_code' => 'SECRET-GROUP',
                'file_path' => '/hidden/path.pdf',
            ],
            'list_price' => 125,
            'discount_rate' => 0,
            'unit_price' => 125,
            'line_total' => 12500,
            'status' => 'active',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'print_unit_price' => 25,
            'print_total' => 2500,
            'note' => 'Müşteriye gösterilebilir baskı notu',
            'status' => 'draft',
        ]);

        return $quote;
    }

    private function enablePortalModule(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($this->tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    private function enablePortalFeature(string $featureKey): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => $featureKey,
            ],
            ['is_enabled' => true]
        );
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenantHost . $path;
    }
}
