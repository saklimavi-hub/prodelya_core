<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteShowPolishTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_show_groups_primary_actions_and_hides_sensitive_fields(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'whatsapp_links',
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($tenant->id, 'notification_whatsapp_enabled', true, 'boolean');

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-SHOW-POLISH-01',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-07-03',
            'valid_until' => '2026-07-10',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1000,
            'print_total' => 200,
            'created_by' => $adminUser->id,
            'show_print_price_details_to_customer' => false,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Show Polish Ürünü',
            'product_code' => 'SHOW-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Show ekranı test ürün açıklaması',
            'unit_price' => 10,
            'line_total' => 1000,
            'has_print' => true,
            'print_total' => 200,
            'status' => 'pending',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'print_unit_price' => 2,
            'print_total' => 200,
            'status' => 'draft',
        ]);

        app(QuoteApprovalService::class)->sendToCustomer($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => 'ayse@example.test',
            'contact_phone' => '05320000000',
            'sent_channel' => 'manual',
        ], $adminUser);

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote->fresh()));

        $response->assertOk();
        $response->assertSee('Birincil satış aksiyonları');
        $response->assertSee('Müşteriye Gönder');
        $response->assertSee('PDF Teklif');
        $response->assertSee('WhatsApp Gönder');
        $response->assertSee('Siparişe Çevir ve Süreci Başlat');
        $response->assertSee('Son Gönderim Kayıtları');
        $response->assertSee('E-posta');
        $response->assertSee('Link Oluşturuldu');
        $response->assertSee('Baskı detayları müşteriye gizli');
        $response->assertDontSee('group_code');
        $response->assertDontSee('file_path');
        $response->assertDontSee('physical_path');
        $response->assertDontSee('setup_total_amount');
    }
}
