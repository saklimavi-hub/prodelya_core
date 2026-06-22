<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Services\ModuleFeatureCatalogService;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'key' => 'starter',
                'name' => 'Starter',
                'description' => 'Temel operasyon omurgasi ve dusuk kullanim limitleri.',
                'status' => 'active',
                'sort_order' => 10,
                'is_public' => true,
                'trial_days' => 14,
                'monthly_price' => 990,
                'yearly_price' => 9990,
                'currency' => 'TRY',
                'modules' => [],
                'features' => [],
                'limits' => [
                    'users' => 3,
                    'current_accounts' => 250,
                    'companies' => 250,
                    'products' => 500,
                    'supplier_feeds' => 0,
                    'orders' => 250,
                    'storage_mb' => 1024,
                    'custom_domains' => 0,
                    'api_tokens' => 0,
                ],
            ],
            [
                'key' => 'promotion',
                'name' => 'Promotion',
                'description' => 'Promosyon operasyonlari icin Product Data Hub ve teklif onayi destekli paket.',
                'status' => 'active',
                'sort_order' => 20,
                'is_public' => true,
                'trial_days' => 14,
                'monthly_price' => 1990,
                'yearly_price' => 19990,
                'currency' => 'TRY',
                'modules' => [
                    'product_data_hub',
                    'supplier_feed',
                    'quote_customer_approval',
                ],
                'features' => [
                    ['module_key' => 'quote_customer_approval', 'feature_key' => 'public_quote_approval'],
                    ['module_key' => 'quote_customer_approval', 'feature_key' => 'quote_approval_requests'],
                    ['module_key' => 'product_data_hub', 'feature_key' => 'tenant_catalog_projection'],
                ],
                'limits' => [
                    'users' => 8,
                    'current_accounts' => 1000,
                    'companies' => 1000,
                    'products' => 2500,
                    'supplier_feeds' => 2,
                    'orders' => 1500,
                    'storage_mb' => 4096,
                    'custom_domains' => 0,
                    'api_tokens' => 0,
                ],
            ],
            [
                'key' => 'suite',
                'name' => 'Suite',
                'description' => 'Katalog, portal, bildirim ve raporlama modullerini iceren genisletilmis paket.',
                'status' => 'active',
                'sort_order' => 30,
                'is_public' => true,
                'trial_days' => 21,
                'monthly_price' => 3490,
                'yearly_price' => 34990,
                'currency' => 'TRY',
                'modules' => [
                    'product_data_hub',
                    'advanced_catalog',
                    'supplier_feed',
                    'notification_center',
                    'customer_portal',
                    'quote_customer_approval',
                    'reporting',
                ],
                'features' => [
                    ['module_key' => 'product_data_hub', 'feature_key' => 'tenant_catalog_projection'],
                    ['module_key' => 'advanced_catalog', 'feature_key' => 'product_variants'],
                    ['module_key' => 'advanced_catalog', 'feature_key' => 'price_management'],
                    ['module_key' => 'customer_portal', 'feature_key' => 'customer_login'],
                    ['module_key' => 'quote_customer_approval', 'feature_key' => 'public_quote_approval'],
                    ['module_key' => 'notification_center', 'feature_key' => 'smtp_settings'],
                    ['module_key' => 'reporting', 'feature_key' => 'sales_analytics'],
                ],
                'limits' => [
                    'users' => 20,
                    'current_accounts' => 5000,
                    'companies' => 5000,
                    'products' => 10000,
                    'supplier_feeds' => 5,
                    'orders' => 5000,
                    'storage_mb' => 10240,
                    'custom_domains' => 1,
                    'api_tokens' => 5,
                ],
            ],
            [
                'key' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Cogu opsiyonel modulu ve yuksek limitleri acan kurumsal paket.',
                'status' => 'active',
                'sort_order' => 40,
                'is_public' => true,
                'trial_days' => 30,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'currency' => 'TRY',
                'modules' => [
                    'product_data_hub',
                    'advanced_catalog',
                    'supplier_feed',
                    'notification_center',
                    'customer_portal',
                    'quote_customer_approval',
                    'reporting',
                    'api_access',
                    'custom_domain',
                    'advanced_reports',
                    'xml_export',
                ],
                'features' => [
                    ['module_key' => 'product_data_hub', 'feature_key' => 'tenant_catalog_projection'],
                    ['module_key' => 'advanced_catalog', 'feature_key' => 'product_variants'],
                    ['module_key' => 'advanced_catalog', 'feature_key' => 'price_management'],
                    ['module_key' => 'customer_portal', 'feature_key' => 'customer_login'],
                    ['module_key' => 'quote_customer_approval', 'feature_key' => 'public_quote_approval'],
                    ['module_key' => 'notification_center', 'feature_key' => 'smtp_settings'],
                    ['module_key' => 'reporting', 'feature_key' => 'sales_analytics'],
                    ['module_key' => 'api_access', 'feature_key' => 'tenant_api_tokens'],
                    ['module_key' => 'custom_domain', 'feature_key' => 'tenant_custom_domain'],
                    ['module_key' => 'xml_export', 'feature_key' => 'feed_outputs'],
                ],
                'limits' => [
                    'users' => null,
                    'current_accounts' => null,
                    'companies' => null,
                    'products' => null,
                    'supplier_feeds' => null,
                    'orders' => null,
                    'storage_mb' => null,
                    'custom_domains' => 5,
                    'api_tokens' => 20,
                ],
                'unlimited_keys' => [
                    'users',
                    'current_accounts',
                    'companies',
                    'products',
                    'supplier_feeds',
                    'orders',
                    'storage_mb',
                ],
            ],
        ];

        foreach ($packages as $data) {
            $package = Package::query()->updateOrCreate(
                ['key' => $data['key']],
                collect($data)->except(['modules', 'features', 'limits', 'unlimited_keys'])->all()
            );

            $catalog = app(ModuleFeatureCatalogService::class);

            foreach (collect($data['modules'])->map(fn (string $moduleKey) => $catalog->normalizeModuleKey($moduleKey))->unique() as $moduleKey) {
                $package->modules()->updateOrCreate(
                    ['module_key' => $moduleKey],
                    ['is_enabled' => true, 'status' => 'active']
                );
            }

            $normalizedFeatures = collect($data['features'])
                ->map(function (array $feature) use ($catalog): array {
                    return [
                        'module_key' => isset($feature['module_key']) ? $catalog->normalizeModuleKey($feature['module_key']) : null,
                        'feature_key' => $catalog->normalizeFeatureKey($feature['feature_key']),
                    ];
                })
                ->unique('feature_key');

            foreach ($normalizedFeatures as $feature) {
                $package->features()->updateOrCreate(
                    ['feature_key' => $feature['feature_key']],
                    [
                        'module_key' => $feature['module_key'] ?? null,
                        'is_enabled' => true,
                        'status' => 'active',
                    ]
                );
            }

            foreach ($data['limits'] as $limitKey => $limitValue) {
                $package->limits()->updateOrCreate(
                    ['limit_key' => $limitKey],
                    [
                        'limit_value' => $limitValue,
                        'is_unlimited' => in_array($limitKey, $data['unlimited_keys'] ?? [], true),
                    ]
                );
            }
        }
    }
}
