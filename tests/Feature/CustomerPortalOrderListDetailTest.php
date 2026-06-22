<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemWorkForm;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalOrderListDetailTest extends TestCase
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
            'panel_subdomain' => 'portal-orders-demo',
            'slug' => 'portal-orders-demo',
            'status' => 'active',
        ])->save();

        $this->company->forceFill(['portal_enabled' => true])->save();

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Sipariş Portal Yetkilisi',
            'title' => 'Operasyon',
            'email' => 'portal-order-contact@example.test',
            'phone' => '02123334455',
            'mobile' => '05323334455',
            'is_primary' => true,
        ]);

        $this->portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Portal Order User',
            'email' => 'portal-order-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->tenantHost = 'portal-orders-demo.prodelya_core.test';

        $this->enablePortalModule();
        $this->enablePortalFeature('customer_login');
        $this->enablePortalFeature('portal_orders');
    }

    public function test_order_list_and_detail_are_company_scoped_and_show_safe_tracking_context(): void
    {
        ['order' => $order, 'work_form' => $workForm] = $this->createOrder(
            'SP-PORTAL-001',
            $this->tenant,
            $this->company,
            'Portal Sipariş Ürünü 1'
        );
        $this->createOrder('SP-PORTAL-002', $this->tenant, $this->company, 'Portal Sipariş Ürünü 2');

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Ayni Tenant Baska Musteri',
            'short_name' => 'Ayni Tenant Baska Musteri',
            'email' => 'other-order-company@example.test',
            'phone' => '02124445566',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        ['order' => $otherCompanyOrder, 'work_form' => $otherCompanyWorkForm] = $this->createOrder(
            'SP-OTHER-COMPANY-001',
            $this->tenant,
            $otherCompany,
            'Gizli Sipariş'
        );

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Portal Orders Other Tenant',
            'legal_name' => 'Portal Orders Other Tenant Ltd.',
            'slug' => 'portal-orders-other-tenant',
            'panel_subdomain' => 'portal-orders-other-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);
        $otherTenantCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Baska Tenant Musteri',
            'short_name' => 'Baska Tenant Musteri',
            'email' => 'other-order-tenant-company@example.test',
            'phone' => '02127778899',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        ['order' => $otherTenantOrder] = $this->createOrder(
            'SP-OTHER-TENANT-001',
            $otherTenant,
            $otherTenantCompany,
            'Baska Tenant Sipariş'
        );

        $list = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler?q=Portal'));

        $list->assertOk()
            ->assertSee('SP-PORTAL-001')
            ->assertSee('SP-PORTAL-002')
            ->assertSee('Portal Sipariş Ürünü 1')
            ->assertSee('Siparişi Görüntüle')
            ->assertSee('Sipariş Takibi')
            ->assertDontSee('SP-OTHER-COMPANY-001')
            ->assertDontSee('SP-OTHER-TENANT-001')
            ->assertDontSee('12.500,00 TL')
            ->assertDontSee('finance_warning');

        $detail = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $order->id));

        $detail->assertOk()
            ->assertSee('SP-PORTAL-001')
            ->assertSee($this->company->legal_name)
            ->assertSee('Portal Sipariş Ürünü 1')
            ->assertSee('UV Baskı Tek taraf')
            ->assertSee('100')
            ->assertSee('Grafik hazır')
            ->assertSee('Ürününüz hazırlanıyor')
            ->assertSee('Üretim devam ediyor')
            ->assertSee('Teslimat bekliyor')
            ->assertSee('TRK-001')
            ->assertSee('Müşteri Takip Ekranı')
            ->assertSee('/musteri-portal/siparisler/' . $order->id . '/takip/' . $workForm->id, false)
            ->assertDontSee($workForm->public_tracking_token)
            ->assertDontSee('Public Tracking')
            ->assertDontSee('125,00 TL')
            ->assertDontSee('12.500,00 TL')
            ->assertDontSee('2.500,00 TL')
            ->assertDontSee('purchase_total')
            ->assertDontSee('payment_amount')
            ->assertDontSee('balance_due')
            ->assertDontSee('finance_warning')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('subcontractor_cost')
            ->assertDontSee('setup_cost')
            ->assertDontSee('profit')
            ->assertDontSee('group_code')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('internal_note')
            ->assertDontSee('internal attachment')
            ->assertDontSee('notification_logs')
            ->assertDontSee('current_account_transactions');

        $trackingRedirect = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $order->id . '/takip/' . $workForm->id));

        $trackingRedirect->assertRedirect(route('public.work-forms.track', ['token' => $workForm->public_tracking_token]));

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $otherCompanyOrder->id))
            ->assertNotFound();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $otherTenantOrder->id))
            ->assertNotFound();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $otherCompanyOrder->id . '/takip/' . $otherCompanyWorkForm->id))
            ->assertNotFound();
    }

    public function test_order_feature_guard_and_guest_admin_access_rules_work(): void
    {
        ['order' => $order] = $this->createOrder('SP-GUARD-001', $this->tenant, $this->company, 'Guard Siparişi');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler'))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.orders.index'))
            ->assertRedirect(route('customer.login'));

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->where('feature_key', 'portal_orders')
            ->update(['is_enabled' => false]);

        $closed = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.orders.index'));

        $this->assertContains($closed->getStatusCode(), [403, 404]);

        $this->enablePortalFeature('portal_orders');

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $order->id))
            ->assertOk();
    }

    public function test_public_routes_remain_public_while_portal_tracking_helper_stays_scoped(): void
    {
        ['order' => $order, 'work_form' => $workForm] = $this->createOrder(
            'SP-PUBLIC-001',
            $this->tenant,
            $this->company,
            'Public Link Siparişi'
        );

        $this->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get(route('public.work-forms.track', ['token' => $workForm->public_tracking_token]))
            ->assertOk();
    }

    private function createOrder(string $documentNumber, TenantAccount $tenant, Company $company, string $productName): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $company->id,
            'status' => 'pending',
            'workflow_status' => 'active',
            'quote_date' => now()->toDateString(),
            'currency' => 'TL',
            'subtotal' => 12500,
            'vat_total' => 2500,
            'grand_total' => 15000,
            'product_total' => 12500,
            'print_total' => 2500,
            'notes' => 'Müşteriye gösterilebilir sipariş notu',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $productName,
            'product_code' => $documentNumber . '-CODE',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Portal sipariş ürünü',
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

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'print_unit_price' => 25,
            'print_total' => 2500,
            'note' => 'Müşteriye gösterilebilir baskı notu',
            'status' => 'draft',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source_quote_number' => 'TK-PORTAL-001',
            'work_form_number' => 'IF-' . $documentNumber,
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'track-' . str_replace('-', '', strtolower($documentNumber)) . '-token',
            'order_snapshot' => [
                'document_number' => $documentNumber,
            ],
            'customer_snapshot' => [
                'company_name' => $company->legal_name,
            ],
            'product_snapshot' => [
                'product_name' => $productName,
                'product_code' => $documentNumber . '-CODE',
                'quantity' => 100,
            ],
            'print_snapshot' => [[
                'sequence' => '1a',
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf',
                'print_quantity' => 100,
                'note' => 'Müşteriye gösterilebilir baskı notu',
            ]],
            'graphic_snapshot' => [
                'public_status_label' => 'Grafik hazır',
                'group_code' => 'HIDDEN-GRAPHIC-GROUP',
            ],
            'production_snapshot' => [
                'public_status_label' => 'Üretim devam ediyor',
                'setup_cost' => 999,
            ],
            'delivery_snapshot' => [
                'public_status_label' => 'Teslimat bekliyor',
                'tracking_number' => 'TRK-001',
                'finance_warning' => 'odeme_bekliyor',
                'file_path' => '/secret/delivery.pdf',
            ],
            'procurement_snapshot' => [
                'public_status_label' => 'Ürününüz hazırlanıyor',
                'supplier_cost' => 100,
            ],
            'notes' => 'customer safe work form note',
        ]);

        return [
            'order' => $order,
            'item' => $item,
            'print' => $print,
            'work_form' => $workForm,
        ];
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
