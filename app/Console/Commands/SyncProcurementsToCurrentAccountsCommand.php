<?php

namespace App\Console\Commands;

use App\Models\TenantAccount;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use Illuminate\Console\Command;

class SyncProcurementsToCurrentAccountsCommand extends Command
{
    protected $signature = 'prodelya:sync-procurements-to-current-accounts
        {--tenant= : Yalnız belirtilen tenant için çalıştır}
        {--request= : Yalnız belirtilen tedarik talebi için çalıştır}
        {--item= : Yalnız belirtilen talep kalemi için çalıştır}
        {--dry-run : Veritabanına yazmadan rapor üret}';

    protected $description = 'Supplier procurement request item kayıtlarını current account transactions tablosuna senkronlar.';

    public function __construct(
        private readonly SupplierProcurementCurrentAccountSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $requestId = $this->option('request');
        $itemId = $this->option('item');
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
            'requests' => 0,
            'items' => 0,
            'created' => 0,
            'updated' => 0,
            'cancelled' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($tenants as $tenant) {
            $report = $this->syncService->syncTenantProcurements(
                $tenant,
                $dryRun,
                $requestId ? (int) $requestId : null,
                $itemId ? (int) $itemId : null
            );

            $this->line(sprintf(
                'Tenant #%d %s | requests:%d items:%d created:%d updated:%d cancelled:%d skipped:%d errors:%d%s',
                $tenant->id,
                $tenant->name,
                $report['requests'],
                $report['items'],
                $report['created'],
                $report['updated'],
                $report['cancelled'],
                $report['skipped'],
                $report['errors'],
                $dryRun ? ' [dry-run]' : ''
            ));

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $report[$key] ?? 0;
            }
        }

        $this->table(['Ölçüm', 'Değer'], [
            ['Requests', $totals['requests']],
            ['Items', $totals['items']],
            ['Created', $totals['created']],
            ['Updated', $totals['updated']],
            ['Cancelled', $totals['cancelled']],
            ['Skipped', $totals['skipped']],
            ['Errors', $totals['errors']],
            ['Dry Run', $dryRun ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
