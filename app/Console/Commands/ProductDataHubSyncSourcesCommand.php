<?php

namespace App\Console\Commands;

use App\Models\ProductDataHubSyncRun;
use App\Models\SupplierSource;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use App\Services\System\SystemHeartbeatService;
use Illuminate\Console\Command;
use Throwable;

class ProductDataHubSyncSourcesCommand extends Command
{
    protected $signature = 'product-data-hub:sync-sources
        {--source= : Sadece belirtilen source ID için çalıştır}
        {--all : Tüm görünür Product Data Hub kaynaklarını çalıştır}
        {--frequency= : Sadece manual/hourly/daily/weekly kaynakları çalıştır}
        {--mode=full : full veya delta çalışma modu}
        {--dry-run : Veri yazmadan önizleme ve rapor üret}
        {--apply-price-stock : Delta modunda yalnız güvenli fiyat ve stok değişikliklerini uygula}
        {--only-clean-stock : Delta modunda yalnız temiz stock_changed adaylarını filtrele}
        {--project-dirty : Delta modunda yalnız dirty standard product ID listesini tenant kataloga yansıt}
        {--force : Frekans ve zaman filtresini zorlayarak çalıştır}
        {--no-build : Standard product build adımını atla}
        {--no-project : Tenant catalog projection adımını atla}
        {--report-only : Sync çalıştırmadan sadece uygunluk raporu üret}';

    protected $description = 'Product Data Hub kaynaklarını güvenli şekilde senkronize eder.';

    public function __construct(
        private readonly SupplierSourceSyncService $syncService,
        private readonly SystemHeartbeatService $heartbeatService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $frequency = $this->normalizeFrequency($this->option('frequency'));
        $mode = $this->normalizeMode($this->option('mode'));
        $sourceId = $this->option('source');
        $heartbeatKey = $this->heartbeatKey($frequency);
        $heartbeatMeta = [
            'label' => $this->heartbeatLabel($frequency),
            'frequency' => $frequency,
            'mode' => $mode,
            'dry_run' => (bool) $this->option('dry-run'),
            'report_only' => (bool) $this->option('report-only'),
        ];

        if ($heartbeatKey !== null) {
            $this->heartbeatService->touch($heartbeatKey, $heartbeatMeta);
        }

        try {
            if ($this->option('no-project') && $this->option('project-dirty')) {
                $this->fail('--no-project ile --project-dirty birlikte kullanılamaz.');
            }
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
                    'mode' => $mode,
                    'dry_run' => (bool) $this->option('dry-run'),
                    'apply_price_stock' => (bool) $this->option('apply-price-stock'),
                    'only_clean_stock' => (bool) $this->option('only-clean-stock'),
                    'project_dirty' => (bool) $this->option('project-dirty'),
                    'no_build' => (bool) $this->option('no-build'),
                    'no_project' => (bool) $this->option('no-project'),
                    'force' => (bool) $this->option('force'),
                ]);

                $run = $result['run'];
                $normalizedStatus = $run->normalizedStatus();

                if ($normalizedStatus === ProductDataHubSyncRun::STATUS_FAILED) {
                    $failed++;
                    $this->error(($source->supplier?->name ?? $source->source_name) . ' başarısız: ' . ($run->error_message ?: 'Kaynak okunamadı.'));
                    continue;
                }

                if ($normalizedStatus === ProductDataHubSyncRun::STATUS_COMPLETED_WITH_WARNINGS) {
                    $partial++;
                }

