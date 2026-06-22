<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\TenantModule;
use App\Models\TenantNumberSequence;
use Illuminate\Support\Str;

class DefaultTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a default tenant for development/testing
        $tenant = TenantAccount::create([
            'name' => 'Demo Şirketi',
            'legal_name' => 'Demo Şirketi Ltd. Şti.',
            'slug' => 'demo-sirketi',
            'panel_subdomain' => 'demo',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        // Create default tenant settings
        $this->createDefaultSettings($tenant->id);

        // Enable default modules
        $this->enableDefaultModules($tenant->id);

        // Create default number sequences
        $this->createDefaultNumberSequences($tenant->id);

        $this->command->info('Default tenant created: ' . $tenant->name);
    }

    /**
     * Create default settings for the tenant
     */
    private function createDefaultSettings($tenantId)
    {
        $commonSettings = TenantSetting::getCommonSettings();

        foreach ($commonSettings as $key => $config) {
            TenantSetting::create([
                'tenant_account_id' => $tenantId,
                'key' => $key,
                'value' => $config['default'],
                'type' => $config['type'],
                'description' => "Default setting for {$key}",
            ]);
        }
    }

    /**
     * Enable default modules for the tenant
     */
    private function enableDefaultModules($tenantId)
    {
        $defaultModules = config('prodelya_modules.default_modules', ['core']);

        foreach ($defaultModules as $moduleKey) {
            TenantModule::create([
                'tenant_account_id' => $tenantId,
                'module_key' => $moduleKey,
                'is_enabled' => true,
                'meta' => [
                    'enabled_at' => now(),
                    'enabled_by' => 'system',
                ],
            ]);
        }
    }

    /**
     * Create default number sequences
     */
    private function createDefaultNumberSequences($tenantId)
    {
        $currentYear = date('Y');
        $documentTypes = config('prodelya_numbering.document_types', []);

        foreach ($documentTypes as $type => $config) {
            TenantNumberSequence::create([
                'tenant_account_id' => $tenantId,
                'document_type' => $type,
                'year' => $currentYear,
                'prefix' => $config['prefix'],
                'sequence_length' => $config['sequence_length'],
                'current_number' => 0,
                'reset_period' => $config['reset_period'],
                'format' => $config['format'],
            ]);
        }
    }
}
