<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\GraphicApprovalRequest;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\Role;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AdminDashboardWorkQueueDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPendingActionCounterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $foreignTenant;
    private User $owner;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();

        $this->tenant = TenantAccount::query()->create([
            'name' => 'Dashboard Pending Counter',
            'legal_name' => 'Dashboard Pending Counter Ltd. Şti.',
            'slug' => 'dashboard-pending-counter',
            'panel_subdomain' => 'dashboard-pending-counter',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Dashboard Pending Counter Foreign',
            'legal_name' => 'Dashboard Pending Counter Foreign Ltd. Şti.',
            'slug' => 'dashboard-pending-counter-foreign',
            'panel_subdomain' => 'dashboard-pending-counter-foreign',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->owner = User::query()->create([
            'name' => 'Dashboard Pending Owner',
            'email' => 'dashboard-pending-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        $this->customer = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Dashboard Pending Müşteri A.Ş.',
            'short_name' => 'Dashboard Pending Müşteri',
            'status' => 'active',
            'portal_enabled' => false,
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $this->customer->id,
            'role_key' => 'customer',
        ]);

        $this->enableDashboardModules($this->tenant->id);
        $this->enableDashboardModules($this->foreignTenant->id);
    }

    public function test_dashboard_pending_actions_ignore_quote_backed_orphan_like_operations_and_notification_previews(): void
    {
        $this->createWorkflowBundle($this->tenant, 'ORPHAN', 'quote');

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'dashboard_preview',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'audience_type' => 'customer',
            'recipient_type' => 'email',
            'recipient_name' => 'Preview User',
            'recipient_email' => 'preview@example.test',
            'subject' => 'Preview only',
            'message_preview' => 'Preview payload',
            'status' => NotificationLog::STATUS_PREVIEW,
        ]);

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'dashboard_failed',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'audience_type' => 'customer',
            'recipient_type' => 'email',
            'recipient_name' => 'Failed User',
            'recipient_email' => 'failed@example.test',
            'subject' => 'Failed only',
            'message_preview' => 'Failure payload',
            'status' => NotificationLog::STATUS_FAILED,
            'error_message' => 'SMTP timeout',
        ]);

        $dashboard = app(AdminDashboardWorkQueueDataBuilder::class)->build($this->tenant, $this->owner);
        $cards = collect($dashboard['cards'])->keyBy('title');

        $this->assertSame(0, $dashboard['queue_summary']['active_orders']);
        $this->assertSame(0, $dashboard['queue_summary']['pending_actions_total']);
        $this->assertSame(1, $cards['Başarısız Bildirimler']['count'] ?? null);

        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/dashboard'));

        $response->assertOk()
            ->assertSee('Aksiyon Bekleyen')
            ->assertSee('Gerçek sipariş/iş akışına bağlı bekleyen işlemler')
            ->assertSee('Bildirim Hatası');

        $response->assertViewHas('dashboard', function (array $viewDashboard): bool {
            return (int) data_get($viewDashboard, 'queue_summary.pending_actions_total') === 0
                && (int) data_get($viewDashboard, 'queue_summary.active_orders') === 0;
        });
    }

    public function test_dashboard_pending_actions_count_real_active_order_operations_and_ignore_foreign_tenant(): void
    {
        $this->createWorkflowBundle($this->tenant, 'REAL', 'order');
        $this->createWorkflowBundle($this->foreignTenant, 'FOREIGN', 'order');

        NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'dashboard_preview',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'audience_type' => 'customer',
            'recipient_type' => 'email',
            'recipient_name' => 'Preview User',
            'recipient_email' => 'preview@example.test',
            'subject' => 'Preview only',
            'message_preview' => 'Preview payload',
            'status' => NotificationLog::STATUS_PREVIEW,
        ]);

        $dashboard = app(AdminDashboardWorkQueueDataBuilder::class)->build($this->tenant, $this->owner);
        $cards = collect($dashboard['cards'])->keyBy('title');

        $this->assertSame(1, $dashboard['queue_summary']['active_orders']);
        $this->assertSame(5, $dashboard['queue_summary']['pending_actions_total']);
        $this->assertSame(1, $cards['Grafik Bekleyen İşler']['count'] ?? null);
        $this->assertSame(1, $cards['Müşteri Grafik Onayı Bekleyenler']['count'] ?? null);
        $this->assertSame(1, $cards['Tedarik Bekleyen İşler']['count'] ?? null);
        $this->assertSame(1, $cards['Teslimat Bekleyen İşler']['count'] ?? null);
        $this->assertSame(0, $cards['Başarısız Bildirimler']['count'] ?? null);
    }

    public function test_dashboard_pending_actions_exclude_cancelled_orders_and_completed_operations(): void
    {
        $cancelled = $this->createWorkflowBundle($this->tenant, 'CANCEL', 'order');
        $cancelled['order']->forceFill([
            'status' => 'cancelled',
            'workflow_status' => 'cancelled',
        ])->save();

        $completed = $this->createWorkflowBundle($this->tenant, 'DONE', 'order');
        $completed['graphic']->forceFill([
            'status' => OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
            'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED,
        ])->save();
        $completed['approval']->forceFill([
            'status' => GraphicApprovalRequest::STATUS_APPROVED,
        ])->save();
        $completed['procurement']->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'remaining_quantity' => 0,
        ])->save();
        $completed['production']->forceFill([
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'remaining_quantity' => 0,
        ])->save();
        $completed['delivery']->forceFill([
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
            'remaining_quantity' => 0,
        ])->save();

        $dashboard = app(AdminDashboardWorkQueueDataBuilder::class)->build($this->tenant, $this->owner);

        $this->assertSame(1, $dashboard['queue_summary']['active_orders']);
        $this->assertSame(0, $dashboard['queue_summary']['pending_actions_total']);
    }

    public function test_customer_readiness_is_ready_when_tenant_has_active_company_even_without_current_account(): void
    {
        $dashboard = app(AdminDashboardWorkQueueDataBuilder::class)->build($this->tenant, $this->owner);

        $readiness = collect($dashboard['readiness_checklist'])->keyBy('label');
        $quickStart = collect($dashboard['quick_start'])->keyBy('title');

        $this->assertSame('Hazır', $readiness['Cari Kartlar']['status'] ?? null);
        $this->assertSame('Hazır', $quickStart['Cari kart ekle']['status'] ?? null);
    }

    public function test_customer_readiness_is_missing_when_tenant_has_no_active_company_or_current_account(): void
    {
        Company::query()->where('tenant_account_id', $this->tenant->id)->delete();

        $dashboard = app(AdminDashboardWorkQueueDataBuilder::class)->build($this->tenant, $this->owner);

        $readiness = collect($dashboard['readiness_checklist'])->keyBy('label');
        $quickStart = collect($dashboard['quick_start'])->keyBy('title');

        $this->assertSame('Eksik', $readiness['Cari Kartlar']['status'] ?? null);
        $this->assertSame('Eksik', $quickStart['Cari kart ekle']['status'] ?? null);
    }

    public function test_quote_readiness_is_ready_when_customer_and_catalog_are_available(): void
    {
        $this->createQuoteReadyCatalogProduct();

        $dashboard = app(AdminDashboardWorkQueueDataBuilder::class)->build($this->tenant, $this->owner);

        $readiness = collect($dashboard['readiness_checklist'])->keyBy('label');
        $quickStart = collect($dashboard['quick_start'])->keyBy('title');

        $this->assertSame('Hazır', $readiness['Teklif Oluşturma']['status'] ?? null);
        $this->assertSame('Hazır', $quickStart['İlk teklifini oluştur']['status'] ?? null);
    }

    public function test_quote_readiness_requires_catalog_when_customer_exists_but_quote_ready_product_is_missing(): void
    {
        $dashboard = app(AdminDashboardWorkQueueDataBuilder::class)->build($this->tenant, $this->owner);

        $readiness = collect($dashboard['readiness_checklist'])->keyBy('label');
        $quickStart = collect($dashboard['quick_start'])->keyBy('title');

        $this->assertSame('Kontrol Gerekir', $readiness['Teklif Oluşturma']['status'] ?? null);
        $this->assertSame('Kontrol Gerekir', $quickStart['İlk teklifini oluştur']['status'] ?? null);
    }

    public function test_catalog_summary_counts_visible_rows_quote_ready_rows_and_attention_rows_correctly(): void
    {
        $this->createCatalogProduct([
            'product_code' => 'CAT-READY-001',
            'product_name' => 'Hazır Ürün',
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'is_active' => true,
            'standard_category_id' => 1,
        ]);

        $parentProduct = $this->createCatalogProduct([
            'product_code' => 'CAT-PARENT-001',
            'product_name' => 'Varyantlı Grup Ürün',
            'visible_in_catalog' => true,
            'visible_in_quote' => false,
            'is_active' => true,
            'standard_category_id' => 1,
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'price_snapshot' => ['list_price' => 130, 'vat_rate' => 20],
            ],
        ]);

        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $parentProduct->id,
            'variant_code' => 'CAT-PARENT-001-KRM',
            'variant_name' => 'Kırmızı',
            'variant_color' => 'Kırmızı',
            'display_price' => 130,
            'currency' => 'TL',
            'stock_quantity' => 8,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 8,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [],
            'meta' => [
                'quote_search_visible' => true,
                'parent_product_name' => 'Varyantlı Grup Ürün',
                'price_snapshot' => ['list_price' => 130, 'vat_rate' => 20],
            ],
        ]);

        $this->createCatalogProduct([
            'product_code' => 'CAT-WARN-001',
            'product_name' => 'Kategori Bekleyen Ürün',
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'is_active' => true,
            'standard_category_id' => null,
            'meta' => [
                'category_missing_warning' => true,
                'price_snapshot' => ['list_price' => 140, 'vat_rate' => 20],
                'warnings' => ['Kategori bekliyor'],
            ],
        ]);

        $this->createCatalogProduct([
            'product_code' => 'CAT-STALE-001',
            'product_name' => 'Eski Uyarı Meta Ürünü',
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'is_active' => true,
            'standard_category_id' => 1,
            'meta' => [
                'category_missing_warning' => true,
                'category_status' => 'unmapped',
                'fallback_category_code' => 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN',
                'supplier_warning_flag' => false,
                'net_price_warning' => false,
                'warning_snapshot' => [],
                'price_snapshot' => ['list_price' => 155, 'vat_rate' => 20],
            ],
        ]);

        $this->createCatalogProduct([
            'product_code' => 'CAT-CLOSED-001',
            'product_name' => 'Teklifte Kapalı Ürün',
            'visible_in_catalog' => true,
            'visible_in_quote' => false,
            'is_active' => true,
            'standard_category_id' => 1,
        ]);

        $this->createCatalogProduct([
            'product_code' => 'CAT-SUP-001',
            'product_name' => 'Tedarikçi Uyarılı Ürün',
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'is_active' => true,
            'standard_category_id' => 1,
            'meta' => [
                'supplier_warning_flag' => true,
                'price_snapshot' => ['list_price' => 175, 'vat_rate' => 20],
            ],
        ]);

        TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'tenant_sku' => 'FOREIGN-SKU-001',
            'name' => 'Foreign Ürün',
            'product_code' => 'FOREIGN-001',
            'product_name' => 'Foreign Ürün',
            'slug' => 'foreign-urun',
            'product_family' => 'promotion',
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'is_active' => true,
            'currency' => 'TL',
            'display_price' => 90,
            'sale_price' => 90,
            'stock_quantity' => 4,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        $dashboard = app(AdminDashboardWorkQueueDataBuilder::class)->build($this->tenant, $this->owner);
        $catalogSummary = $dashboard['catalog_summary'];

        $this->assertSame(6, $catalogSummary['total_products'] ?? null);
        $this->assertSame(5, $catalogSummary['quote_ready_products'] ?? null);
        $this->assertSame(2, $catalogSummary['needs_review_count'] ?? null);
        $this->assertSame('sellable_rows', $catalogSummary['count_basis'] ?? null);
    }

    private function createWorkflowBundle(TenantAccount $tenant, string $suffix, string $documentType): array
    {
        $customer = $tenant->id === $this->tenant->id
            ? $this->customer
            : $this->createForeignCustomer($tenant, $suffix);

        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => $documentType,
            'document_number' => ($documentType === 'order' ? 'SP' : 'TK') . '-' . $suffix . '-001',
            'customer_company_id' => $customer->id,
            'status' => $documentType === 'order' ? 'pending' : 'approved',
            'workflow_status' => $documentType === 'order' ? 'order_created' : 'quote',
            'customer_approval_status' => $documentType === 'order'
                ? Order::CUSTOMER_APPROVAL_NOT_SENT
                : Order::CUSTOMER_APPROVAL_APPROVED,
            'quote_date' => '2026-06-23',
            'valid_until' => '2026-06-30',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->owner->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Dashboard Pending Product ' . $suffix,
            'product_code' => 'DPC-' . $suffix,
            'quantity' => 100,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'list_price' => 100,
            'discount_rate' => 0,
            'unit_price' => 100,
            'line_total' => 10000,
            'has_print' => true,
            'print_total' => 1000,
            'status' => 'pending',
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
            'print_unit_price' => 10,
            'print_total' => 1000,
            'status' => 'draft',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_number' => 'IF-' . $suffix . '-001',
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'token-' . strtolower($suffix) . '-001',
            'customer_snapshot' => ['company_name' => $customer->legal_name],
            'product_snapshot' => ['product_name' => 'Dashboard Pending Product ' . $suffix],
        ]);

        $graphic = OrderItemPrintGraphic::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'order_item_work_form_id' => $workForm->id,
            'sequence_code' => 'G-' . $suffix,
            'status' => OrderItemPrintGraphic::STATUS_WAITING_VISUAL,
            'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING,
            'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id,
        ]);

        $attachment = OrderItemWorkFormAttachment::query()->create([
            'tenant_account_id' => $tenant->id,
            'work_form_id' => $workForm->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_graphic_id' => $graphic->id,
            'order_item_print_id' => $print->id,
            'attachment_type' => 'graphic_visual',
            'visibility' => 'customer_visible',
            'file_path' => 'work-forms/' . strtolower($suffix) . '.png',
            'file_name' => strtolower($suffix) . '.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'uploaded_by' => $this->owner->id,
        ]);

        $approval = GraphicApprovalRequest::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'order_item_print_graphic_id' => $graphic->id,
            'work_form_id' => $workForm->id,
            'attachment_id' => $attachment->id,
            'customer_company_id' => $customer->id,
            'token' => strtolower($suffix) . '-approval-token',
            'status' => GraphicApprovalRequest::STATUS_WAITING,
            'created_by' => $this->owner->id,
        ]);

        $procurement = OrderItemProcurement::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'requires_procurement' => true,
            'fulfillment_source' => OrderItemProcurement::FULFILLMENT_SUPPLIER,
            'procurement_status' => OrderItemProcurement::STATUS_PENDING,
            'requested_quantity' => 100,
            'remaining_quantity' => 100,
        ]);

        $production = OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'work_form_id' => $workForm->id,
            'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'planned_quantity' => 100,
            'remaining_quantity' => 100,
            'cliche_required' => false,
            'production_snapshot' => ['print_sequence' => '1A'],
        ]);

        $delivery = OrderItemWorkFormDelivery::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
            'planned_quantity' => 100,
            'remaining_quantity' => 100,
        ]);

        return compact('order', 'item', 'print', 'workForm', 'graphic', 'attachment', 'approval', 'procurement', 'production', 'delivery');
    }

    private function createForeignCustomer(TenantAccount $tenant, string $suffix): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Foreign Dashboard Customer ' . $suffix,
            'short_name' => 'Foreign Dashboard Customer ' . $suffix,
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        return $company;
    }

    private function createQuoteReadyCatalogProduct(): TenantCatalogProduct
    {
        return TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_sku' => 'DASH-READY-SKU',
            'name' => 'Dashboard Quote Ready Product',
            'product_name' => 'Dashboard Quote Ready Product',
            'product_code' => 'DASH-READY-PRD',
            'slug' => 'dashboard-quote-ready-product',
            'product_family' => 'promotion',
            'catalog_source' => 'tenant_catalog',
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'is_active' => true,
            'currency' => 'TL',
            'display_price' => 125.50,
            'sale_price' => 125.50,
            'stock_quantity' => 10,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);
    }

    private function createCatalogProduct(array $attributes = []): TenantCatalogProduct
    {
        return TenantCatalogProduct::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'tenant_sku' => 'SKU-' . uniqid(),
            'name' => $attributes['product_name'] ?? 'Dashboard Catalog Product',
            'product_code' => 'CAT-' . uniqid(),
            'product_name' => 'Dashboard Catalog Product',
            'slug' => 'dashboard-catalog-' . uniqid(),
            'product_family' => 'promotion',
            'currency' => 'TL',
            'display_price' => 100,
            'sale_price' => 100,
            'stock_quantity' => 10,
            'total_stock_quantity' => 10,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 10,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'is_active' => true,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
            'meta' => [
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
            ],
        ], $attributes));
    }

    private function enableDashboardModules(int $tenantId): void
    {
        foreach ([
            ['module_key' => 'order_flow', 'feature_key' => null],
            ['module_key' => 'graphics', 'feature_key' => null],
            ['module_key' => 'graphic_customer_approval', 'feature_key' => null],
            ['module_key' => 'graphic_customer_approval', 'feature_key' => 'public_graphic_approval'],
            ['module_key' => 'procurement', 'feature_key' => null],
            ['module_key' => 'production', 'feature_key' => null],
            ['module_key' => 'delivery', 'feature_key' => null],
            ['module_key' => 'notification_center', 'feature_key' => null],
            ['module_key' => 'notification_center', 'feature_key' => 'notification_logs'],
        ] as $module) {
            TenantModule::query()->updateOrCreate(
                [
                    'tenant_account_id' => $tenantId,
                    'module_key' => $module['module_key'],
                    'feature_key' => $module['feature_key'],
                ],
                ['is_enabled' => true]
            );
        }
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
