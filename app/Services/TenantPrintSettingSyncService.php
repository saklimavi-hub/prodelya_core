<?php

namespace App\Services;

use App\Models\StandardPrintType;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use Illuminate\Support\Facades\DB;

class TenantPrintSettingSyncService
{
    public function syncForTenant(TenantAccount $tenant): array
    {
        $types = StandardPrintType::query()
            ->where('status', StandardPrintType::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $report = [
            'tenant_id' => $tenant->id,
            'types' => $types->count(),
            'created' => 0,
            'skipped_existing' => 0,
            'errors' => 0,
        ];

        foreach ($types as $type) {
            try {
                $existing = TenantPrintSetting::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('standard_print_type_id', $type->id)
                    ->exists();

                if ($existing) {
                    $report['skipped_existing']++;
                    continue;
                }

                $this->createDefaultFromStandard($tenant, $type);
                $report['created']++;
            } catch (\Throwable) {
                $report['errors']++;
            }
        }

        return $report;
    }

    public function syncAllTenants(bool $dryRun = false): array
    {
        $report = [
            'tenants' => 0,
            'created' => 0,
            'skipped_existing' => 0,
            'errors' => 0,
        ];

        $runner = function () use (&$report): void {
            $tenants = TenantAccount::query()->orderBy('id')->get();
            $report['tenants'] = $tenants->count();

            foreach ($tenants as $tenant) {
                $tenantReport = $this->syncForTenant($tenant);
                $report['created'] += $tenantReport['created'];
                $report['skipped_existing'] += $tenantReport['skipped_existing'];
                $report['errors'] += $tenantReport['errors'];
            }
        };

        if ($dryRun) {
            DB::beginTransaction();

            try {
                $runner();
            } finally {
                DB::rollBack();
            }
        } else {
            $runner();
        }

        return $report;
    }

    public function ensureSettingForTenant(TenantAccount $tenant, StandardPrintType $type): TenantPrintSetting
    {
        $existing = TenantPrintSetting::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_print_type_id', $type->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createDefaultFromStandard($tenant, $type);
    }

    public function createDefaultFromStandard(TenantAccount $tenant, StandardPrintType $type): TenantPrintSetting
    {
        return TenantPrintSetting::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_print_type_id' => $type->id,
            'custom_name' => null,
            'is_active' => $type->isActive(),
            'production_mode' => $type->default_production_mode,
            'default_subcontractor_company_id' => null,
            'default_subcontractor_current_account_id' => null,
            'default_currency' => $this->normalizeCurrency($tenant->default_currency ?: 'TRY'),
            'default_unit_price' => null,
            'default_setup_cost' => null,
            'requires_graphic' => $type->default_requires_graphic,
            'requires_production' => $type->default_requires_production,
            'requires_setup' => $type->default_requires_setup,
            'setup_types' => array_values(array_filter((array) $type->default_setup_types)),
            'notes' => null,
        ]);
    }

    private function normalizeCurrency(?string $currency): string
    {
        $value = strtoupper(trim((string) $currency));

        return match ($value) {
            'TL' => 'TRY',
            'TRY', 'USD', 'EUR' => $value,
            default => 'TRY',
        };
    }
}
