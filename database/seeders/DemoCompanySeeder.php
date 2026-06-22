<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\TenantAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoCompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the demo tenant
        $tenant = TenantAccount::where('slug', 'demo-sirketi')->first();
        if (!$tenant) {
            $this->command->error('Demo tenant not found. Please run DefaultTenantSeeder first.');
            return;
        }

        // Note: Company roles are attached to companies, not created separately
        // The role_key 'customer' is already defined in the migration enum

        // Create demo companies
        $companies = [
            [
                'tenant_account_id' => $tenant->id,
                'legal_name' => 'ABC İnşaat A.Ş.',
                'short_name' => 'ABC İnşaat',
                'tax_number' => '1234567890',
                'tax_office' => 'Büyükşehir Vergi Dairesi',
                'email' => 'info@abcinsaat.com',
                'phone' => '+90 212 555 0101',
                'website' => 'https://www.abcinsaat.com',
                'status' => 'active',
                'risk_status' => 'low',
                'notes' => 'Demo müşteri - inşaat sektörü',
            ],
            [
                'tenant_account_id' => $tenant->id,
                'legal_name' => 'Delta Eğitim Ltd. Şti.',
                'short_name' => 'Delta Eğitim',
                'tax_number' => '0987654321',
                'tax_office' => 'Eğitim Vergi Dairesi',
                'email' => 'iletisim@deltaegitim.com',
                'phone' => '+90 216 555 0202',
                'website' => 'https://www.deltaegitim.com',
                'status' => 'active',
                'risk_status' => 'low',
                'notes' => 'Demo müşteri - eğitim sektörü',
            ],
        ];

        foreach ($companies as $companyData) {
            // Check if company already exists
            $existingCompany = Company::where('tenant_account_id', $tenant->id)
                ->where('tax_number', $companyData['tax_number'])
                ->first();

            if (!$existingCompany) {
                $company = Company::create($companyData);
                
                // Attach customer role
                $company->companyRoles()->create([
                    'tenant_account_id' => $tenant->id,
                    'company_id' => $company->id,
                    'role_key' => 'customer',
                ]);
                
                $this->command->info("Created demo company: {$company->legal_name}");
            } else {
                $this->command->line("Company already exists: {$existingCompany->legal_name}");
            }
        }

        $this->command->info('Demo company seeding completed.');
    }
}
