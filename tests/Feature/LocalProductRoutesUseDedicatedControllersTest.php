<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalProductRoutesUseDedicatedControllersTest extends TestCase
{
    public function test_local_product_routes_use_dedicated_controllers(): void
    {
        $routes = app('router')->getRoutes();

        $expectedActions = [
            'admin.catalog.local-products' => 'App\Http\Controllers\Admin\LocalProductController@index',
            'admin.catalog.local-products.create' => 'App\Http\Controllers\Admin\LocalProductController@create',
            'admin.catalog.local-products.store' => 'App\Http\Controllers\Admin\LocalProductController@store',
            'admin.catalog.local-products.show' => 'App\Http\Controllers\Admin\LocalProductController@show',
            'admin.catalog.local-products.edit' => 'App\Http\Controllers\Admin\LocalProductController@edit',
            'admin.catalog.local-products.update' => 'App\Http\Controllers\Admin\LocalProductController@update',
            'admin.catalog.local-products.import' => 'App\Http\Controllers\Admin\LocalProductImportController@create',
            'admin.catalog.local-products.import.preview' => 'App\Http\Controllers\Admin\LocalProductImportController@preview',
            'admin.catalog.local-products.import.apply' => 'App\Http\Controllers\Admin\LocalProductImportController@apply',
        ];

        foreach ($expectedActions as $routeName => $action) {
            $route = $routes->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] tanımlı değil.");
            $this->assertSame($action, $route->getActionName(), "Route [{$routeName}] dedicated controller'a bağlı değil.");
        }
    }
}
