<?php

namespace App\Console\Commands;

use App\Models\StandardCategory;
use App\Models\SupplierCategoryMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ProductDataHubExportCategoryReviewBatchCommand extends Command
{
    protected $signature = 'product-data-hub:export-category-review-batch
        {--batch=001 : Batch numarası}
        {--limit=50 : Export edilecek maksimum satır}';

    protected $description = 'Kategori eşleme review paketini CSV ve JSON olarak üretir; veri değiştirmez.';

    public function handle(): int
    {
        $batch = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $this->option('batch')) ?: '001';
        $limit = max(1, (int) $this->option('limit'));
        $rows = $this->reviewRows($limit);
        $directory = 'product-data-hub/category-review';
        $csvPath = "{$directory}/category_review_batch_{$batch}.csv";
        $jsonPath = "{$directory}/category_review_batch_{$batch}.json";

        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->put($csvPath, $this->toCsv($rows));
        Storage::disk('local')->put($jsonPath, json_encode([
            'batch' => $batch,
            'generated_at' => now()->toIso8601String(),
            'row_count' => $rows->count(),
            'rows' => $rows->values(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Kategori review batch üretildi; veri değiştirilmedi.');
        $this->line('CSV: ' . Storage::disk('local')->path($csvPath));
        $this->line('JSON: ' . Storage::disk('local')->path($jsonPath));
        $this->line('Satır sayısı: ' . $rows->count());

        return self::SUCCESS;
    }

    private function reviewRows(int $limit): Collection
    {
        return SupplierCategoryMapping::query()
            ->with(['supplier', 'standardCategory'])
            ->whereNotIn('mapping_status', ['approved', 'auto_approved', 'mapped', 'rejected', 'ignored'])
            ->where(function ($query) {
                $query->whereNull('standard_category_id')
                    ->orWhereIn('mapping_status', ['pending', 'needs_review', 'conflict', 'cancelled'])
                    ->orWhere('suggestion_meta->review_required', true);
            })
            ->get()
            ->map(fn (SupplierCategoryMapping $mapping) => $this->row($mapping))
            ->sortBy([
                ['priority_score', 'desc'],
                ['product_count', 'desc'],
                ['supplier_category_name', 'asc'],
            ])
            ->take($limit)
            ->values()
            ->map(function (array $row, int $index) {
                $row['priority'] = $index + 1;
                unset($row['priority_score']);

                return $row;
            });
    }

    private function row(SupplierCategoryMapping $mapping): array
    {
        $classification = $this->classify($mapping);
        $status = $mapping->standard_category_id ? ($mapping->mapping_status ?: 'pending') : 'target_missing';
        $priorityScore = (int) $mapping->product_count;

        if ($status === 'target_missing') {
            $priorityScore += 100000;
        }

        if ($classification['risk_level'] === 'high') {
            $priorityScore += 50000;
        }

        if ((bool) data_get($mapping->suggestion_meta, 'review_required', false)) {
            $priorityScore += 25000;
        }

        return [
            'priority' => 0,
            'supplier_category_mapping_id' => $mapping->id,
            'supplier' => $mapping->supplier?->name ?? 'Tedarikçi',
            'supplier_category_code' => $mapping->supplier_category_code ?: '',
            'supplier_category_name' => $mapping->source_category ?: '',
            'supplier_category_path' => $mapping->supplier_category_path ?: '',
            'product_count' => (int) $mapping->product_count,
            'sample_products' => implode(' | ', collect($mapping->sample_product_names ?? [])->take(3)->all()),
            'current_status' => $status,
            'risk_group' => $classification['risk_group'],
            'suggested_class' => $classification['suggested_class'],
            'suggested_target_category' => $classification['suggested_target_category'],
            'suggested_feature' => $classification['suggested_feature'],
            'suggested_decision' => $classification['suggested_decision'],
            'confidence_score' => (float) ($mapping->confidence_score ?? 0),
            'reason' => $classification['reason'],
            'user_decision' => '',
            'user_note' => '',
            'priority_score' => $priorityScore,
        ];
    }

    private function classify(SupplierCategoryMapping $mapping): array
    {
        $text = $this->normalize(implode(' ', array_filter([
            $mapping->source_category,
            $mapping->supplier_category_path,
            implode(' ', (array) ($mapping->sample_product_names ?? [])),
            data_get($mapping->suggestion_meta, 'special_rule'),
        ])));

        if ($this->containsAny($text, ['masa sumeni', 'masa sümeni', 'sumen', 'sümen'])) {
            if ($this->containsAny($text, ['matbaa', 'takvim', 'gemici', 'haftalik', 'haftalık'])) {
                return $this->classification('Masa Sümeni', 'Manuel inceleme gerekir', $this->path('PRINT-TAKVIM-MASA-SUMENI'), '', 'Eşle', 'high', 'Masa sümeni matbaa/takvim sinyali taşıyor; Matbaa > Takvimler > Masa Sümeni adayı.');
            }

            if ($this->containsAny($text, ['bloknot', 'baskili', 'baskılı', 'kagit', 'kağıt'])) {
                return $this->classification('Masa Sümeni', 'Manuel inceleme gerekir', $this->path('PROMO-KAGIT-URETIM-BASKILI-MASA-SUMENI'), '', 'Eşle', 'high', 'Baskılı/kağıt sümen sinyali var; Kağıt & Üretim Promosyonları adayı.');
            }

            return $this->classification('Masa Sümeni', 'Manuel inceleme gerekir', $this->path('PROMO-OFIS-MASAUSTU-SUMEN'), '', 'Eşle', 'high', 'Hazır/promosyon masa sümeni olabilir; ürün örnekleriyle doğrulanmalı.');
        }

        if ($this->containsAny($text, ['mousepad', 'bardak altligi', 'bardak altlığı'])) {
            if ($this->containsAny($text, ['wireless', 'kablosuz', 'sarj', 'şarj', 'qi', 'power'])) {
                return $this->classification('Mousepad', 'Mevcut kategoriye doğrudan eşlenebilir', $this->path('PROMO-TEKNOLOJI-WIRELESS-MOUSEPAD'), '', 'Eşle', 'medium', 'Wireless/şarj sinyali var; teknoloji altında kalmalı.');
            }

            $target = $this->containsAny($text, ['bardak'])
                ? $this->path('PROMO-KAGIT-URETIM-BARDAK-ALTLIK')
                : $this->path('PROMO-KAGIT-URETIM-KLASIK-MOUSEPAD');

            return $this->classification('Mousepad', 'Manuel inceleme gerekir', $target, '', 'Eşle', 'medium', 'Klasik/baskılı mousepad kağıt üretim tarafında değerlendirilir.');
        }

        if ($this->containsAny($text, ['takvim', 'gemici', 'piramit'])) {
            $code = $this->containsAny($text, ['gemici']) ? 'PRINT-TAKVIM-GEMICI' : ($this->containsAny($text, ['piramit']) ? 'PRINT-TAKVIM-PIRAMIT' : 'PRINT-TAKVIM');

            return $this->classification('Takvim', 'Mevcut kategoriye doğrudan eşlenebilir', $this->path($code), '', 'Eşle', 'medium', 'Takvimler promosyon altında değil Matbaa > Takvimler altında eşlenir.');
        }

        if ($this->containsAny($text, ['kupa', 'seramik', 'porselen', 'cam kupa', 'metal kupa'])) {
            return $this->classification('Kupa / Malzeme', 'Özellik / filtre olmalı', $this->path('PROMO-ICECEK-KUPA'), $this->cupFeature($text), 'Eşle', 'low', 'Kupa malzemesi kategori değil malzeme özelliğidir.');
        }

        if ($this->containsAny($text, ['set kutu', 'set kutusu', 'ambalaj', 'bos kutu', 'boş kutu'])) {
            return $this->classification('Set Kutuları', 'Manuel inceleme gerekir', $this->path('PROMO-AMBALAJ-KUTU-SET'), '', 'Eşle veya Özellik/Filtre Yap', 'high', 'Set kutusu boş ambalaj mı ürünlü set mi kontrol edilmeli; otomatik kabul edilmez.');
        }

        if ($this->containsAny($text, ['hediyelik set', 'vip set', 'kutulu set', 'kalemli set', 'defterli set', 'termoslu set', 'teknolojik set', 'kurumsal set', 'hazir paket', 'hazır paket'])) {
            $feature = $this->giftSetFeature($text);

            return $this->classification('Hediyelik Setler', 'Özellik / filtre olmalı', $this->path('PROMO-HEDIYELIK-SET'), $feature, 'Eşle', 'low', 'Set alt türleri kategori değil Hediyelik Setler altında özellik olarak tutulmalı.');
        }

        if ($this->containsAny($text, ['acacakli magnet', 'açacaklı magnet'])) {
            return $this->classification('Açacak / Magnet', 'Mevcut kategoriye doğrudan eşlenebilir', $this->path('PROMO-AKSESUAR-ACACAKLI-MAGNET'), '', 'Eşle', 'low', 'Açacaklı Magnet, Açacak ve Magnet ile birleştirilmez; ayrı hedefte kalır.');
        }

        if ($this->containsAny($text, ['magnet'])) {
            return $this->classification('Açacak / Magnet', 'Mevcut kategoriye doğrudan eşlenebilir', $this->path('PROMO-AKSESUAR-MAGNET'), '', 'Eşle', 'low', 'Magnetler ayrı hedefte kalır.');
        }

        if ($this->containsAny($text, ['acacak', 'açacak'])) {
            return $this->classification('Açacak / Magnet', 'Mevcut kategoriye doğrudan eşlenebilir', $this->path('PROMO-AKSESUAR-ACACAK'), '', 'Eşle', 'low', 'Açacaklar ayrı hedefte kalır.');
        }

        if ($mapping->standardCategory?->isPermanentBackbone()) {
            return $this->classification('Genel Review', 'Mevcut kategoriye doğrudan eşlenebilir', $mapping->standardCategory->full_path, '', 'Eşle', 'medium', 'Kalıcı kategori hedefi var; operatör onayı bekliyor.');
        }

        if ($this->containsAny($text, ['renk', 'metal', 'plastik', 'seramik', 'porselen', 'ebat', 'olcu', 'ölçü'])) {
            return $this->classification('Genel Review', 'Özellik / filtre olmalı', '', '', 'Özellik/Filtre Yap', 'medium', 'Kategori adı ürün özelliği gibi görünüyor; otomatik Diğer hedefi verilmedi.');
        }

        return $this->classification('Genel Review', 'Yeni standart kategori önerisi gerekebilir', '', '', 'Yeni kategori gerektirir', 'medium', 'Yeni omurgada net hedef bulunamadı; kullanıcı kararı gerekir.');
    }

    private function classification(string $riskGroup, string $suggestedClass, string $target, string $feature, string $decision, string $riskLevel, string $reason): array
    {
        return [
            'risk_group' => $riskGroup,
            'suggested_class' => $suggestedClass,
            'suggested_target_category' => $target,
            'suggested_feature' => $feature,
            'suggested_decision' => $decision,
            'risk_level' => $riskLevel,
            'reason' => $reason,
        ];
    }

    private function path(string $code): string
    {
        return StandardCategory::query()
            ->permanentBackbone()
            ->where('code', $code)
            ->value('path') ?: '';
    }

    private function giftSetFeature(string $text): string
    {
        return match (true) {
            str_contains($text, 'vip') => 'set_tipi: VIP',
            str_contains($text, 'kutulu') => 'kutu_tipi: Kutulu',
            str_contains($text, 'kalemli') => 'set_icerigi: Kalem',
            str_contains($text, 'defterli') => 'set_icerigi: Defter / Ajanda',
            str_contains($text, 'termoslu') => 'set_icerigi: Termos / Kupa',
            str_contains($text, 'teknolojik') => 'set_icerigi: Teknolojik Ürün',
            str_contains($text, 'kurumsal') => 'set_tipi: Kurumsal',
            str_contains($text, 'hazir') || str_contains($text, 'hazır') => 'set_tipi: Hazır Paket',
            default => '',
        };
    }

    private function cupFeature(string $text): string
    {
        return match (true) {
            str_contains($text, 'seramik') => 'malzeme: seramik',
            str_contains($text, 'porselen') => 'malzeme: porselen',
            str_contains($text, 'cam') => 'malzeme: cam',
            str_contains($text, 'metal') => 'malzeme: metal',
            str_contains($text, 'plastik') => 'malzeme: plastik',
            default => '',
        };
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $normalized = strtr($value, [
            'ç' => 'c', 'Ç' => 'c',
            'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's',
            'ü' => 'u', 'Ü' => 'u',
        ]);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($normalized, 'UTF-8')) ?: $normalized);
    }

    private function toCsv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        $columns = [
            'priority',
            'supplier_category_mapping_id',
            'supplier',
            'supplier_category_code',
            'supplier_category_name',
            'supplier_category_path',
            'product_count',
            'sample_products',
            'current_status',
            'risk_group',
            'suggested_class',
            'suggested_target_category',
            'suggested_feature',
            'suggested_decision',
            'confidence_score',
            'reason',
            'user_decision',
            'user_note',
        ];

        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? '', $columns));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
    }
}
