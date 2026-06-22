<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TenantNumberSequence;
use App\Models\TenantAccount;

class DefaultNumberingSeeder extends Seeder
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

        $currentYear = date('Y');
        $documentTypes = config('prodelya_numbering.document_types', []);
        $orderFamilies = config('prodelya.order_families', []);

        // Create sequences for each document type
        foreach ($documentTypes as $type => $config) {
            $this->createNumberSequence($tenant->id, $type, null, $config, $currentYear);
        }

        // Create sequences for each order family if needed
        foreach ($orderFamilies as $familyKey => $familyConfig) {
            foreach ($documentTypes as $type => $config) {
                $this->createNumberSequence($tenant->id, $type, $familyKey, $config, $currentYear);
            }
        }

        $this->command->info('Default number sequences created for tenant: ' . $tenant->name);
    }

    /**
     * Create a number sequence
     */
    private function createNumberSequence($tenantId, $documentType, $orderFamily, $config, $year)
    {
        $sequence = TenantNumberSequence::updateOrCreate(
            [
                'tenant_account_id' => $tenantId,
                'document_type' => $documentType,
                'order_family' => $orderFamily,
                'year' => $year,
            ],
            [
                'prefix' => $config['prefix'],
                'sequence_length' => $config['sequence_length'],
                'current_number' => 0,
                'reset_period' => $config['reset_period'],
                'format' => $config['format'],
            ]
        );

        $familyText = $orderFamily ? " ({$orderFamily})" : '';
        $this->command->line("  - {$config['name']}{$familyText}: {$config['format']}");
    }
}
