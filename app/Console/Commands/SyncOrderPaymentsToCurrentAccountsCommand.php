<?php

namespace App\Console\Commands;

use App\Models\TenantAccount;
use App\Services\OrderPaymentCurrentAccountSyncService;
use Illuminate\Console\Command;

class SyncOrderPaymentsToCurrentAccountsCommand extends Command
{
    protected $signature = 'prodelya:sync-order-payments-to-current-accounts
        {--tenant= : Yalnız belirtilen tenant için çalıştır}
        {--payment= : Yalnız belirtilen order payment kaydı için çalıştır}
        {--dry-run : Veritabanına yazmadan rapor üret}';

    protected $description = 'Order payment kayıtlarını current account transactions tablosuna senkronlar.';

    public function __construct(
        private readonly OrderPaymentCurrentAccountSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $paymentId = $this->option('payment');
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
            'payments' => 0,
            'created' => 0,
            'updated' => 0,
            'cancelled' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($tenants as $tenant) {
            $report = $this->syncService->syncOrderPaymentsForTenant($tenant, $dryRun, $paymentId ? (int) $paymentId : null);

            $this->line(sprintf(
                'Tenant #%d %s | payments:%d created:%d updated:%d cancelled:%d skipped:%d errors:%d%s',
                $tenant->id,
                $tenant->name,
                $report['payments'],
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
            ['Payments', $totals['payments']],
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
