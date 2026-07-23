<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionQuoteWorkspaceInitialRowContractTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const TENANT_HOST = 'saklimavi.prodelya_core.test';

    private User $tenantUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrCreate(
            ['panel_subdomain' => 'saklimavi'],
            [
                'name' => 'SAKLImavi',
                'legal_name' => 'SAKLImavi',
                'slug' => 'saklimavi',
                'custom_domain' => null,
                'portal_domain' => null,
                'status' => 'active',
                'package_key' => 'starter',
                'default_locale' => 'tr',
                'default_currency' => 'TRY',
                'timezone' => 'Europe/Istanbul',
            ]
        );

        $this->tenantUser = User::query()->create([
            'name' => 'H4 Tenant Owner',
            'email' => 'h4-tenant-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->tenantUser->id,
            'role_id' => $tenantOwnerRole->id,
        ]);
    }

    public function test_create_workspace_source_contract_keeps_empty_boot_and_safe_sales_panel_reference(): void
    {
        $response = $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::TENANT_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('const quoteWorkspace =', false);
        $response->assertSee('"items":', false);
        $response->assertSee('const salesPanelHtml = renderSalesPresentationPanel(item, payload);', false);
        $response->assertSee('const initialItems = quoteWorkspace.items?.length ? quoteWorkspace.items : [defaultItem()];', false);
        $response->assertSee('mountItems(initialItems);', false);
        $response->assertSee('data-live-product-info-box', false);
    }

    public function test_edit_workspace_bootstraps_existing_items_without_needing_forced_extra_empty_row(): void
    {
        $customer = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'H4 Test Musteri ' . Str::upper(Str::random(4)),
            'short_name' => 'H4 Musteri',
            'email' => 'h4-customer@example.test',
            'phone' => '5550000000',
            'status' => 'active',
            'portal_enabled' => false,
        ]);

        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-H4-' . Str::upper(Str::random(5)),
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'TRY',
            'subtotal' => 250,
            'vat_total' => 0,
            'grand_total' => 250,
            'product_total' => 250,
            'print_total' => 0,
            'created_by' => $this->tenantUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Bootstrap Kalem',
            'product_code' => 'H4-BOOT-001',
            'quantity' => 25,
            'unit' => 'Adet',
            'list_price' => 10,
            'discount_rate' => 0,
            'unit_price' => 10,
            'line_total' => 250,
            'has_print' => false,
            'print_total' => 0,
            'manual_unit_price' => true,
            'status' => 'draft',
            'catalog_source' => 'tenant_catalog',
            'product_snapshot' => [
                'product_name' => 'Bootstrap Kalem',
                'product_code' => 'H4-BOOT-001',
            ],
            'price_snapshot' => [
                'list_price' => 10,
                'manual_unit_price' => true,
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => 0,
            ],
        ]);

        $response = $this->actingAs($this->tenantUser)
            ->withServerVariables(['HTTP_HOST' => self::TENANT_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();
        $response->assertSee('"items":[{', false);
        $response->assertSee('"product_name":"Bootstrap Kalem"', false);
        $response->assertSee('"quote_item_id":' . $item->id, false);
        $response->assertSee('const initialItems = quoteWorkspace.items?.length ? quoteWorkspace.items : [defaultItem()];', false);
        $response->assertSee('data-live-product-info-box', false);
    }
}
