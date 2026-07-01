<?php

namespace App\Console\Commands;

use App\Services\ProductDataHub\ProductHubQuoteVisibilityDiagnosticService;
use Illuminate\Console\Command;

class ProductDataHubQuoteVisibilityAuditCommand extends Command
{
    protected $signature = 'product-data-hub:quote-visibility-audit
        {--tenant= : Abone Firma slug / subdomain / ad}
        {--tenant-id= : Abone Firma ID}
        {--supplier= : Tedarikçi kodu / adı}
        {--supplier-id= : Tedarikçi ID}
        {--source= : Kaynak adı}
        {--source-id= : Kaynak ID}
        {--sample=50 : Örnek görünmeyen kayıt limiti}
        {--dry-run : Sadece rapor üretir, veri yazmaz}';

    protected $description = 'Product Hub teklif görünürlüğü zincirini read-only olarak denetler.';

    public function __construct(
        private readonly ProductHubQuoteVisibilityDiagnosticService $diagnosticService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $audit = $this->diagnosticService->audit([
            'tenant' => $this->option('tenant'),
            'tenant_id' => $this->option('tenant-id'),
            'supplier' => $this->option('supplier'),
            'supplier_id' => $this->option('supplier-id'),
            'source' => $this->option('source'),
            'source_id' => $this->option('source-id'),
            'sample' => $this->option('sample'),
        ]);

        $tenant = $audit['tenant'];
        $summary = $audit['summary'];

        $this->line('Abone Firma: ' . $tenant->name);
        $this->line('İncelenen kaynak: ' . $summary['source_count']);
        $this->line('Standart ürün: ' . $summary['standard_products']);
        $this->line('Standart varyant: ' . $summary['standard_variants']);
        $this->line('Tenant katalog ürün: ' . $summary['tenant_catalog_products']);
        $this->line('Tenant katalog varyant: ' . $summary['tenant_catalog_variants']);
        $this->line('Teklifte görünür ürün: ' . $summary['quote_visible_products']);
        $this->line('Teklifte görünür varyant: ' . $summary['quote_visible_variants']);
        $this->line('Görünmeyen kayıt: ' . $summary['invisible_items']);

        foreach ($audit['sources'] as $sourceAudit) {
            $this->newLine();
            $this->info('Tedarikçi: ' . ($sourceAudit['supplier_name'] ?: '-'));
            $this->line('Kaynak: ' . ($sourceAudit['source_name'] ?: '-'));
            $this->line('Access: '
                . ($sourceAudit['access']['exists'] ? 'var' : 'yok')
                . ', aktif=' . ($sourceAudit['access']['is_active'] ? '1' : '0')
                . ', katalog=' . ($sourceAudit['access']['visible_in_catalog'] ? '1' : '0')
                . ', teklif=' . ($sourceAudit['access']['can_use_in_quotes'] ? '1' : '0'));
            $this->line('Standard ürün/varyant: ' . $sourceAudit['standard_product_count'] . ' / ' . $sourceAudit['standard_variant_count']);
            $this->line('Katalog ürün/varyant: ' . $sourceAudit['tenant_catalog_product_count'] . ' / ' . $sourceAudit['tenant_catalog_variant_count']);
            $this->line('Teklif görünür ürün/varyant: ' . $sourceAudit['quote_visible_product_count'] . ' / ' . $sourceAudit['quote_visible_variant_count']);
            $this->line('Kategori bekleyen: ' . $sourceAudit['category_pending_count']);
            $this->line('Fiyat eksik: ' . $sourceAudit['missing_price_count']);
            $this->line('Stok 0: ' . $sourceAudit['stock_zero_count']);
            $this->line('Projection eksik: ' . $sourceAudit['projection_missing_count']);
            $this->line('Projection stale: ' . $sourceAudit['projection_stale_count']);
            $this->line('Parent/varyant görünürlük problemi: ' . $sourceAudit['parent_variant_visibility_problem_count']);

            if (!empty($sourceAudit['reason_counts'])) {
                $this->line('Görünmeme nedenleri:');
                foreach ($sourceAudit['reason_counts'] as $reason => $count) {
                    $this->line(' - ' . $reason . ': ' . $count);
                }
            }

            foreach ($sourceAudit['samples'] as $sample) {
                $label = ($sample['type'] ?? 'product') === 'variant'
                    ? (($sample['variant_code'] ?? '-') . ' / ' . ($sample['variant_name'] ?? '-'))
                    : (($sample['product_code'] ?? '-') . ' / ' . ($sample['product_name'] ?? '-'));
                $this->line('* ' . $label . ' -> ' . ($sample['message'] ?? '-'));
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry-run: Veri yazılmadı.');
        }

        return self::SUCCESS;
    }
}
