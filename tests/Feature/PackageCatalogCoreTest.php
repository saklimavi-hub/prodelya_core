<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageFeature;
use App\Models\PackageLimit;
use App\Models\PackageModule;
use App\Services\PackageCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageCatalogCoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_package_models_seeder_and_catalog_service_work_with_canonical_keys(): void
    {
        $catalog = app(PackageCatalogService::class);

        $starter = Package::query()->where('key', 'starter')->first();
        $promotion = Package::query()->where('key', 'promotion')->first();
        $suite = Package::query()->where('key', 'suite')->first();
        $enterprise = Package::query()->where('key', 'enterprise')->first();

        $this->assertNotNull($starter);
        $this->assertNotNull($promotion);
        $this->assertNotNull($suite);
        $this->assertNotNull($enterprise);

        $this->assertInstanceOf(PackageModule::class, $promotion->modules()->first());
        $this->assertInstanceOf(PackageFeature::class, $promotion->features()->first());
        $this->assertInstanceOf(PackageLimit::class, $promotion->limits()->first());

        $module = PackageModule::query()->create([
            'package_id' => $promotion->id,
            'module_key' => 'promotion_orders',
            'is_enabled' => true,
            'status' => 'active',
        ]);

        $feature = PackageFeature::query()->create([
            'package_id' => $starter->id,
            'module_key' => 'customer_quote_approval',
            'feature_key' => 'customer_quote_approval',
            'is_enabled' => true,
            'status' => 'active',
            'notes' => 'alias normalize test',
        ]);

        $limit = $enterprise->limitFor('users');

        $this->assertSame('order_flow', $module->fresh()->module_key);
        $this->assertSame('quote_customer_approval', $feature->fresh()->module_key);
        $this->assertSame('public_quote_approval', $feature->fresh()->feature_key);
        $this->assertNotNull($limit);
        $this->assertTrue($limit->isUnlimited());
        $this->assertNull($limit->effectiveLimitValue());

        $this->assertNotEmpty($catalog->activePackages());
        $this->assertTrue($catalog->hasModule($promotion->fresh(), 'product_data_hub'));
        $this->assertTrue($catalog->hasFeature($promotion->fresh(), 'customer_quote_approval', 'customer_quote_approval'));

        $packageLimit = $catalog->getLimit($suite->fresh(), 'users');
        $this->assertNotNull($packageLimit);
        $this->assertSame(20, $packageLimit['limit_value']);

        $this->assertContains('product_data_hub', array_column($catalog->packageModules($promotion->fresh()), 'module_key'));
        $this->assertContains('public_quote_approval', array_column($catalog->packageFeatures($promotion->fresh()), 'feature_key'));
        $this->assertContains('public_quote_approval', array_column($catalog->packageFeatures($starter->fresh()), 'feature_key'));
        $this->assertContains('users', array_column($catalog->packageLimits($suite->fresh()), 'limit_key'));
    }
}
