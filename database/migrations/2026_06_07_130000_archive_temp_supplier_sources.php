<?php

use App\Models\SupplierSource;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SupplierSource::query()
            ->with('supplier')
            ->get()
            ->filter(function (SupplierSource $source) {
                $profileKey = (string) data_get($source->config, 'profile_key', '');
                $supplierCode = (string) ($source->supplier?->code ?? '');

                return str_starts_with($profileKey, 'TMP-') || str_starts_with($supplierCode, 'TMP-');
            })
            ->each(function (SupplierSource $source) {
                $config = (array) ($source->config ?? []);
                $config['lifecycle_state'] = 'archived';
                $config['temp_profile'] = true;
                $config['archive_reason'] = $config['archive_reason'] ?? 'legacy_temp_source_cleanup';
                $config['archived_at'] = $config['archived_at'] ?? now()->toDateTimeString();

                $source->update([
                    'status' => 'inactive',
                    'config' => $config,
                ]);
            });
    }

    public function down(): void
    {
        SupplierSource::query()
            ->get()
            ->filter(fn (SupplierSource $source) => (string) data_get($source->config, 'archive_reason') === 'legacy_temp_source_cleanup')
            ->each(function (SupplierSource $source) {
                $config = (array) ($source->config ?? []);
                unset($config['archived_at'], $config['archive_reason'], $config['temp_profile']);
                $config['lifecycle_state'] = 'inactive';

                $source->update([
                    'status' => 'inactive',
                    'config' => $config,
                ]);
            });
    }
};
