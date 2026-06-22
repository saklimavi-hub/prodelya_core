<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\ModuleFeatureCatalogService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleFeatureCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_catalog_config_and_service_expose_canonical_modules_features_aliases_and_safe_defaults(): void
    {
        $service = app(ModuleFeatureCatalogService::class);

        $this->assertNotEmpty(config('prodelya_modules.modules'));
        $this->assertNotEmpty(config('prodelya_modules.features'));

        $core = $service->getModule('core');
        $orderFlow = $service->getModule('order_flow');
        $productionQc = $service->getModule('production_qc');

        $this->assertNotNull($core);
        $this->assertNotNull($orderFlow);
        $this->assertNotNull($service->getModule('graphics'));
        $this->assertNotNull($service->getModule('procurement'));
        $this->assertNotNull($service->getModule('production'));
        $this->assertNotNull($service->getModule('delivery'));
        $this->assertNotNull($service->getModule('current_accounts'));
        $this->assertNotNull($service->getModule('print_settings'));
        $this->assertNotNull($service->getModule('product_data_hub'));
        $this->assertNotNull($service->getModule('customer_portal'));
        $this->assertNotNull($productionQc);

        $this->assertSame('core', $core['status']);
        $this->assertSame('planned', $productionQc['status']);
        $this->assertTrue($service->isCoreModule('core'));
        $this->assertTrue($service->isActiveModule('order_flow'));
        $this->assertTrue($service->isPlannedModule('production_qc'));

        foreach ($service->modules() as $module) {
            $this->assertContains($module['status'], $service->statusValues());
        }

        $quoteApprovalFeature = $service->getFeature('public_quote_approval');
        $this->assertNotNull($quoteApprovalFeature);
        $this->assertSame('public_quote_approval', $quoteApprovalFeature['key']);

        $this->assertSame('order_flow', $service->normalizeModuleKey('promotion_orders'));
        $this->assertSame('quote_customer_approval', $service->normalizeModuleKey('customer_quote_approval'));
        $this->assertSame('production_qc', $service->normalizeModuleKey('quality_control'));
        $this->assertSame('public_quote_approval', $service->normalizeFeatureKey('customer_quote_approval'));

        $this->assertNull($service->getModule('not_real_module'));
        $this->assertNull($service->getFeature('not_real_feature'));

        $defaultModules = $service->defaultEnabledModules();
        $this->assertContains('core', $defaultModules);
        $this->assertContains('order_flow', $defaultModules);
        $this->assertNotContains('production_qc', $defaultModules);

        $orderFlowOptions = $service->featureOptionsForAdmin('order_flow');
        $this->assertNotEmpty($orderFlowOptions);
        $this->assertContains('promotion_quotes', array_column($orderFlowOptions, 'key'));

        $moduleOptions = $service->moduleOptionsForAdmin();
        $this->assertNotEmpty($moduleOptions);
        $this->assertContains('current_accounts', array_column($moduleOptions, 'key'));
        $this->assertContains('api_access', array_column($moduleOptions, 'key'));
    }

    public function test_existing_operational_surfaces_stay_clean_after_catalog_standardization(): void
    {
        $workForm = $this->createPublicTrackingWorkForm();

        $public = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $public->assertOk();
        $public->assertDontSee('group_code', false);
        $public->assertDontSee('file_path', false);
        $public->assertDontSee('physical_path', false);
        $public->assertDontSee('default_unit_price', false);

        $admin = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index'));

        $admin->assertOk();
        $admin->assertDontSee('group_code', false);
        $admin->assertDontSee('file_path', false);
        $admin->assertDontSee('physical_path', false);
    }

    private function createPublicTrackingWorkForm()
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'MOD-CAT-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Module Catalog Public Product',
            'product_code' => 'MOD-CAT-001',
            'quantity' => 2,
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