                $processed++;
                $dryRunSuffix = data_get($run->report_payload, 'dry_run') ? ' (dry-run)' : '';
                if ($mode === 'delta') {
                    $deltaSummaryKey = $this->option('apply-price-stock') ? 'delta_apply_summary' : 'delta_summary';
                    $this->info(sprintf(
                        '%s delta %s%s: fiyat %d, stok %d, yeni ürün %d, yeni varyant %d, kaynakta yok %d, identity blok %d.',
                        $source->supplier?->name ?? $source->source_name,
                        $this->option('apply-price-stock') ? 'uygulaması' : 'raporu',
                        $dryRunSuffix,
                        (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.price_changed", 0) + (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.price_and_stock_changed", 0),
                        (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.stock_changed", 0) + (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.price_and_stock_changed", 0),
                        (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.new_product", 0),
                        (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.new_variant", 0),
                        (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.missing_product", 0) + (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.missing_variant", 0),
                        (int) data_get($run->report_payload, "{$deltaSummaryKey}.counts.blocked_identity_missing", 0),
                    ));

                    if ((bool) data_get($run->report_payload, "{$deltaSummaryKey}.flags.suspicious_price_jump")) {
                        $this->warn('Şüpheli fiyat sıçraması tespit edildi.');
                    }

                    if ((bool) data_get($run->report_payload, "{$deltaSummaryKey}.flags.suspicious_feed_drop")) {
                        $this->warn('Şüpheli feed düşüşü tespit edildi.');
                    }

                    if ((bool) data_get($run->report_payload, "{$deltaSummaryKey}.flags.feed_degraded")) {
                        $this->warn('Feed bozulması şüphesi tespit edildi.');
                    }

                    if ((bool) $this->option('only-clean-stock')) {
                        $this->line(sprintf(
                            'Clean stock filtresi: temiz %d, variant structure skip %d, eksik alan skip %d, apply adayı %d.',
                            (int) data_get($run->report_payload, "{$deltaSummaryKey}.clean_stock_candidates", 0),
                            (int) data_get($run->report_payload, "{$deltaSummaryKey}.skipped_variant_structure_changed", 0),
                            (int) data_get($run->report_payload, "{$deltaSummaryKey}.skipped_required_field_missing", 0),
                            (int) data_get($run->report_payload, "{$deltaSummaryKey}.would_apply_clean_stock", 0),
                        ));
                    }

                    if (!(bool) data_get($run->report_payload, "{$deltaSummaryKey}.identity.reliable", true)) {
                        $this->warn('Identity durumu riskli; apply adayı değil.');
                    }

                    $projectionMode = (string) data_get($run->report_payload, "{$deltaSummaryKey}.projection_mode", 'none');
                    if ($projectionMode !== 'none') {
                        $this->line(sprintf(
                            'Dirty projection: aday %d, yansıtılan %d, etkilenen tenant %d.',
                            (int) data_get($run->report_payload, "{$deltaSummaryKey}.dirty_standard_products_detected", 0),
                            (int) data_get($run->report_payload, "{$deltaSummaryKey}.dirty_standard_products_projected", 0),
                            (int) data_get($run->report_payload, "{$deltaSummaryKey}.affected_tenants_count", 0),
                        ));
                    }

                    continue;
                }

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
                $this->comment($mode === 'delta'
                    ? 'Bu işlem delta dry-run olarak çalıştı, veri değiştirilmedi.'
                    : 'Bu işlem dry-run olarak çalıştı, veri değiştirilmedi.');
            }

            if ($heartbeatKey !== null) {
                $meta = array_merge($heartbeatMeta, [
                    'checked_sources' => $checked,
                    'processed_sources' => $processed,
                    'failed_sources' => $failed,
                    'skipped_sources' => $skipped,
                    'partial_sources' => $partial,
                ]);

                if ($failed > 0) {
                    $this->heartbeatService->failure($heartbeatKey, null, $meta + [
                        'error_message' => 'Bir veya daha fazla kaynak senkronizasyonu başarısız oldu.',
                    ]);
                } else {
                    $this->heartbeatService->success($heartbeatKey, $meta);
                }
            }

            return $failed > 0 && $processed === 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            if ($heartbeatKey !== null) {
                $this->heartbeatService->failure($heartbeatKey, $exception, $heartbeatMeta);
            }

            throw $exception;
        }
    }

    private function heartbeatKey(?string $frequency): ?string
    {
        return match ($frequency) {
            'hourly' => 'product_data_hub_hourly',
            'daily' => 'product_data_hub_daily',
            'weekly' => 'product_data_hub_weekly',
            default => null,
        };
    }

    private function heartbeatLabel(?string $frequency): string
    {
        return match ($frequency) {
            'hourly' => 'Product Data Hub Saatlik Senkronizasyonu',
            'daily' => 'Product Data Hub Günlük Senkronizasyonu',
            'weekly' => 'Product Data Hub Haftalık Senkronizasyonu',
            default => 'Product Data Hub Senkronizasyonu',
        };
    }

    private function resolveSources(mixed $sourceId): \Illuminate\Support\Collection
    {
        if ($sourceId !== null) {
            $query = SupplierSource::query()
                ->visibleInProductDataHub()
                ->with(['supplier', 'fieldMappings']);

            $source = is_numeric($sourceId)
                ? $query->find($sourceId)
                : $query
                    ->where(function ($inner) use ($sourceId) {
                        $value = trim((string) $sourceId);
                        $inner->where('source_name', $value)
                            ->orWhereHas('supplier', fn ($supplier) => $supplier
                                ->where('code', $value)
                                ->orWhere('name', $value));
                    })
                    ->first();

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

    private function normalizeMode(mixed $mode): string
    {
        $value = is_string($mode) ? trim($mode) : 'full';

        if ($value === '') {
            $value = 'full';
        }

        if (!in_array($value, ['full', 'delta'], true)) {
            $this->fail('Geçersiz mode değeri. Sadece full veya delta kullanın.');
        }

        return $value;
    }
}
