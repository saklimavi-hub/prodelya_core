<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\QuoteApprovalService;

trait BuildsPromotionQuoteDetailFixture
{
    private const DETAIL_HOST = 'prodelya_core.test';

    protected TenantAccount $tenant;
    protected User $adminUser;
    protected Company $customer;

    protected function setUpPromotionQuoteDetailFixture(): void
    {
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Ayşe Müşteri',
            ],
            [
                'email' => 'ayse@example.test',
                'phone' => '05320000000',
                'mobile' => '05320000000',
                'is_primary' => true,
            ]
        );

        $this->enableQuoteApprovalFeatures();
        $this->enableWhatsappFeatures();
    }

    protected function showQuote(Order $quote)
    {
        return $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::DETAIL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));
    }

    protected function createPromotionQuote(string $documentNumber = 'TK-DETAIL-001'): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-07-08',
            'valid_until' => '2026-07-15',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'subtotal' => 12330,
            'vat_total' => 2466,
            'grand_total' => 14796,
            'product_total' => 6730,
            'print_total' => 5600,
            'created_by' => $this->adminUser->id,
            'notes' => 'İç satış notu: müşteriyle paylaşılmaz.',
            'show_print_price_details_to_customer' => false,
        ]);

        $firstItem = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Kompakt Görünüm Test Ürünü',
            'product_code' => 'QD-001',
            'quantity' => 1000,
            'unit' => 'Adet',
            'description' => 'Kısa ürün açıklaması ve baskı notu kontrolü için örnek kalem.',
            'unit_price' => 4.73,
            'line_total' => 4730,
            'has_print' => true,
            'print_total' => 5300,
            'status' => 'pending',
            'product_snapshot' => [
                'group_code' => 'SECRET-GROUP',
                'raw' => ['hidden' => true],
            ],
            'price_snapshot' => [
                'supplier_cost' => 50,
                'profit' => 20,
                'file_path' => '/hidden/file.pdf',
            ],
        ]);

        $firstItem->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $firstItem->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 1000,
            'print_unit_price' => 5,
            'print_total' => 5000,
            'note' => 'Logo sağ üst köşede kullanılacak.',
            'status' => 'draft',
        ]);

        $firstItem->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $firstItem->id,
            'print_type' => 'Lazer',
            'print_option' => 'Gövde',
            'print_quantity' => 1000,
            'print_unit_price' => 0.30,
            'print_total' => 300,
            'note' => 'İkinci baskı satırı numaralandırma kontrolü.',
            'status' => 'draft',
        ]);

        $secondItem = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'İkinci Test Ürünü',
            'product_code' => 'QD-002',
            'quantity' => 250,
            'unit' => 'Adet',
            'description' => 'İkinci ürün satırı yerleşim ve tekrar kontrolü için eklenmiştir.',
            'unit_price' => 8,
            'line_total' => 2000,
            'has_print' => true,
            'print_total' => 300,
            'status' => 'pending',
        ]);

        $secondItem->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $secondItem->id,
            'print_type' => 'Serigrafi',
            'print_option' => 'Ön yüz',
            'print_quantity' => 250,
            'print_unit_price' => 1.20,
            'print_total' => 300,
            'note' => 'İkinci ürünün baskı satırı.',
            'status' => 'draft',
        ]);

        return $quote->fresh(['items.prints', 'customer']);
    }

    protected function createSentPromotionQuote(string $documentNumber = 'TK-DETAIL-SENT-001', array $recipientData = []): Order
    {
        $quote = $this->createPromotionQuote($documentNumber);

        app(QuoteApprovalService::class)->sendToCustomer($quote, array_merge([
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => 'ayse@example.test',
            'contact_phone' => '05320000000',
            'sent_channel' => 'manual',
        ], $recipientData), $this->adminUser);

        return $quote->fresh();
    }

    protected function enableQuoteApprovalFeatures(): void
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

    protected function enableWhatsappFeatures(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'whatsapp_links',
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
    }
}
