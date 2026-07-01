<?php

namespace App\Console\Commands;

use App\Services\ProductDataHub\SyncRunRecoveryService;
use Illuminate\Console\Command;

class ProductDataHubRecoverRunsCommand extends Command
{
    protected $signature = 'product-data-hub:recover-runs
        {--dry-run : DB yazmadan sadece recovery raporu üret}
        {--minutes=60 : Running kabul edilen maksimum süre eşiği (dakika)}';

    protected $description = 'Running kalan Product Data Hub sync run kayıtlarını güvenli recovery standardı ile raporlar veya stuck olarak işaretler.';

    public function __construct(
        private readonly SyncRunRecoveryService $recoveryService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');
        $result = $this->recoveryService->recoverStuckRuns($minutes, $dryRun);

        if ($dryRun) {
            $this->comment('DRY-RUN: Veri yazılmadı.');
        }

        $this->line($result['count'] . ' stuck run bulundu');

        foreach ($result['entries'] as $entry) {
            $this->line('Source: ' . $entry['source_name']);
            $this->line('Run ID: ' . $entry['run_id']);
            $this->line('Running süresi: ' . (($entry['running_minutes'] ?? 0)) . ' dakika');
            $this->line('Önerilen işlem: ' . $entry['recommended_action']);
            $this->newLine();
        }

        if (!$dryRun && $result['count'] > 0) {
            $this->info($result['count'] . ' run stuck olarak işaretlendi.');
        }

        return self::SUCCESS;
    }
}
