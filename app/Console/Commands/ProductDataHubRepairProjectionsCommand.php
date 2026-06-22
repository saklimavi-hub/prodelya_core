<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Services\ProductDataHub\VariantHealthScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ProductDataHubRepairProjectionsCommand extends Command
{
    protected $signature = 'product-data-hub:repair-projections
        {--source= : Supplier source ID veya supplier code}
        {--group= : Tek grup kodunu analiz et}
        {--dry-run : Rapor modunda çalış}
        {--only-safe : Sadece güvenli repair adaylarını göster}
        {--apply : Gerçek repair uygula}';

    protected $description = 'Product Data Hub projection mismatch raporunu dry-run olarak üretir.';

    public function handle(VariantHealthScanner $scanner): int
    {
        $tenant = TenantAccount::query()->first();

        if (!$tenant) {
            $this->error('Tenant bulunamadı. Veri değiştirilmedi.');

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            $this->warn('--apply seçildi ancak bu aşamada repair uygulaması kapalı. Veri değiştirilmedi.');
        }

        $this->info('DRY-RUN: Projection repair raporu üretiliyor, veri değiştirilmeyecek.');

        $suppliers = $this->resolveSuppliers();

        if ($suppliers->isEmpty()) {
            $this->error('Analiz edilecek tedarikçi bulunamadı.');

            return self::FAILURE;
        }

        $rows = $suppliers
            ->flatMap(fn (Supplier $supplier) => $scanner->scanSupplier(
                $supplier,
                $tenant,
                10000,
                $this->option('group') ?: null
            ))
            ->values();

        if ($this->option('only-safe')) {
            $rows = $rows->where('repair_candidate', true)->values();
        }

        $summary = $scanner->summarize($rows);

        $this->line('');
        $this->info('Özet');
        $this->table(
            ['Toplam', 'Sağlıklı', 'Review', 'Build eksik', 'Projection eksik', 'Search eksik', 'Kategori bloklu', 'Fiyat bloklu', 'Güvenli repair', 'Manuel kontrol'],
            [[
                $summary['total_groups'],
                $summary['healthy_groups'],
                $summary['review_groups'],
                $summary['build_missing_groups'],
                $summary['projection_missing_groups'],
                $summary['search_visibility_missing_groups'],
                $summary['category_blocked_groups'],
                $summary['price_blocked_groups'],
                $summary['safe_repair_groups'],
                $summary['manual_review_groups'],
            ]]
        );

        $this->line('');
        $this->info('Tedarikçi Bazlı');
        $this->table(
            ['Tedarikçi', 'Toplam', 'Sağlıklı', 'Review', 'Build', 'Projection', 'Search', 'Kategori', 'Fiyat', 'Güvenli', 'Manuel'],
            $rows
                ->groupBy('supplier_name')
                ->map(function (Collection $supplierRows, string $supplierName) use ($scanner) {
                    $summary = $scanner->summarize($supplierRows);

                    return [
                        $supplierName,
                        $summary['total_groups'],
                        $summary['healthy_groups'],
                        $summary['review_groups'],
                        $summary['build_missing_groups'],
                        $summary['projection_missing_groups'],
                        $summary['search_visibility_missing_groups'],
                        $summary['category_blocked_groups'],
                        $summary['price_blocked_groups'],
                        $summary['safe_repair_groups'],
                        $summary['manual_review_groups'],
                    ];
                })
                ->values()
                ->all()
        );

        $reviewRows = $rows
            ->where('status', 'needs_review')
            ->take(25)
            ->map(fn (array $row) => [
                $row['supplier_name'],
                $row['group_code'],
                $row['raw_variant_count'],
                $row['standard_variant_count'],
                $row['tenant_catalog_variant_count'],
                $row['quote_search_variant_count'],
                implode(', ', $row['mismatch_types']),
                $row['repair_candidate'] ? 'Evet' : 'Hayır',
                $row['blocked_reason'] ?: $row['warning_reason'] ?: '-',
            ])
            ->values()
            ->all();

        if ($reviewRows !== []) {
            $this->line('');
            $this->info('İlk Review Örnekleri');
            $this->table(
                ['Tedarikçi', 'Grup', 'Raw', 'Standard', 'Tenant', 'Search', 'Tip', 'Güvenli', 'Sebep'],
                $reviewRows
            );
        }

        $this->line('');
        $this->comment('Bu komut bu aşamada yalnız dry-run rapor üretir. --apply verilse bile veri değiştirilmedi.');

        return self::SUCCESS;
    }

    private function resolveSuppliers(): Collection
    {
        $source = $this->option('source');

        if (blank($source)) {
            return Supplier::query()
                ->whereIn('code', ['YENI-NESIL', 'ETKIN', 'AKDENIZ', 'ILPEN'])
                ->orderBy('name')
                ->get();
        }

        if (is_numeric($source)) {
            $supplierSource = SupplierSource::query()->with('supplier')->find((int) $source);

            return $supplierSource?->supplier ? collect([$supplierSource->supplier]) : collect();
        }

        return Supplier::query()
            ->where('code', $source)
            ->orWhere('name', 'like', '%' . $source . '%')
            ->get();
    }
}
