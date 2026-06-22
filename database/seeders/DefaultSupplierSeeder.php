<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use Illuminate\Database\Seeder;

class DefaultSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantAccount::query()->where('panel_subdomain', 'demo')->first();

        $suppliers = [
            ['code' => 'ETKIN', 'name' => 'Etkin Promosyon', 'status' => 'active'],
            ['code' => 'AKDENIZ', 'name' => 'Akdeniz Promosyon', 'status' => 'active'],
            ['code' => 'ILPEN', 'name' => 'İlpen', 'status' => 'active'],
            ['code' => 'YENI-NESIL', 'name' => 'Yeni Nesil', 'status' => 'active'],
        ];

        foreach ($suppliers as $row) {
            $supplier = Supplier::query()->updateOrCreate(
                ['code' => $row['code']],
                $row
            );

            if ($tenant) {
                TenantSupplierAccess::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'supplier_id' => $supplier->id,
                    ],
                    [
                        'is_active' => true,
                        'can_view_products' => true,
                        'can_request_purchase' => true,
                        'can_use_in_quotes' => true,
                        'visible_in_catalog' => true,
                        'export_allowed' => false,
                        'granted_at' => now(),
                    ]
                );
            }
        }
    }
}
