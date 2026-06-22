<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DefaultTenantSeeder::class,
            DefaultRolesAndPermissionsSeeder::class,
            DefaultModulesSeeder::class,
            PackageSeeder::class,
            DefaultSupplierSeeder::class,
            StandardPrintTypeSeeder::class,
            DefaultStandardCategorySeeder::class,
            DefaultProductAttributeDefinitionSeeder::class,
            DefaultSupplierCategoryMappingSeeder::class,
            DefaultSupplierFieldMappingSeeder::class,
            DefaultNumberingSeeder::class,
            DemoCompanySeeder::class,
        ]);

        // Optional: Create test data for development
        if (app()->environment('local')) {
            // User::factory(10)->create();
        }
    }
}
