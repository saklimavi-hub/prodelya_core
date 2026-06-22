<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\GraphicApprovalRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\GraphicApprovalRequestService;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerPortalAndPublicFlowSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private Company $company;
    private CompanyContact $contact;
    private CustomerPortalUser $portalUser;
    private User $adminUser;
    private string $tenantHost;

    protected function setUp(): void
    {
        parent::setUp();

        Auth::forgetGuards();

        Storage::fake('public');

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->tenant->forceFill([
            'panel_subdomain' => 'portal-public-regression',
            'slug' => 'portal-public-regression',
            'status' => 'active',
        ])->save();

        $this->company->forceFill(['portal_enabled' => true])->save();

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Security Regression Yetkilisi',
            'title' => 'Operasyon',
            'email' => 'portal-public-regression-contact@example.test',
            'phone' => '02123334455',
            'mobile' => '05323334455',
            'is_primary' => true,
        ]);

        $this->portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Portal Public Regression User',
            'email' => 'portal-public-regression-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->tenantHost = 'portal-public-regression.prodelya_core.test';

        $this->enablePortalModule();
        $this->enablePortalFeature('customer_login');
        $this->enablePortalFeature('portal_quotes');
        $this->enablePortalFeature('portal_orders');
        $this->enableVisibleFilesFeature();
        $this->enableQuoteApproval();
        $this->enableGraphicApproval();
    }

    public function test_portal_auth_boundaries_and_public_routes_remain_independent(): void
    {
        $quote = $this->createQuote('TK-REG-001', $this->tenant, $this->company, 'Portal Quote Ürünü');
        $trackingContext = $this->createTrackingContext('REGTRACK001');
        $workForm = $trackingContext['workForm'];
        $visibleAttachment = $trackingContext['customerVisibleAttachment'];
        $quoteRequest = app(QuoteApprovalService::class)->sendToCustomer($quote, [], $this->adminUser);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($this->adminUser, 'web');

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->post($this->tenantUrl('/musteri-cikis'))
            ->assertRedirect(route('customer.login'));

        $this->assertFalse(auth('customer_portal')->check());

        $graphicContext = $this->createGraphicApprovalContext('PG-REG-001');

        $trackingResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', ['token' => $workForm->public_tracking_token]));
        $this->assertSame(200, $trackingResponse->getStatusCode(), 'public tracking should stay open');

        $visibleAttachment = $visibleAttachment->fresh();
        $this->assertNotNull($visibleAttachment);
        $this->assertTrue(Storage::disk('public')->exists((string) $visibleAttachment->file_path));

        $trackingAttachmentResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.attachments.show', [
                'token' => $workForm->public_tracking_token,
                'attachment' => $visibleAttachment->id,
            ]));
        $this->assertSame(200, $trackingAttachmentResponse->getStatusCode(), 'public tracking attachment should stay open');

        $quoteShowResponse = $this->get(route('public.quotes.approval.show', ['token' => $quoteRequest->token]));
        $this->assertSame(200, $quoteShowResponse->getStatusCode(), 'public quote approval should stay open');

        $graphicShowResponse = $this->get(route('public.graphics.approval.show', ['token' => $graphicContext['request']->token]));
        $this->assertSame(200, $graphicShowResponse->getStatusCode(), 'public graphic approval should stay open');
    }

    public function test_portal_and_public_surfaces_enforce_tenant_company_isolation_and_visibility(): void
    {
        $ownQuote = $this->createQuote('TK-REG-002', $this->tenant, $this->company, 'Scope Quote Ürünü');
        ['order' => $ownOrder, 'work_form' => $ownWorkForm] = $this->createOrder('SP-REG-002', $this->tenant, $this->company, 'Scope Order Ürünü');
        $ownVisibleAttachment = $this->createAttachment(
            $ownWorkForm,
            'customer_visible',
            'delivery_document',
            'scope-visible.pdf',
            'scope-visible-content',
            'application/pdf'
        );
        $ownInternalAttachment = $this->createAttachment(
            $ownWorkForm,
            'internal',
            'delivery_document',
            'scope-internal.pdf',
            'scope-internal-content',
            'application/pdf'
        );

        $otherCompany = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Ayni Tenant Baska Musteri',
            'short_name' => 'Ayni Tenant Baska Musteri',
            'email' => 'scope-other-company@example.test',
            'phone' => '02124445566',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        $otherCompanyQuote = $this->createQuote('TK-REG-OTHER-COMPANY', $this->tenant, $otherCompany, 'Gizli Quote');
        ['order' => $otherCompanyOrder, 'work_form' => $otherCompanyWorkForm] = $this->createOrder(
            'SP-REG-OTHER-COMPANY',
            $this->tenant,
            $otherCompany,
            'Gizli Order'
        );
        $otherCompanyAttachment = $this->createAttachment(
            $otherCompanyWorkForm,
            'customer_visible',
            'delivery_document',
            'scope-other-company.pdf',
            'scope-other-company-content',
            'application/pdf'
        );

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Portal Public Regression Other Tenant',
            'legal_name' => 'Portal Public Regression Other Tenant Ltd.',
            'slug' => 'portal-public-regression-other',
            'panel_subdomain' => 'portal-public-regression-other',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);
        $otherTenantCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Baska Tenant Musteri',
            'short_name' => 'Baska Tenant Musteri',
            'email' => 'scope-other-tenant-company@example.test',
            'phone' => '02127778899',
            'status' => 'active',
            'portal_enabled' => true,
        ]);
        $otherTenantQuote = $this->createQuote('TK-REG-OTHER-TENANT', $otherTenant, $otherTenantCompany, 'Baska Tenant Quote');
        ['order' => $otherTenantOrder, 'work_form' => $otherTenantWorkForm] = $this->createOrder(
            'SP-REG-OTHER-TENANT',
            $otherTenant,
            $otherTenantCompany,
            'Baska Tenant Order'
        );
        $otherTenantAttachment = $this->createAttachment(
            $otherTenantWorkForm,
            'customer_visible',
            'delivery_document',
            'scope-other-tenant.pdf',
            'scope-other-tenant-content',
            'application/pdf'
        );

        $dashboard = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'));
        $dashboard->assertOk()
            ->assertSee($this->company->legal_name)
            ->assertDontSee('TK-REG-OTHER-COMPANY')
            ->assertDontSee('TK-REG-OTHER-TENANT')
            ->assertDontSee('SP-REG-OTHER-COMPANY')
            ->assertDontSee('SP-REG-OTHER-TENANT');

        $quoteList = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler'));
        $quoteList->assertOk()
            ->assertSee('TK-REG-002')
            ->assertDontSee('TK-REG-OTHER-COMPANY')
            ->assertDontSee('TK-REG-OTHER-TENANT');

        $orderList = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler'));
        $orderList->assertOk()
            ->assertSee('SP-REG-002')
            ->assertDontSee('SP-REG-OTHER-COMPANY')
            ->assertDontSee('SP-REG-OTHER-TENANT');

        $fileList = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar'));
        $fileList->assertOk()
            ->assertSee('scope-visible.pdf')
            ->assertDontSee('scope-internal.pdf')
            ->assertDontSee('scope-other-company.pdf')
            ->assertDontSee('scope-other-tenant.pdf');

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $ownQuote->id))
            ->assertOk();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $otherCompanyQuote->id))
            ->assertNotFound();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $otherTenantQuote->id))
            ->assertNotFound();

        $orderDetail = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $ownOrder->id));
        $orderDetail->assertOk()
            ->assertSee('/musteri-portal/siparisler/' . $ownOrder->id . '/takip/' . $ownWorkForm->id, false)
            ->assertDontSee($ownWorkForm->public_tracking_token)
            ->assertDontSee('scope-internal.pdf');

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

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $otherTenantOrder->id . '/takip/' . $otherTenantWorkForm->id))
            ->assertNotFound();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $ownInternalAttachment->id))
            ->assertNotFound();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $otherCompanyAttachment->id))
            ->assertNotFound();

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar/' . $otherTenantAttachment->id))
            ->assertNotFound();
    }

    public function test_feature_guards_helper_links_and_forbidden_fields_remain_safe(): void
    {
        $quote = $this->createQuote('TK-REG-003', $this->tenant, $this->company, 'Feature Quote Ürünü');
        ['order' => $order, 'work_form' => $workForm] = $this->createOrder('SP-REG-003', $this->tenant, $this->company, 'Feature Order Ürünü');
        $this->createAttachment($workForm, 'customer_visible', 'delivery_document', 'feature-visible.pdf', 'feature-visible-content', 'application/pdf');
        $quoteRequest = app(QuoteApprovalService::class)->sendToCustomer($quote, [], $this->adminUser);

        $quoteDetail = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $quote->id));
        $quoteDetail->assertOk()
            ->assertSee('Teklifi İncele')
            ->assertSee(route('customer.portal.quotes.approval.open', $quote), false)
            ->assertDontSee($quoteRequest->token)
            ->assertSee('15.000,00 TL')
            ->assertDontSee('purchase_total')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('subcontractor_cost')
            ->assertDontSee('setup_cost')
            ->assertDontSee('profit')
            ->assertDontSee('balance_due')
            ->assertDontSee('payment_amount')
            ->assertDontSee('finance_warning')
            ->assertDontSee('pdh_raw')
            ->assertDontSee('group_code')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('internal_note')
            ->assertDontSee('notification_logs')
            ->assertDontSee('current_account_transactions');

        $orderDetail = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $order->id));
        $orderDetail->assertOk()
            ->assertSee('/musteri-portal/siparisler/' . $order->id . '/takip/' . $workForm->id, false)
            ->assertDontSee($workForm->public_tracking_token)
            ->assertDontSee('125,00 TL')
            ->assertDontSee('12.500,00 TL')
            ->assertDontSee('2.500,00 TL')
            ->assertDontSee('purchase_total')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('subcontractor_cost')
            ->assertDontSee('setup_cost')
            ->assertDontSee('profit')
            ->assertDontSee('balance_due')
            ->assertDontSee('payment_amount')
            ->assertDontSee('finance_warning')
            ->assertDontSee('pdh_raw')
            ->assertDontSee('group_code')
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('internal_note')
            ->assertDontSee('notification_logs')
            ->assertDontSee('current_account_transactions');

        $fileIndex = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar'));
        $fileIndex->assertOk()
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('storage/app')
            ->assertDontSee('purchase_total')
            ->assertDontSee('payment_amount')
            ->assertDontSee('group_code')
            ->assertDontSee('notification_logs');

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'quote_customer_approval')
            ->where('feature_key', 'public_quote_approval')
            ->update(['is_enabled' => false]);

        $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $quote->id))
            ->assertOk()
            ->assertDontSee('Teklifi İncele');

        $this->enableQuoteApproval();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'graphics')
            ->where('feature_key', 'customer_visible_files')
            ->update(['is_enabled' => false]);

        $closedFiles = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.files.index'));
        $this->assertContains($closedFiles->getStatusCode(), [403, 404]);

        $orderDetailWithoutFiles = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $order->id));
        $orderDetailWithoutFiles->assertOk()
            ->assertDontSee('Müşteri Dosyaları');

        $this->enableVisibleFilesFeature();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->where('feature_key', 'portal_quotes')
            ->update(['is_enabled' => false]);

        $quoteClosed = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.quotes.index'));
        $this->assertContains($quoteClosed->getStatusCode(), [403, 404]);

        $this->enablePortalFeature('portal_quotes');

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->where('feature_key', 'portal_orders')
            ->update(['is_enabled' => false]);

        $orderClosed = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get(route('customer.portal.orders.index'));
        $this->assertContains($orderClosed->getStatusCode(), [403, 404]);

        $this->enablePortalFeature('portal_orders');

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->whereNull('feature_key')
            ->update(['is_enabled' => false]);

        $portalClosed = $this->actingAs($this->portalUser->fresh(), 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'));
        $this->assertContains($portalClosed->getStatusCode(), [302, 403, 404]);

        $this->enablePortalModule();

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_portal')
            ->where('feature_key', 'customer_login')
            ->update(['is_enabled' => false]);

        $loginClosed = $this->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-giris'));
        $this->assertContains($loginClosed->getStatusCode(), [403, 404]);
    }

    public function test_public_token_boundaries_and_processed_actions_stay_locked(): void
    {
        $trackingContext = $this->createTrackingContext('SECREG-TRACK');
        $quote = $this->createQuote('TK-REG-004', $this->tenant, $this->company, 'Processed Quote Ürünü');
        $quoteRequest = app(QuoteApprovalService::class)->sendToCustomer($quote, [], $this->adminUser);
        $graphicContext = $this->createGraphicApprovalContext('PG-REG-004');

        $this->get(route('public.graphics.approval.show', ['token' => $quoteRequest->token]))->assertNotFound();
        $this->get(route('public.quotes.approval.show', ['token' => $graphicContext['request']->token]))->assertNotFound();
        $this->get(route('public.quotes.approval.show', ['token' => $trackingContext['workForm']->public_tracking_token]))->assertNotFound();
        $this->get(route('public.graphics.approval.show', ['token' => $trackingContext['workForm']->public_tracking_token]))->assertNotFound();

        app(QuoteApprovalService::class)->approve($quoteRequest->fresh(), 'Tamam');
        $this->followingRedirects()
            ->post(route('public.quotes.approval.approve', ['token' => $quoteRequest->token]), [
                'customer_note' => 'Tekrar',
            ])
            ->assertOk()
            ->assertSee('Bu teklif daha önce onaylanmış.');

        app(GraphicApprovalRequestService::class)->approve($graphicContext['request']->fresh(), []);
        $this->followingRedirects()
            ->post(route('public.graphics.approval.approve', ['token' => $graphicContext['request']->token]), [])
            ->assertOk()
            ->assertSee('Bu grafik daha önce onaylanmış.');
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
            'description' => 'Portal detail ürünü',
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
                'pdh_raw' => ['secret' => true],
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
                'pdh_raw' => ['secret' => true],
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
            'source_quote_number' => 'TK-' . $documentNumber,
            'work_form_number' => 'IF-' . $documentNumber,
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'track-' . str_replace('-', '', strtolower($documentNumber)) . '-token',
            'order_snapshot' => [
                'document_number' => $documentNumber,
                'current_account_transactions' => [['id' => 1]],
            ],
            'customer_snapshot' => [
                'company_name' => $company->legal_name,
            ],
            'product_snapshot' => [
                'product_name' => $productName,
                'product_code' => $documentNumber . '-CODE',
                'quantity' => 100,
                'group_code' => 'WF-HIDDEN-GROUP',
            ],
            'print_snapshot' => [[
                'sequence' => '1a',
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf',
                'print_quantity' => 100,
                'note' => 'Müşteriye gösterilebilir baskı notu',
                'file_path' => '/hidden/proof.pdf',
            ]],
            'graphic_snapshot' => [
                'public_status_label' => 'Grafik hazır',
                'group_code' => 'HIDDEN-GRAPHIC-GROUP',
            ],
            'production_snapshot' => [
                'public_status_label' => 'Üretim devam ediyor',
                'setup_cost' => 999,
                'subcontractor_cost' => 250,
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
            'notes' => 'internal_note hidden',
        ]);

        return [
            'order' => $order,
            'item' => $item,
            'print' => $print,
            'work_form' => $workForm,
        ];
    }

    private function createAttachment(
        OrderItemWorkForm $workForm,
        string $visibility,
        string $attachmentType,
        string $fileName,
        string $content,
        string $mimeType
    ): OrderItemWorkFormAttachment {
        $path = 'work-forms/' . $workForm->tenant_account_id . '/' . $workForm->order_id . '/' . $workForm->id . '/' . $fileName;
        Storage::disk('public')->put($path, $content);

        return OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'attachment_type' => $attachmentType,
            'visibility' => $visibility,
            'file_path' => $path,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'disk' => 'public',
            'note' => 'internal_note should stay hidden',
        ]);
    }

    private function createTrackingContext(string $productCode): array
    {
        ['work_form' => $workForm] = $this->createOrder('SP-' . $productCode, $this->tenant, $this->company, 'Tracking Smoke Ürünü');

        $customerVisibleAttachment = $this->createAttachment(
            $workForm,
            'customer_visible',
            'delivery_document',
            strtolower($productCode) . '-visible.pdf',
            'visible-pdf-content',
            'application/pdf'
        );

        $internalAttachment = $this->createAttachment(
            $workForm->fresh(),
            'internal',
            'delivery_document',
            strtolower($productCode) . '-internal.pdf',
            'internal-pdf-content',
            'application/pdf'
        );

        return [
            'workForm' => $workForm->fresh(),
            'customerVisibleAttachment' => $customerVisibleAttachment->fresh(),
            'internalAttachment' => $internalAttachment->fresh(),
        ];
    }

    private function createGraphicApprovalContext(string $productCode): array
    {
        $workForm = $this->createConvertedWorkForm($productCode, true);
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->with(['orderItem', 'orderItemPrint', 'workForm'])
            ->orderBy('sequence_code')
            ->firstOrFail();

        Storage::disk('public')->put('work-forms/' . $workForm->id . '/request-' . strtolower($productCode) . '.png', 'graphic-request-content');
        Storage::disk('public')->put('work-forms/' . $workForm->id . '/latest-' . strtolower($productCode) . '.png', 'graphic-latest-content');

        $requestAttachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/request-' . strtolower($productCode) . '.png',
            'file_name' => 'request-' . $productCode . '.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Müşteri onay görseli',
            'sort_order' => 1,
        ]);

        $latestAttachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . $workForm->id . '/latest-' . strtolower($productCode) . '.png',
            'file_name' => 'latest-' . $productCode . '.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->adminUser->id,
            'note' => 'Sonraki görsel',
            'sort_order' => 2,
        ]);

        $graphic->forceFill([
            'latest_attachment_id' => $requestAttachment->id,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $request = app(GraphicApprovalRequestService::class)->createRequest(
            $graphic->fresh(),
            $requestAttachment,
            [],
            $this->adminUser
        );

        $graphic->forceFill([
            'latest_attachment_id' => $latestAttachment->id,
        ])->save();

        return [
            'workForm' => $workForm->fresh(),
            'graphic' => $graphic->fresh(['orderItem']),
            'request' => $request->fresh(),
            'requestAttachment' => $requestAttachment->fresh(),
            'latestAttachment' => $latestAttachment->fresh(),
        ];
    }

    private function createConvertedWorkForm(string $productCode, bool $multiplePrints = false): OrderItemWorkForm
    {
        $prints = [[
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => '100',
            'print_unit_price' => '10',
        ]];

        if ($multiplePrints) {
            $prints[] = [
                'print_type' => 'Serigrafi',
                'print_option' => 'Gövde',
                'production_type' => 'İç üretim',
                'print_quantity' => '100',
                'print_unit_price' => '12',
            ];
        }

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->company->id,
                'quote_date' => '2026-06-18',
                'valid_until' => '2026-06-25',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Portal public regression graphic context',
                'items' => [[
                    'product_name' => 'Portal Public Regression Ürünü ' . $productCode,
                    'product_code' => $productCode,
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '100',
                    'discount_rate' => '0',
                    'unit_price' => '100',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => $prints,
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        return OrderItemWorkForm::query()
            ->whereHas('order', fn ($query) => $query->where('source_quote_id', $quote->id))
            ->latest('id')
            ->firstOrFail();
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

    private function enableVisibleFilesFeature(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphics',
                'feature_key' => 'customer_visible_files',
            ],
            ['is_enabled' => true]
        );
    }

    private function enableQuoteApproval(): void
    {
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
    }

    private function enableGraphicApproval(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'graphic_customer_approval',
                'feature_key' => 'public_graphic_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenantHost . $path;
    }
}
