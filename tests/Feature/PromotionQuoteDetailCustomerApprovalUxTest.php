<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteDetailCustomerApprovalUxTest extends TestCase
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
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'quote-detail-guarded',
            'slug' => 'quote-detail-guarded',
        ])->save();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_detail_shows_decision_band_and_not_sent_state_with_safe_copy(): void
    {
        $quote = $this->createQuote('TK-2026-6101');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Teklif Durumu ve Sıradaki Karar');
        $response->assertSee('Müşteri Onayı');
        $response->assertSee('Gönderilmedi');
        $response->assertSee('Bu teklif henüz müşteriye gönderilmedi.');
        $response->assertSee('Teklifleri Listele');
        $response->assertSee('Onaylandı İşaretle');
        $response->assertSee('Ürün &amp; Baskı Kalemleri', false);
        $response->assertSee('Teklif Özeti');
        $response->assertSee('Gönderim Geçmişi ve İkincil Bilgiler');
        $response->assertSee('Ürün Toplamı');
        $response->assertSee('Baskı Toplamı');
        $response->assertSee('Ara Toplam');
        $response->assertSee('Genel Toplam');
        $response->assertDontSee('Public Onay Linkini Aç');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('snapshot_json', false);
        $response->assertDontSee('quote_send_snapshot_id', false);
        $response->assertDontSee('notification_logs', false);
    }

    public function test_detail_shows_viewed_and_approved_states_with_safe_public_helper_and_convert_cta(): void
    {
        $quote = $this->createQuote('TK-2026-6102', [
            'status' => 'approved',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
        ]);

        $this->enableCustomerQuoteApprovalModule();
        $service = app(QuoteApprovalService::class);
        $request = $service->sendToCustomer($quote, ['contact_email' => 'detail-approve@example.test'], $this->adminUser);
        $service->markViewed($request->fresh());
        $service->approve($request->fresh(), 'Uygundur.');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote->fresh()));

        $response->assertOk();
        $response->assertSee('Teklif onaylandı, siparişe çevrilebilir.');
        $response->assertSee('Onaylandı');
        $response->assertSee('Görüntülenme');
        $response->assertSee('Public Onay Linkini Aç');
        $response->assertSee(route('admin.promotion-quotes.customer-approval.open', $quote), false);
        $response->assertSee('data-testid="quote-convert-cta"', false);
        $response->assertDontSee($request->token, false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.customer-approval.open', $quote))
            ->assertRedirect(route('public.quotes.approval.show', ['token' => $request->fresh()->token]));

        $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.quotes.approval.show', ['token' => $request->fresh()->token]))
            ->assertOk();
    }

    public function test_detail_shows_revision_and_reject_states_and_hides_helper_when_feature_is_closed(): void
    {
        $revisionQuote = $this->createQuote('TK-2026-6103');
        $rejectedQuote = $this->createQuote('TK-2026-6104');
        $this->enableCustomerQuoteApprovalModule();
        $service = app(QuoteApprovalService::class);

        $revisionRequest = $service->sendToCustomer($revisionQuote, ['contact_email' => 'detail-revision@example.test'], $this->adminUser);
        $service->requestRevision($revisionRequest->fresh(), 'Logo rengini mavi yapalım.');

        $revisionResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $revisionQuote->fresh()));

        $revisionResponse->assertOk();
        $revisionResponse->assertSee('Revize İstendi');
        $revisionResponse->assertSee('Müşteri revize istedi.');
        $revisionResponse->assertSee('Logo rengini mavi yapalım.');
        $revisionResponse->assertSee('Tekrar Gönder');
        $revisionResponse->assertSee('Public Onay Linkini Aç');

        $rejectedRequest = $service->sendToCustomer($rejectedQuote, ['contact_email' => 'detail-reject@example.test'], $this->adminUser);
        $service->reject($rejectedRequest->fresh(), 'Bu fiyat uygun değil.');

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'quote_customer_approval')
            ->where('feature_key', 'public_quote_approval')
            ->update(['is_enabled' => false]);

        $rejectResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $rejectedQuote->fresh()));

        $rejectResponse->assertOk();
        $rejectResponse->assertSee('Reddedildi');
        $rejectResponse->assertSee('Teklif reddedildi.');
        $rejectResponse->assertSee('Bu fiyat uygun değil.');
        $rejectResponse->assertDontSee('Public Onay Linkini Aç');
        $rejectResponse->assertDontSee($rejectedRequest->token, false);
    }

    public function test_detail_hides_financial_data_for_user_without_financial_visibility(): void
    {
        $quote = $this->createQuote('TK-2026-6105');

        $user = User::factory()->create([
            'name' => 'Operasyon Kullanıcısı',
            'email' => 'operation.quote.detail@prodelya.local',
            'password' => 'password',
        ]);

        $graphicRole = Role::query()->where('key', 'graphic')->firstOrFail();

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $graphicRole->id,
        ]);

        $response = $this->actingAs($user)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Finansal bilgiler yetkiniz dışında gizlendi.');
        $response->assertDontSee('1.440,00 TL');
        $response->assertDontSee('1.200,00 TL');
        $response->assertDontSee('240,00 TL');
        $response->assertDontSee('purchase_total');
        $response->assertDontSee('supplier_cost');
        $response->assertDontSee('subcontractor_cost');
        $response->assertDontSee('setup_cost');
        $response->assertDontSee('profit');
        $response->assertDontSee('current_account_transactions');
    }

    private function enableCustomerQuoteApprovalModule(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => null,
            ],
            [
                'is_enabled' => true,
            ]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            [
                'is_enabled' => true,
            ]
        );
    }

    private function createQuote(string $documentNumber, array $overrides = []): Order
    {
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
            'quote_date' => '2026-06-15',
            'valid_until' => '2026-06-22',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
            'notes' => 'Müşteriye gösterilebilir teklif notu',
        ], $overrides));

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'UI Test Ürünü',
            'product_code' => 'UIT-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'UI teklif kalemi',
            'product_snapshot' => ['display_name' => 'UI Test Ürünü'],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                ],
                'group_code' => 'SECRET-GROUP',
                'file_path' => '/hidden/quote.pdf',
                'purchase_total' => 300,
                'supplier_cost' => 400,
                'subcontractor_cost' => 500,
                'setup_cost' => 600,
                'profit' => 700,
            ],
            'stock_snapshot' => ['visible_stock_quantity' => 500],
            'list_price' => 12,
            'discount_rate' => 0,
            'unit_price' => 12,
            'line_total' => 1200,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $quote->fresh();
    }
}
