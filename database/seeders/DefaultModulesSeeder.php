<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TenantModule;
use App\Models\TenantAccount;

class DefaultModulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the default tenant created by DefaultTenantSeeder
        $tenant = TenantAccount::first();
        
        if (!$tenant) {
            $this->command->error('No tenant found. Run DefaultTenantSeeder first.');
            return;
        }

        $defaultModules = config('prodelya_modules.default_modules', ['core']);
        $trialModules = config('prodelya_modules.trial_modules', []);

        // Enable default modules
        foreach ($defaultModules as $moduleKey) {
            $this->enableModule($tenant->id, $moduleKey, true);
        }

        // Enable trial modules for demo
        foreach ($trialModules as $moduleKey) {
            $this->enableModule($tenant->id, $moduleKey, true);
        }

        $this->command->info('Default modules enabled for tenant: ' . $tenant->name);
    }

    /**
     * Enable a module for a tenant
     */
    private function enableModule($tenantId, $moduleKey, $isEnabled)
    {
        $moduleConfig = config("prodelya_modules.modules.{$moduleKey}");
        
        if (!$moduleConfig) {
            $this->command->warn("Module configuration not found: {$moduleKey}");
            return;
        }

        TenantModule::updateOrCreate(
            [
                'tenant_account_id' => $tenantId,
                'module_key' => $moduleKey,
            ],
            [
                'is_enabled' => $isEnabled,
                'meta' => [
                    'enabled_at' => now(),
                    'enabled_by' => 'system',
                    'module_name' => $moduleConfig['name'],
                    'description' => $moduleConfig['description'],
                ],
            ]
        );

        $this->command->line("  - {$moduleConfig['name']}: " . ($isEnabled ? 'Enabled' : 'Disabled'));
    }
}
