<?php

namespace App\Console\Commands;

use App\Models\SupplierSource;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Console\Command;

class ProductDataHubSyncSourcesCommand extends Command
{
    protected $signature = 'product-data-hub:sync-sources
        {--source= : Sadece belirtilen source ID için çalıştır}
        {--all : Tüm görünür Product Data Hub kaynaklarını çalıştır}
        {--frequency= : Sadece manual/hourly/daily/weekly kaynakları çalıştır}
        {--dry-run : Veri yazmadan önizleme ve rapor üret}
        {--force : Frekans ve zaman filtresini zorlayarak çalıştır}
        {--no-build : Standard product build adımını atla}
        {--no-project : Tenant catalog projection adımını atla}
        {--report-only : Sync çalıştırmadan sadece uygunluk raporu üret}';

    protected $description = 'Product Data Hub kaynaklarını güvenli şekilde senkronize eder.';

    public function __construct(
        private readonly SupplierSourceSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $frequency = $this->normalizeFrequency($this->option('frequency'));
        $sourceId = $this->option('source');
        $sources = $this->resolveSources($sourceId);

        $checked = 0;
        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $partial = 0;

        foreach ($sources as $source) {
            $checked++;

            [$shouldRun, $skipReason] = $this->shouldRunSource($source, $frequency);
            if (!$shouldRun) {
                $skipped++;
                $this->warn(($source->supplier?->name ?? $source->source_name) . ' atlandı: ' . $skipReason);
                continue;
            }

            if ($this->option('report-only')) {
                $this->line(($source->supplier?->name ?? $source->source_name) . ' uygun: source #' . $source->id);
                continue;
            }

            $result = $this->syncService->syncSource($source, [
                'run_type' => $frequency ? 'scheduled' : 'manual',
                'dry_run' => (bool) $this->option('dry-run'),
                'no_build' => (bool) $this->option('no-build'),
                'no_project' => (bool) $this->option('no-project'),
                'force' => (bool) $this->option('force'),
            ]);

            $run = $result['run'];
            if ($run->status === 'failed') {
                $failed++;
                $this->error(($source->supplier?->name ?? $source->source_name) . ' başarısız: ' . ($run->error_message ?: 'Kaynak okunamadı.'));
                continue;
            }

            if ($run->status === 'partial') {
                $partial++;
            }

            $processed++;
            $dryRunSuffix = data_get($run->report_payload, 'dry_run') ? ' (dry-run)' : '';
            $this->info(sprintf(
                '%s senkronize edildi%s: okunan %d, yeni %d, güncellenen %d, stok değişen %d, fiyat değişen %d.',
                $source->supplier?->name ?? $source->source_name,
                $dryRunSuffix,
                (int) $run->records_read,
                (int) $run->products_created,
                (int) $run->products_updated,
                (int) $run->stock_changed_count,
                (int) $run->price_changed_count,
            ));
        }

        $this->newLine();
        $this->line($checked . ' kaynak kontrol edildi.');
        $this->line($processed . ' kaynak senkronize edildi.');
        $this->line($skipped . ' kaynak atlandı.');
        $this->line($failed . ' hata.');
        if ($partial > 0) {
            $this->warn($partial . ' kaynak kısmi başarı ile tamamlandı.');
        }
        if ($this->option('dry-run')) {
            $this->comment('Bu işlem dry-run olarak çalıştı, veri değiştirilmedi.');
        }

        return $failed > 0 && $processed === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveSources(mixed $sourceId): \Illuminate\Support\Collection
    {
        if ($sourceId !== null) {
            $source = SupplierSource::query()
                ->visibleInProductDataHub()
                ->with(['supplier', 'fieldMappings'])
                ->find($sourceId);

            return collect($source ? [$source] : []);
        }

        return SupplierSource::query()
            ->visibleInProductDataHub()
            ->with(['supplier', 'fieldMappings'])
            ->orderBy('source_name')
            ->get();
    }

    private function shouldRunSource(SupplierSource $source, ?string $frequency): array
    {
        if ($frequency !== null && !$this->option('force')) {
            $sourceFrequency = (string) data_get($source->config, 'sync_policy.sync_frequency', data_get($source->config, 'sync_frequency', 'manual'));
            if ($sourceFrequency !== $frequency) {
                return [false, 'frekans uygun değil.'];
            }
        }

        if (!$this->hasLocation($source)) {
            return [false, 'Kaynak URL bilgisi eksik olduğu için senkronizasyon atlandı.'];
        }

        if (!$this->hasRunnableMapping($source)) {
            return [false, 'Alan eşleme bilgisi eksik olduğu için senkronizasyon başlatılmadı.'];
        }

        return [true, null];
    }

    private function hasLocation(SupplierSource $source): bool
    {
        return filled($source->url) || filled(data_get($source->config, 'source_file_path'));
    }

    private function hasRunnableMapping(SupplierSource $source): bool
    {
        $profileKey = (string) data_get($source->config, 'profile_key', '');
        $hasFieldMappings = $source->fieldMappings->isNotEmpty();

        if ($profileKey === 'CUSTOM') {
            return $hasFieldMappings;
        }

        return config()->has('prodelya_product_data_hub.supplier_profiles.' . $profileKey)
            || config()->has('prodelya_product_data_hub.supplier_profiles.' . ($source->supplier?->code ?? ''))
            || $hasFieldMappings;
    }

    private function normalizeFrequency(mixed $frequency): ?string
    {
        $value = is_string($frequency) ? trim($frequency) : null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!in_array($value, ['manual', 'hourly', 'daily', 'weekly'], true)) {
            $this->fail('Geçersiz frequency değeri. Sadece manual, hourly, daily veya weekly kullanın.');
        }

        return $value;
    }
}
