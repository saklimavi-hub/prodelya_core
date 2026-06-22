<?php

namespace App\Console\Commands;

use App\Models\TenantAccount;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncTenantPrintSettingsCommand extends Command
{
    protected $signature = 'prodelya:sync-tenant-print-settings
        {--tenant= : Yalnız belirtilen tenant için çalıştır}
        {--dry-run : Veritabanına yazmadan rapor üret}';

    protected $description = 'Tenant baskı ayarlarını standart baskı tiplerinden senkronlar.';

    public function __construct(
        private readonly TenantPrintSettingSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
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
            'tenants' => $tenants->count(),
            'created' => 0,
            'skipped_existing' => 0,
            'errors' => 0,
        ];

        foreach ($tenants as $tenant) {
            if ($dryRun) {
                DB::beginTransaction();

                try {
                    $report = $this->syncService->syncForTenant($tenant);
                } finally {
                    DB::rollBack();
                }
            } else {
                $report = $this->syncService->syncForTenant($tenant);
            }

            $tenantCreated = $report['created'] ?? 0;
            $tenantSkipped = $report['skipped_existing'] ?? 0;
            $tenantErrors = $report['errors'] ?? 0;

            $this->line(sprintf(
                'Tenant #%d %s | created:%d skipped:%d errors:%d%s',
                $tenant->id,
                $tenant->name,
                $tenantCreated,
                $tenantSkipped,
                $tenantErrors,
                $dryRun ? ' [dry-run]' : ''
            ));

            $totals['created'] += $tenantCreated;
            $totals['skipped_existing'] += $tenantSkipped;
            $totals['errors'] += $tenantErrors;
        }

        $this->table(['Ölçüm', 'Değer'], [
            ['Tenants', $totals['tenants']],
            ['Created', $totals['created']],
            ['Skipped Existing', $totals['skipped_existing']],
            ['Errors', $totals['errors']],
            ['Dry Run', $dryRun ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
