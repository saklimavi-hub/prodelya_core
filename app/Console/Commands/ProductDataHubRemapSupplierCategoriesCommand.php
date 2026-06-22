<?php

namespace App\Console\Commands;

use App\Models\StandardProduct;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Services\ProductDataHub\SupplierCategoryDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductDataHubRemapSupplierCategoriesCommand extends Command
{
    protected $signature = 'product-data-hub:remap-supplier-categories
        {--dry-run : Sadece öneri raporu üretir, veri değiştirmez}
        {--apply : Önerileri mapping kayıtlarına yazar}
        {--source= : Yalnız belirli source id için çalıştır}
        {--only-safe : Apply/dry-run modunda yalnız safe auto approve adaylarını işler}
        {--confirm= : Apply için güvenli onay anahtarı}';

    protected $description = 'Tedarikçi kategorilerini yeni kalıcı kategori omurgasına yeniden eşleme önerilerini üretir.';

    public function handle(SupplierCategoryDiscoveryService $discoveryService): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = !$apply || (bool) $this->option('dry-run');
        $sourceId = $this->option('source');
        $onlySafe = (bool) $this->option('only-safe');

        if ($apply && $this->option('confirm') !== 'SAFE-CATEGORY-MAPPING') {
            $this->error('Apply durduruldu. Güvenli kategori eşleme için --confirm=SAFE-CATEGORY-MAPPING zorunludur.');

            return self::FAILURE;
        }

        if ($apply && !$onlySafe) {
            $this->error('Apply durduruldu. Bu fazda yalnız --only-safe mapping kabulü desteklenir.');

            return self::FAILURE;
        }

        $sources = SupplierSource::query()
            ->visibleInProductDataHub()
            ->with('supplier')
            ->when($sourceId, fn ($query) => $query->whereKey((int) $sourceId))
            ->orderBy('id')
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('Aktif tedarikçi kaynağı bulunamadı.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? 'Dry-run: öneriler hesaplanacak, veri değiştirilmeyecek.'
            : 'Apply: öneriler hesaplanacak ve yalnız safe mapping kayıtları onaylanacak.');

        $results = $sources->map(fn (SupplierSource $source) => $discoveryService->scanSource($source, persist: false));
        $safePreview = $this->safePreviewRows($results);
        $rows = $results->map(fn (array $result) => $this->sourceRow($result))->values();
        $totals = $this->totals($rows);

        $this->table(
            ['Tedarikçi', 'Source', 'Toplam', 'Önerilen', 'Yüksek güven', 'Safe', 'Kontrol', 'Hedef yok', 'Özel kural'],
            $rows->map(fn (array $row) => [
                $row['supplier'],
                $row['source_id'],
                $row['total'],
                $row['mapped'],
                $row['high'],
                $row['safe'],
                $row['review'],
                $row['no_target'],
                $row['special'],
            ])->all()
        );

        $this->line("Toplam tedarikçi kategori: {$totals['total']}");
        $this->line("Önerilen: {$totals['mapped']}");
        $this->line("Yüksek güvenli: {$totals['high']}");
        $this->line("Güvenli toplu kabul adayı: {$totals['safe']}");
        $this->line("Kontrol gereken: {$totals['review']}");
        $this->line("Hedef bulunamayan: {$totals['no_target']}");
        $this->line("Özel kural uygulanan: {$totals['special']}");
        $this->printNoTargetClassification($this->classifyNoTargetRows($results));

        if ($onlySafe) {
            $this->printSafePreview($safePreview);
        }

        if ($apply && $onlySafe) {
            if ($safePreview['blocked_count'] > 0) {
                $this->error('Apply durduruldu. Safe preview içinde arşiv hedefli veya review_required riskli satır var.');

                return self::FAILURE;
            }

            $approved = $discoveryService->applySafeCategorySuggestions($results);
            $this->info("Safe toplu kabul tamamlandı. Kabul: {$approved['approved']}, alias: {$approved['alias_created']}, atlanan: {$approved['skipped']}.");
        }

        $this->line('Projection refresh hazırlık raporu:');
        $this->line('Kategori bekleyen standard product: ' . StandardProduct::query()->whereNull('standard_category_id')->count());
        $this->line('Kategori bekleyen tenant catalog product: ' . TenantCatalogProduct::query()->whereNull('standard_category_id')->count());
        $this->line('Bekleyen supplier mapping: ' . SupplierCategoryMapping::query()->whereIn('mapping_status', ['pending', 'needs_review', 'conflict'])->count());
        $this->line('Ürün/projection snapshot refresh bu komutta otomatik çalıştırılmadı.');

        return self::SUCCESS;
    }

    private function safePreviewRows(Collection $results): array
    {
        $rows = $results
            ->flatMap(fn (array $result) => collect($result['categories'] ?? [])->map(fn (array $category) => [
                'supplier' => $result['source']->supplier?->name ?? 'Tedarikçi',
                'target' => $category['target_category'] ?? 'Hedef yok',
                'decision_type' => $category['decision_type'] ?? 'map',
                'review_required' => (bool) data_get($category, 'suggestion_meta.review_required', false),
                'is_archived' => false,
                'source_category' => $category['source_category'] ?? '',
                'safe' => (bool) data_get($category, 'suggestion_meta.safe_auto_approve', false),
                'confidence' => (float) ($category['confidence_score'] ?? 0),
                'target_id' => $category['standard_category_id'] ?? null,
            ]))->filter(fn (array $row) => ($row['safe'] ?? false)
                && ($row['confidence'] ?? 0) >= 95
                && filled($row['target_id'])
                && in_array($row['decision_type'], ['map', 'alias'], true)
                && !($row['review_required'] ?? false)
            )->values();

        $blocked = $rows->filter(fn (array $row) => ($row['review_required'] ?? false) || ($row['is_archived'] ?? false));

        return [
            'count' => $rows->count(),
            'blocked_count' => $blocked->count(),
            'by_supplier' => $rows->countBy('supplier')->all(),
            'by_target' => $rows->countBy('target')->sortDesc()->take(12)->all(),
            'by_decision' => $rows->countBy('decision_type')->all(),
            'has_special_rule' => $rows->filter(fn (array $row) => Str::contains((string) ($row['source_category'] ?? ''), ['Set Kut', 'Mousepad', 'Sümen']))->count(),
        ];
    }

    private function printSafePreview(array $preview): void
    {
        $this->line('Safe apply preview:');
        $this->line("Uygulanacak mapping: {$preview['count']}");
        $this->line("Riskli/arşiv hedefli satır: {$preview['blocked_count']}");
        $this->line('Decision type dağılımı: ' . $this->formatDistribution($preview['by_decision']));
        $this->line('Supplier dağılımı: ' . $this->formatDistribution($preview['by_supplier']));
        $this->line('Hedef kategori dağılımı: ' . $this->formatDistribution($preview['by_target']));
        $this->line('Özel kural safe adayı: ' . $preview['has_special_rule']);
        $this->line('Mapping log apply sırasında oluşturulacak; ürün/projection refresh otomatik çalışmayacak.');
    }

    private function classifyNoTargetRows(Collection $results): array
    {
        $classification = [
            'alias_candidate' => 0,
            'feature_candidate' => 0,
            'new_category_candidate' => 0,
            'reject_candidate' => 0,
            'manual_review' => 0,
        ];

        $results
            ->flatMap(fn (array $result) => $result['categories'] ?? [])
            ->filter(fn (array $category) => blank($category['standard_category_id'] ?? null))
            ->each(function (array $category) use (&$classification) {
                $text = Str::lower(Str::ascii(trim(implode(' ', array_filter([
                    $category['source_category'] ?? '',
                    $category['supplier_category_path'] ?? '',
                    implode(' ', (array) ($category['sample_product_names'] ?? [])),
                ])))));

                if (Str::contains($text, ['test', 'demo', 'tmp', 'sil', 'cop', 'çöp'])) {
                    $classification['reject_candidate']++;
                } elseif (Str::contains($text, ['metal', 'plastik', 'cam', 'seramik', 'porselen', 'bambu', 'ahsap', 'ahşap', 'renk', 'ebat', 'boyut', 'ml', 'mah', 'gb'])) {
                    $classification['feature_candidate']++;
                } elseif (Str::contains($text, ['benzer', 'alternatif', 'diger', 'diğer', 'karma', 'karisik', 'karışık'])) {
                    $classification['alias_candidate']++;
                } elseif ((int) ($category['product_count'] ?? 0) >= 5) {
                    $classification['new_category_candidate']++;
                } else {
                    $classification['manual_review']++;
                }
            });

        return $classification;
    }

    private function printNoTargetClassification(array $classification): void
    {
        $this->line('Hedef bulunamayan sınıflandırma: '
            . "alias adayı {$classification['alias_candidate']}, "
            . "özellik/filtre adayı {$classification['feature_candidate']}, "
            . "yeni kategori adayı {$classification['new_category_candidate']}, "
            . "reddedilebilir {$classification['reject_candidate']}, "
            . "manuel review {$classification['manual_review']}.");
    }

    private function formatDistribution(array $distribution): string
    {
        if ($distribution === []) {
            return '-';
        }

        return collect($distribution)
            ->map(fn ($count, $key) => "{$key}: {$count}")
            ->implode(', ');
    }

    private function sourceRow(array $result): array
    {
        $summary = $result['summary'] ?? [];
        $source = $result['source'];

        return [
            'supplier' => $source->supplier?->name ?? 'Tedarikçi',
            'source_id' => $source->id,
            'total' => (int) ($summary['category_count'] ?? 0),
            'mapped' => (int) ($summary['mapped_count'] ?? 0),
            'high' => (int) ($summary['high_confidence_count'] ?? 0),
            'safe' => (int) ($summary['safe_auto_approve_count'] ?? 0),
            'review' => (int) (($summary['review_count'] ?? 0) + ($summary['review_required_count'] ?? 0)),
            'no_target' => (int) ($summary['no_target_count'] ?? 0),
            'special' => (int) ($summary['special_rule_count'] ?? 0),
        ];
    }

    private function totals(Collection $rows): array
    {
        return [
            'total' => (int) $rows->sum('total'),
            'mapped' => (int) $rows->sum('mapped'),
            'high' => (int) $rows->sum('high'),
            'safe' => (int) $rows->sum('safe'),
            'review' => (int) $rows->sum('review'),
            'no_target' => (int) $rows->sum('no_target'),
            'special' => (int) $rows->sum('special'),
        ];
    }
}
