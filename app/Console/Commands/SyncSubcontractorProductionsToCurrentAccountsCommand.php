<?php

namespace App\Console\Commands;

use App\Models\TenantAccount;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use Illuminate\Console\Command;

class SyncSubcontractorProductionsToCurrentAccountsCommand extends Command
{
    protected $signature = 'prodelya:sync-subcontractor-productions-to-current-accounts
        {--tenant= : Yalnız belirtilen tenant için çalıştır}
        {--production= : Yalnız belirtilen üretim kaydı için çalıştır}
        {--dry-run : Veritabanına yazmadan rapor üret}';

    protected $description = 'Fason / dış üretim maliyetlerini current account transactions tablosuna senkronlar.';

    public function __construct(
        private readonly SubcontractorProductionCurrentAccountSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $productionId = $this->option('production');
        $dryRun = (bool) $this->option('dry-run');

        $tenants = TenantAccount::query()
            ->when($tenantId, fn ($query) => $query->whereKey((int) $tenantId))
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('İşlenecek tenant bulunamadı.');

            return self::FAILURE;
        }

        $totals = [
            'productions' => 0,
            'created' => 0,
            'updated' => 0,
            'cancelled' => 0,
            'skipped_internal' => 0,
            'skipped_cost_missing' => 0,
            'skipped_company_missing' => 0,
            'errors' => 0,
        ];

        foreach ($tenants as $tenant) {
            $report = $this->syncService->syncTenantProductions(
                $tenant,
                $dryRun,
                $productionId ? (int) $productionId : null
            );

            $this->line(sprintf(
                'Tenant #%d %s | productions:%d created:%d updated:%d cancelled:%d skipped_internal:%d skipped_cost:%d skipped_company:%d errors:%d%s',
                $tenant->id,
                $tenant->name,
                $report['productions'],
                $report['created'],
                $report['updated'],
                $report['cancelled'],
                $report['skipped_internal'],
                $report['skipped_cost_missing'],
                $report['skipped_company_missing'],
                $report['errors'],
                $dryRun ? ' [dry-run]' : ''
            ));

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $report[$key] ?? 0;
            }
        }

        $this->table(['Ölçüm', 'Değer'], [
            ['Productions', $totals['productions']],
            ['Created', $totals['created']],
            ['Updated', $totals['updated']],
            ['Cancelled', $totals['cancelled']],
            ['Skipped Internal', $totals['skipped_internal']],
            ['Skipped Cost Missing', $totals['skipped_cost_missing']],
            ['Skipped Company Missing', $totals['skipped_company_missing']],
            ['Errors', $totals['errors']],
            ['Dry Run', $dryRun ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
