<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageLimit;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageLimitCreateEnforcementTest extends TestCase
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
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_create_enforcement_blocks_only_safe_create_actions_and_reacts_to_limit_overrides(): void
    {
        TenantSetting::setValue($this->tenant->id, 'limit_current_accounts', 0, 'integer');

        $currentAccountBlocked = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.current-accounts.create'))
            ->post(route('admin.current-accounts.store'), [
                'display_name' => 'Limitli Cari',
                'status' => CurrentAccount::STATUS_ACTIVE,
                'roles' => [CurrentAccountRole::ROLE_CUSTOMER],
            ]);

        $currentAccountBlocked->assertRedirect(route('admin.current-accounts.create'));
        $currentAccountBlocked->assertSessionHasErrors('usage_limit');
        $this->assertSame(0, CurrentAccount::query()->where('tenant_account_id', $this->tenant->id)->count());

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index'))
            ->assertOk();

        TenantSetting::setValue($this->tenant->id, 'limit_current_accounts', 2, 'integer');

        $currentAccountAllowed = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-accounts.store'), [
                'display_name' => 'Izinli Cari',
                'status' => CurrentAccount::STATUS_ACTIVE,
                'roles' => [CurrentAccountRole::ROLE_CUSTOMER],
            ]);

        $currentAccount = CurrentAccount::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('display_name', 'Izinli Cari')
            ->firstOrFail();
        $linkedCompanyId = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('current_account_id', $currentAccount->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->value('link_id');
        $linkedCompany = Company::query()->findOrFail($linkedCompanyId);

        $currentAccountAllowed->assertRedirect(route('admin.companies.show', $linkedCompany));

        TenantSetting::setValue($this->tenant->id, 'limit_orders', 0, 'integer');

        $quoteBlocked = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.promotion-quotes.create'))
            ->post(route('admin.promotion-quotes.store'), $this->baseQuotePayload());

        $quoteBlocked->assertRedirect(route('admin.promotion-quotes.create'));
        $quoteBlocked->assertSessionHasErrors('usage_limit');
        $this->assertSame(0, Order::query()->where('tenant_account_id', $this->tenant->id)->where('document_type', 'quote')->count());

        TenantSetting::setValue($this->tenant->id, 'limit_orders', 3, 'integer');

        $quoteAllowed = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->baseQuotePayload([
                'notes' => 'Guard allowed quote',
            ]));

        $quote = Order::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('document_type', 'quote')
            ->latest('id')
            ->firstOrFail();

        $quoteAllowed->assertRedirect(route('admin.promotion-quotes.show', $quote));

        TenantSetting::setValue($this->tenant->id, 'limit_orders', 1, 'integer');

        $convertBlocked = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.promotion-quotes.show', $quote))
            ->post(route('admin.orders.convert.from.quote', $quote));

        $convertBlocked->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $convertBlocked->assertSessionHasErrors('usage_limit');
        $this->assertSame(0, Order::query()->where('tenant_account_id', $this->tenant->id)->where('document_type', 'order')->count());

        TenantSetting::setValue($this->tenant->id, 'limit_orders', 4, 'integer');

        $convertAllowed = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote));

        $order = Order::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('document_type', 'order')
            ->latest('id')
            ->firstOrFail();

        $convertAllowed->assertRedirect(route('admin.orders.show', $order));

        TenantSetting::setValue($this->tenant->id, 'limit_orders', 1, 'integer');
        TenantSetting::setValue($this->tenant->id, 'limit_current_accounts', 1, 'integer');

        $settings = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings'));

        $settings->assertOk();
        $settings->assertSee('Limit aşıldı');
        $settings->assertSee('Portal ve Uyarılar');

        $package = Package::query()->firstWhere('key', 'starter');
        if (!$package) {
            $package = Package::query()->create([
                'key' => 'starter',
                'name' => 'Starter',
                'status' => 'active',
                'currency' => 'TRY',
            ]);
        }

        PackageLimit::query()->updateOrCreate(
            ['package_id' => $package->id, 'limit_key' => 'users'],
            ['limit_value' => 3, 'is_unlimited' => false]
        );

        $this->tenant->forceFill(['package_key' => 'starter'])->save();
        TenantSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('key', 'limit_users')
            ->delete();

        $this->assertSame(3, app(\App\Services\TenantUsageService::class)->getUsageForKey($this->tenant->fresh(), 'users')['limit']);

        $superAdminRaiseLimit = $this->actingAs($this->adminUser)
            ->put('http://' . self::CENTRAL_HOST . '/admin/super-admin/tenants/' . $this->tenant->id . '/limits', [
                'limits' => [
                    'users' => ['mode' => 'value', 'value' => '5'],
                ],
            ]);

        $superAdminRaiseLimit->assertRedirect();
        $this->assertSame(5, app(\App\Services\TenantUsageService::class)->getUsageForKey($this->tenant->fresh(), 'users')['limit']);

        $publicWorkForm = $this->createPublicTrackingWorkForm();
        $this->get(route('public.work-forms.track', $publicWorkForm->public_tracking_token))
            ->assertOk();
    }

    private function baseQuotePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_company_id' => $this->customer->id,
            'quote_date' => '2026-06-17',
            'valid_until' => '2026-06-24',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'delivery_type' => 'Kargo',
            'notes' => 'Usage limit create enforcement test',
            'items' => [[
                'product_name' => 'Limit Test Urunu',
                'product_code' => 'LIMIT-001',
                'quantity' => '10',
                'unit' => 'Adet',
                'list_price' => '5',
                'discount_rate' => '0',
                'unit_price' => '5',
                'manual_unit_price' => '1',
                'vat_rate' => '20',
                'has_print' => '0',
                'prints' => [],
            ]],
        ], $overrides);
    }

    private function createPublicTrackingWorkForm()
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'ULG-PUBLIC-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $order->items()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Public Tracking Test Product',
            'product_code' => 'ULG-PUBLIC-ITEM',
            'quantity' => 5,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => false,
            'status' => 'pending',
        ]);

        return app(WorkFormCreationService::class)
            ->createForOrder($order, $this->adminUser)
            ->first()
            ->fresh();
    }
}
