<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerPortalUxPolishTest extends TestCase
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

        Storage::fake('public');

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->tenant->forceFill([
            'panel_subdomain' => 'portal-ux-polish-demo',
            'slug' => 'portal-ux-polish-demo',
            'status' => 'active',
        ])->save();

        $this->company->forceFill(['portal_enabled' => true])->save();

        $this->contact = CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Portal UX Yetkilisi',
            'title' => 'Operasyon',
            'email' => 'portal-ux-contact@example.test',
            'phone' => '02123334455',
            'mobile' => '05323334455',
            'is_primary' => true,
        ]);

        $this->portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'company_contact_id' => $this->contact->id,
            'name' => 'Portal UX User',
            'email' => 'portal-ux-user@example.test',
            'password' => 'secret-password',
            'status' => CustomerPortalUser::STATUS_ACTIVE,
        ]);

        $this->tenantHost = 'portal-ux-polish-demo.prodelya_core.test';

        $this->enablePortalModule();
        $this->enablePortalFeature('customer_login');
        $this->enablePortalFeature('portal_quotes');
        $this->enablePortalFeature('portal_orders');
        $this->enableVisibleFilesFeature();
        $this->enableQuoteApproval();
    }

    public function test_customer_portal_uses_customer_facing_copy_without_leaking_sensitive_terms(): void
    {
        $quote = $this->createQuote('TK-UX-001', 'Portal UX Teklif Ürünü');
        ['order' => $order, 'work_form' => $workForm] = $this->createOrder('SP-UX-001', 'Portal UX Sipariş Ürünü');
        $attachment = $this->createAttachment($workForm, 'customer_visible', 'delivery_document', 'portal-ux-visible.pdf', 'portal-ux-content', 'application/pdf');

        $approvalRequest = app(QuoteApprovalService::class)->sendToCustomer($quote, [], $this->adminUser);

        $dashboard = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal'));

        $dashboard->assertOk()
            ->assertSee('Tekliflerim')
            ->assertSee('Siparişlerim')
            ->assertSee('Dosyalarım')
            ->assertSee('Müşteri Takip Ekranı')
            ->assertDontSee('sonraki fazda')
            ->assertDontSee('Public Tracking');

        $quoteDetail = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/teklifler/' . $quote->id));

        $quoteDetail->assertOk()
            ->assertSee('Teklif Özeti')
            ->assertSee('Bu teklif için onay bağlantısı hazır.')
            ->assertSee('Teklifinizi inceleyip onaylayabilir veya revize isteyebilirsiniz.')
            ->assertSee('Teklifi İncele')
            ->assertDontSee($approvalRequest->token)
            ->assertDontSee('file_path')
            ->assertDontSee('group_code');

        $orderDetail = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/siparisler/' . $order->id));

        $orderDetail->assertOk()
            ->assertSee('İş Formu ve Müşteri Takip Ekranı')
            ->assertSee('Sipariş takibi operasyon durumlarını gösterir. Teklif tutarları teklif detayında yer alır.')
            ->assertSee('Müşteri Takip Ekranı')
            ->assertDontSee('Public Tracking')
            ->assertDontSee('balance_due')
            ->assertDontSee('supplier_cost');

        $files = $this->actingAs($this->portalUser, 'customer_portal')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost])
            ->get($this->tenantUrl('/musteri-portal/dosyalar'));

        $files->assertOk()
            ->assertSee('Dosyalarım')
            ->assertSee('Sipariş ve grafik süreçlerinde sizinle paylaşılan dosyaları burada görüntüleyebilirsiniz.')
            ->assertSee($attachment->file_name)
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path');

        $this->withServerVariables(['HTTP_HOST' => 'prodelya_core.test'])
            ->get(route('public.work-forms.track', ['token' => $workForm->public_tracking_token]))
            ->assertOk();
    }

    private function createQuote(string $documentNumber, string $productName): Order
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
            'subtotal' => 12000,
            'vat_total' => 2400,
            'grand_total' => 14400,
            'notes' => 'Müşteri notu',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $productName,
            'product_code' => $documentNumber . '-CODE',
            'quantity' => 20,
            'unit' => 'Adet',
            'unit_price' => 600,
            'line_total' => 12000,
            'price_snapshot' => ['group_code' => 'SECRET'],
            'status' => 'active',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 20,
            'print_unit_price' => 50,
            'print_total' => 1000,
            'note' => 'Baskı notu',
            'status' => 'draft',
        ]);

        return $quote;
    }

    private function createOrder(string $documentNumber, string $productName): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->company->id,
            'status' => 'pending',
            'workflow_status' => 'active',
            'currency' => 'TL',
            'notes' => 'Sipariş notu',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $productName,
            'product_code' => $documentNumber . '-CODE',
            'quantity' => 25,
            'unit' => 'Adet',
            'status' => 'active',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 25,
            'note' => 'Baskı notu',
            'status' => 'draft',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'source_quote_number' => 'TK-UX-001',
            'work_form_number' => 'IF-' . $documentNumber,
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'track-' . strtolower(str_replace('-', '', $documentNumber)),
            'order_snapshot' => ['document_number' => $documentNumber],
            'customer_snapshot' => ['company_name' => $this->company->legal_name],
            'product_snapshot' => ['product_name' => $productName, 'product_code' => $documentNumber . '-CODE'],
            'print_snapshot' => [[
                'sequence' => '1a',
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf',
                'print_quantity' => 25,
            ]],
            'graphic_snapshot' => ['public_status_label' => 'Grafik onayı bekleniyor'],
            'production_snapshot' => ['public_status_label' => 'Üretim aşamasında'],
            'delivery_snapshot' => ['public_status_label' => 'Teslimat bekliyor', 'tracking_number' => 'TRK-UX-001'],
            'procurement_snapshot' => ['public_status_label' => 'Siparişiniz hazırlanıyor'],
        ]);

        return ['order' => $order, 'work_form' => $workForm];
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
        ]);
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

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenantHost . $path;
    }
}
