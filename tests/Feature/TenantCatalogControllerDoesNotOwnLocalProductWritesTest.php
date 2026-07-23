<?php

namespace Tests\Feature;

use Tests\TestCase;

class TenantCatalogControllerDoesNotOwnLocalProductWritesTest extends TestCase
{
    public function test_tenant_catalog_controller_no_longer_owns_local_product_write_routes(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'admin.catalog.local-products',
            'admin.catalog.local-products.create',
            'admin.catalog.local-products.store',
            'admin.catalog.local-products.show',
            'admin.catalog.local-products.edit',
            'admin.catalog.local-products.update',
            'admin.catalog.local-products.import',
            'admin.catalog.local-products.import.preview',
            'admin.catalog.local-products.import.apply',
        ] as $routeName) {
            $route = $routes->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] tanımlı değil.");
            $this->assertStringNotContainsString(
                'TenantCatalogController',
                $route->getActionName(),
                "Route [{$routeName}] hâlâ TenantCatalogController üzerinde."
            );
        }
    }
}
