<?php

namespace App\Console\Commands;

use App\Models\CategoryAlias;
use App\Models\CategoryAttributeRule;
use App\Models\CategoryCleanupDecision;
use App\Models\CategoryTreeDraft;
use App\Models\CategoryTwinView;
use App\Models\ProductAttributeDefinition;
use App\Models\StandardCategory;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use App\Models\TenantCatalogProduct;
use App\Models\StandardProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProductDataHubResetStandardCategoriesCommand extends Command
{
    protected $signature = 'product-data-hub:reset-standard-categories
        {--dry-run : Sadece rapor üretir, veritabanı verisini değiştirmez}
        {--apply : Kalıcı kategori resetini uygular}
        {--confirm= : Apply için zorunlu onay metni}';

    protected $description = 'Prodelya kalıcı standart kategori omurgasını güvenli backup, dry-run ve confirm kilidiyle sıfırdan oluşturur.';

    private const CONFIRM_TEXT = 'KALICI-KATEGORI-RESET';

    private const RESET_NOTE = 'Standart kategori ağacı sıfırlandı; yeni ağaca yeniden eşleme bekleniyor.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || !$apply;

        if ($apply && $this->option('confirm') !== self::CONFIRM_TEXT) {
            $this->error('Apply için --confirm=KALICI-KATEGORI-RESET zorunludur. Veri değiştirilmedi.');

            return self::FAILURE;
        }

        $backupPath = $this->createBackup();
        if (!$backupPath) {
            $this->error('Backup alınamadı. İşlem durduruldu.');

            return self::FAILURE;
        }

        $report = $this->buildReport($backupPath);

        if ($dryRun) {
            $this->printReport($report, 'DRY-RUN: Veri değiştirilmeyecek.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use (&$report): void {
                $report['apply'] = $this->applyReset();
            });
        } catch (Throwable $exception) {
            $this->error('Kategori reset uygulanamadı: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report, 'APPLY: Kalıcı kategori omurgası oluşturuldu.');

        return self::SUCCESS;
    }

    private function buildReport(string $backupPath): array
    {
        $categoryCount = StandardCategory::query()->count();
        $rootCount = StandardCategory::query()->whereNull('parent_id')->count();
        $mappingCount = SupplierCategoryMapping::query()->count();
        $standardProductCategoryCount = $this->tableHasColumn('standard_products', 'standard_category_id')
            ? StandardProduct::query()->whereNotNull('standard_category_id')->count()
            : 0;
        $tenantCatalogCategoryCount = $this->tableHasColumn('tenant_catalog_products', 'standard_category_id')
            ? TenantCatalogProduct::query()->whereNotNull('standard_category_id')->count()
            : 0;

        return [
            'backup_path' => $backupPath,
            'existing_categories' => $categoryCount,
            'existing_roots' => $rootCount,
            'new_categories' => count($this->permanentTreeFlat()),
            'new_roots' => 2,
            'promotion_categories' => collect($this->permanentTreeFlat())->where('family', 'promotion')->count(),
            'print_categories' => collect($this->permanentTreeFlat())->where('family', 'print')->count(),
            'mappings_to_reset' => $mappingCount,
            'standard_products_to_pending' => $standardProductCategoryCount,
            'tenant_catalog_products_to_pending' => $tenantCatalogCategoryCount,
            'feature_templates' => count($this->featureTemplates()),
            'risks' => [
                'Mevcut tedarikçi kategori eşlemeleri yeniden eşleme kuyruğuna alınacak.',
                'Ürün ve tenant catalog kategori bağlantıları Kategori Bekleyen Ürünler kategorisine alınacak.',
                'Eski standart kategoriler hard delete edilmeden arşivlenip pasifleştirilecek.',
            ],
        ];
    }

    private function applyReset(): array
    {
        $archiveStamp = now()->format('YmdHis');
        $existingCategoryIds = StandardCategory::query()->pluck('id');

        $resetMappings = $this->resetMappings();
        $archivedAliases = $this->archiveAliases();
        $archivedTwinViews = $this->archiveTwinViews();
        $archivedCategories = $this->archiveExistingCategories($existingCategoryIds, $archiveStamp);
        $createdCategories = $this->createPermanentCategories();
        $pendingCategory = StandardCategory::query()
            ->where('code', 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN')
            ->firstOrFail();

        $standardProductsPending = $this->moveStandardProductsToPending($pendingCategory);
        $tenantProductsPending = $this->moveTenantCatalogProductsToPending($pendingCategory);
        $featureRules = $this->createFeatureTemplateRules($createdCategories);

        return [
            'reset_mappings' => $resetMappings,
            'archived_aliases' => $archivedAliases,
            'archived_twin_views' => $archivedTwinViews,
            'archived_categories' => $archivedCategories,
            'created_categories' => count($createdCategories),
            'standard_products_pending' => $standardProductsPending,
            'tenant_catalog_products_pending' => $tenantProductsPending,
            'feature_rules' => $featureRules,
            'tenant_visible_categories' => count($createdCategories),
        ];
    }

    private function resetMappings(): int
    {
        $count = 0;

        SupplierCategoryMapping::query()
            ->orderBy('id')
            ->chunkById(200, function (Collection $mappings) use (&$count): void {
                foreach ($mappings as $mapping) {
                    $oldCategoryId = $mapping->standard_category_id;

                    SupplierCategoryMappingLog::query()->create([
                        'mapping_id' => $mapping->id,
                        'old_standard_category_id' => $oldCategoryId,
                        'new_standard_category_id' => null,
                        'action' => 'category_tree_reset',
                        'reason' => self::RESET_NOTE,
                        'changed_by' => null,
                    ]);

                    $mapping->forceFill([
                        'standard_category_id' => null,
                        'mapping_status' => 'pending',
                        'decision_type' => 'review',
                        'decision_note' => self::RESET_NOTE,
                        'reviewed_by' => null,
                        'reviewed_at' => null,
                    ])->save();

                    $count++;
                }
            });

        return $count;
    }

    private function archiveAliases(): int
    {
        if (!Schema::hasTable('category_aliases')) {
            return 0;
        }

        return CategoryAlias::query()->update(['is_active' => false]);
    }

    private function archiveTwinViews(): int
    {
        if (!Schema::hasTable('category_twin_views')) {
            return 0;
        }

        return CategoryTwinView::query()->update(['is_active' => false]);
    }

    private function archiveExistingCategories(Collection $categoryIds, string $archiveStamp): int
    {
        $archived = 0;

        StandardCategory::query()
            ->whereIn('id', $categoryIds)
            ->orderBy('id')
            ->chunkById(200, function (Collection $categories) use (&$archived, $archiveStamp): void {
                foreach ($categories as $category) {
                    $meta = $category->meta ?? [];
                    $meta['archived_by_category_reset'] = true;
                    $meta['archived_at'] = now()->toIso8601String();
                    $meta['old_code'] = $category->code;
                    $meta['old_path'] = $category->path;

                    $category->forceFill([
                        'code' => 'ARCHIVED-' . $archiveStamp . '-' . $category->id . '-' . Str::limit($category->code, 140, ''),
                        'is_active' => false,
                        'visible_in_catalog' => false,
                        'requires_mapping' => false,
                        'duplicate_status' => 'archived',
                        'meta' => $meta,
                    ])->save();

                    $archived++;
                }
            });

        return $archived;
    }

    private function createPermanentCategories(): array
    {
        $created = [];

        foreach ($this->permanentTree() as $root) {
            $this->createCategoryNode($root, null, 0, $root['family'], $created);
        }

        return $created;
    }

    private function createCategoryNode(array $node, ?StandardCategory $parent, int $depth, string $family, array &$created): StandardCategory
    {
        $category = StandardCategory::query()->create([
            'parent_id' => $parent?->id,
            'code' => $node['code'],
            'name' => $node['name'],
            'slug' => StandardCategory::generateSlug($node['name']),
            'product_family' => $family,
            'description' => $node['description'] ?? null,
            'sort_order' => $node['sort_order'],
            'depth' => $depth,
            'path' => $parent ? $parent->path . ' / ' . $node['name'] : $node['name'],
            'is_active' => true,
            'visible_in_catalog' => true,
            'requires_mapping' => false,
            'duplicate_status' => 'canonical',
            'meta' => [
                'is_system' => true,
                'supplier_dependent' => false,
                'tenant_visible' => true,
                'permanent_category_backbone' => true,
                'created_by_reset_command' => true,
            ],
        ]);

        $created[$category->code] = $category;

        foreach (($node['children'] ?? []) as $index => $child) {
            $child['sort_order'] = $child['sort_order'] ?? (($index + 1) * 10);
            $this->createCategoryNode($child, $category, $depth + 1, $family, $created);
        }

        return $category;
    }

    private function moveStandardProductsToPending(StandardCategory $pendingCategory): int
    {
        if (!$this->tableHasColumn('standard_products', 'standard_category_id')) {
            return 0;
        }

        return StandardProduct::query()
            ->whereNotNull('standard_category_id')
            ->update(['standard_category_id' => $pendingCategory->id]);
    }

    private function moveTenantCatalogProductsToPending(StandardCategory $pendingCategory): int
    {
        if (!$this->tableHasColumn('tenant_catalog_products', 'standard_category_id')) {
            return 0;
        }

        return TenantCatalogProduct::query()
            ->whereNotNull('standard_category_id')
            ->update(['standard_category_id' => $pendingCategory->id]);
    }

    private function createFeatureTemplateRules(array $categoriesByCode): int
    {
        $applied = 0;

        foreach ($this->featureTemplates() as $templateKey => $template) {
            foreach ($template['category_codes'] as $categoryCode) {
                $category = $categoriesByCode[$categoryCode] ?? null;
                if (!$category) {
                    continue;
                }

                foreach ($template['attributes'] as $index => $attribute) {
                    $definition = ProductAttributeDefinition::query()->updateOrCreate(
                        ['code' => $attribute['code']],
                        [
                            'name' => $attribute['name'],
                            'type' => $attribute['type'],
                            'unit' => $attribute['unit'] ?? null,
                            'is_filterable' => true,
                            'is_required' => false,
                            'sort_order' => ($index + 1) * 10,
                            'is_active' => true,
                            'meta' => [
                                'template_key' => $templateKey,
                                'template_label' => $template['label'],
                                'show_in_web_filter' => true,
                                'show_in_tenant_catalog' => true,
                                'show_in_export' => true,
                                'use_in_import_mapping' => true,
                                'use_in_suggestion_engine' => true,
                                'keep_out_of_quote_form' => true,
                            ],
                        ]
                    );

                    CategoryAttributeRule::query()->updateOrCreate(
                        [
                            'standard_category_id' => $category->id,
                            'product_attribute_definition_id' => $definition->id,
                        ],
                        [
                            'is_required' => false,
                            'is_filterable' => true,
                            'visible_in_catalog' => true,
                            'sort_order' => ($index + 1) * 10,
                            'meta' => [
                                'template_key' => $templateKey,
                                'template_label' => $template['label'],
                                'show_in_web_filter' => true,
                                'show_in_tenant_catalog' => true,
                                'show_in_export' => true,
                                'use_in_import_mapping' => true,
                                'use_in_suggestion_engine' => true,
                                'keep_out_of_quote_form' => true,
                            ],
                        ]
                    );

                    $applied++;
                }
            }
        }

        return $applied;
    }

    private function createBackup(): ?string
    {
        $path = 'product-data-hub/category-backups/' . now()->format('Ymd_His');

        try {
            $this->exportTable($path, 'standard_categories', 'standard_categories_before_reset');
            $this->exportTable($path, 'supplier_category_mappings', 'supplier_category_mappings_before_reset');
            $this->exportTable($path, 'category_aliases', 'category_aliases_before_reset');
            $this->exportTable($path, 'category_twin_views', 'category_twin_views_before_reset');
            $this->exportTable($path, 'category_tree_drafts', 'category_tree_drafts_before_reset', csv: false);
            $this->exportTable($path, 'category_cleanup_decisions', 'category_cleanup_decisions_before_reset', csv: false);

            Storage::disk('local')->put($path . '/reset_report.json', json_encode($this->backupSummary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            return null;
        }

        return 'storage/app/' . $path;
    }

    private function exportTable(string $path, string $table, string $name, bool $csv = true): void
    {
        $rows = Schema::hasTable($table) ? DB::table($table)->get() : collect();

        Storage::disk('local')->put(
            $path . '/' . $name . '.json',
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        if (!$csv) {
            return;
        }

        $columns = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        $csvLines = [implode(',', $columns)];

        foreach ($rows as $row) {
            $array = (array) $row;
            $csvLines[] = implode(',', array_map(fn ($column) => $this->csvValue($array[$column] ?? ''), $columns));
        }

        Storage::disk('local')->put($path . '/' . $name . '.csv', implode(PHP_EOL, $csvLines));
    }

    private function csvValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $value = (string) $value;

        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function backupSummary(): array
    {
        return [
            'standard_categories' => StandardCategory::query()->count(),
            'root_categories' => StandardCategory::query()->whereNull('parent_id')->count(),
            'products_with_category' => $this->tableHasColumn('standard_products', 'standard_category_id')
                ? StandardProduct::query()->whereNotNull('standard_category_id')->count()
                : 0,
            'mappings_with_category' => SupplierCategoryMapping::query()->whereNotNull('standard_category_id')->count(),
            'tenant_catalog_with_category' => $this->tableHasColumn('tenant_catalog_products', 'standard_category_id')
                ? TenantCatalogProduct::query()->whereNotNull('standard_category_id')->count()
                : 0,
            'empty_categories' => $this->emptyCategoryCount(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function emptyCategoryCount(): int
    {
        return StandardCategory::query()
            ->withCount(['children', 'standardProducts', 'supplierCategoryMappings'])
            ->get()
            ->filter(fn (StandardCategory $category) => $category->children_count === 0
                && $category->standard_products_count === 0
                && $category->supplier_category_mappings_count === 0)
            ->count();
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function printReport(array $report, string $title): void
    {
        $this->info($title);
        $this->line('Backup yolu: ' . $report['backup_path']);
        $this->table(
            ['Mevcut kategori', 'Yeni kategori', 'Mapping reset', 'Ürün bekleyen', 'Tenant ürün bekleyen', 'Özellik şablonu'],
            [[
                $report['existing_categories'],
                $report['new_categories'],
                $report['mappings_to_reset'],
                $report['standard_products_to_pending'],
                $report['tenant_catalog_products_to_pending'],
                $report['feature_templates'],
            ]]
        );

        $this->table(
            ['Root', 'Promosyon', 'Matbaa'],
            [[
                $report['new_roots'],
                $report['promotion_categories'],
                $report['print_categories'],
            ]]
        );

        if (isset($report['apply'])) {
            $this->table(
                ['Arşiv kategori', 'Oluşan kategori', 'Reset mapping', 'Log/şablon kuralı', 'Tenant görünür'],
                [[
                    $report['apply']['archived_categories'],
                    $report['apply']['created_categories'],
                    $report['apply']['reset_mappings'],
                    $report['apply']['feature_rules'],
                    $report['apply']['tenant_visible_categories'],
                ]]
            );
        }

        foreach ($report['risks'] as $risk) {
            $this->warn($risk);
        }
    }

    private function permanentTreeFlat(): array
    {
        $rows = [];

        $walk = function (array $nodes, string $family) use (&$walk, &$rows): void {
            foreach ($nodes as $node) {
                $rows[] = [
                    'code' => $node['code'],
                    'name' => $node['name'],
                    'family' => $family,
                ];

                $walk($node['children'] ?? [], $family);
            }
        };

        foreach ($this->permanentTree() as $root) {
            $walk([$root], $root['family']);
        }

        return $rows;
    }

    private function permanentTree(): array
    {
        return [
            [
                'code' => 'PROMO',
                'name' => 'Promosyon Ürünleri',
                'family' => 'promotion',
                'sort_order' => 10,
                'children' => [
                    $this->node('PROMO-KALEM', 'Kalemler', 10, [
                        ['PROMO-KALEM-PLASTIK', 'Plastik Kalemler'],
                        ['PROMO-KALEM-METAL', 'Metal Kalemler'],
                        ['PROMO-KALEM-ROLLER-JEL-TUKENMEZ', 'Roller / Jel / Tükenmez Kalemler'],
                        ['PROMO-KALEM-KURSUN-BOYA', 'Kurşun ve Boya Kalemleri'],
                        ['PROMO-KALEM-DOKUNMATIK', 'Dokunmatik Kalemler'],
                        ['PROMO-KALEM-DOGA-DOSTU', 'Doğa Dostu Kalemler'],
                        ['PROMO-KALEM-SET', 'Kalem Setleri'],
                        ['PROMO-KALEM-DIGER', 'Diğer Kalemler'],
                    ]),
                    $this->node('PROMO-DEFTER-AJANDA', 'Defter & Ajandalar', 20, [
                        ['PROMO-DEFTER-AJANDA-TARIHLI', 'Tarihli Ajandalar'],
                        ['PROMO-DEFTER-AJANDA-TARIHSIZ', 'Tarihsiz Defterler'],
                        ['PROMO-DEFTER-AJANDA-SPIRALLI', 'Spiralli Defterler'],
                        ['PROMO-DEFTER-AJANDA-ORGANIZER', 'Organizerler'],
                        ['PROMO-DEFTER-AJANDA-SEKRETERLIK', 'Sekreterlikler'],
                        ['PROMO-DEFTER-AJANDA-AKSESUAR', 'Ajanda Aksesuarları'],
                        ['PROMO-DEFTER-AJANDA-DIGER', 'Diğer Defter & Ajanda Ürünleri'],
                    ]),
                    $this->node('PROMO-TEKNOLOJI', 'Teknolojik Ürünler', 30, [
                        ['PROMO-TEKNOLOJI-USB', 'USB Bellekler'],
                        ['PROMO-TEKNOLOJI-POWERBANK', 'Powerbank'],
                        ['PROMO-TEKNOLOJI-BLUETOOTH-HOPARLOR', 'Bluetooth Hoparlörler'],
                        ['PROMO-TEKNOLOJI-BLUETOOTH-KULAKLIK', 'Bluetooth Kulaklıklar'],
                        ['PROMO-TEKNOLOJI-KABLOSUZ-SARJ', 'Kablosuz Şarj Ürünleri'],
                        ['PROMO-TEKNOLOJI-WIRELESS-MOUSEPAD', 'Wireless Mousepadler'],
                        ['PROMO-TEKNOLOJI-SARJ-KABLO', 'Şarj Kabloları'],
                        ['PROMO-TEKNOLOJI-TELEFON-STAND', 'Telefon Standları'],
                        ['PROMO-TEKNOLOJI-LCD-TABLET', 'LCD Tabletler'],
                        ['PROMO-TEKNOLOJI-HESAP-MAKINESI', 'Hesap Makineleri'],
                        ['PROMO-TEKNOLOJI-DIGER', 'Diğer Teknolojik Ürünler'],
                    ]),
                    $this->node('PROMO-ICECEK', 'İçecek Ürünleri', 40, [
                        ['PROMO-ICECEK-TERMOS', 'Termoslar'],
                        ['PROMO-ICECEK-MATARA', 'Mataralar'],
                        ['PROMO-ICECEK-KUPA', 'Kupalar'],
                        ['PROMO-ICECEK-CAM', 'Cam Ürünler'],
                        ['PROMO-ICECEK-FRENCH-PRESS', 'French Press / Özel İçecek Ürünleri'],
                        ['PROMO-ICECEK-DIGER', 'Diğer İçecek Ürünleri'],
                    ]),
                    $this->node('PROMO-CANTA-TASIMA', 'Çanta & Taşıma Ürünleri', 50, [
                        ['PROMO-CANTA-TASIMA-BEZ', 'Bez Çantalar'],
                        ['PROMO-CANTA-TASIMA-KARTON', 'Karton Çantalar'],
                        ['PROMO-CANTA-TASIMA-SIRT', 'Sırt Çantaları'],
                        ['PROMO-CANTA-TASIMA-LAPTOP', 'Laptop Çantaları'],
                        ['PROMO-CANTA-TASIMA-EVRAK', 'Evrak Çantaları'],
                        ['PROMO-CANTA-TASIMA-SPOR-SEYAHAT', 'Spor / Seyahat Çantaları'],
                        ['PROMO-CANTA-TASIMA-CUZDAN', 'Cüzdanlar'],
                        ['PROMO-CANTA-TASIMA-DIGER', 'Diğer Çanta & Taşıma Ürünleri'],
                    ]),
                    $this->node('PROMO-OFIS-MASAUSTU', 'Ofis & Masaüstü Ürünleri', 60, [
                        ['PROMO-OFIS-MASAUSTU-MASA-SET', 'Masa Setleri'],
                        ['PROMO-OFIS-MASAUSTU-SUMEN', 'Masa Sümenleri'],
                        ['PROMO-OFIS-MASAUSTU-ORGANIZER', 'Masaüstü Organizerler'],
                        ['PROMO-OFIS-MASAUSTU-KALEMLIK', 'Kalemlikler'],
                        ['PROMO-OFIS-MASAUSTU-KARTVIZITLIK', 'Kartvizitlikler'],
                        ['PROMO-OFIS-MASAUSTU-ISIMLIK', 'İsimlik / Metal İsim Plakaları'],
                        ['PROMO-OFIS-MASAUSTU-CERCEVE', 'Resim Çerçeveleri'],
                        ['PROMO-OFIS-MASAUSTU-DIGER', 'Diğer Ofis & Masaüstü Ürünleri'],
                    ]),
                    $this->node('PROMO-KAGIT-URETIM', 'Kağıt & Üretim Promosyonları', 70, [
                        ['PROMO-KAGIT-URETIM-BLOKNOT', 'Bloknotlar'],
                        ['PROMO-KAGIT-URETIM-SEKRETER-BLOKNOT', 'Sekreter Bloknotları'],
                        ['PROMO-KAGIT-URETIM-YAPISKANLI-NOT', 'Yapışkanlı Notluklar'],
                        ['PROMO-KAGIT-URETIM-KUP-BLOKNOT', 'Küp Bloknotlar'],
                        ['PROMO-KAGIT-URETIM-KAPAKLI-OZEL-BLOKNOT', 'Kapaklı / Özel Bloknotlar'],
                        ['PROMO-KAGIT-URETIM-BASKILI-MASA-SUMENI', 'Baskılı Masa Sümenleri'],
                        ['PROMO-KAGIT-URETIM-KLASIK-MOUSEPAD', 'Klasik Mousepadler'],
                        ['PROMO-KAGIT-URETIM-BARDAK-ALTLIK', 'Bardak Altlıkları'],
                        ['PROMO-KAGIT-URETIM-ETIKET-STICKER', 'Promosyon Etiket / Sticker'],
                        ['PROMO-KAGIT-URETIM-DIGER', 'Diğer Kağıt & Üretim Promosyonları'],
                    ]),
                    $this->node('PROMO-AKSESUAR', 'Anahtarlık, Rozet & Küçük Aksesuarlar', 80, [
                        ['PROMO-AKSESUAR-ANAHTARLIK', 'Anahtarlıklar'],
                        ['PROMO-AKSESUAR-ROZET', 'Rozetler'],
                        ['PROMO-AKSESUAR-ACACAK', 'Açacaklar'],
                        ['PROMO-AKSESUAR-MAGNET', 'Magnetler'],
                        ['PROMO-AKSESUAR-ACACAKLI-MAGNET', 'Açacaklı Magnetler'],
                        ['PROMO-AKSESUAR-AYNA', 'Aynalar'],
                        ['PROMO-AKSESUAR-DIGER', 'Diğer Küçük Aksesuarlar'],
                    ]),
                    ['code' => 'PROMO-HEDIYELIK-SET', 'name' => 'Hediyelik Setler', 'sort_order' => 90],
                    $this->node('PROMO-ODUL', 'Plaket, Madalya & Ödül Ürünleri', 100, [
                        ['PROMO-ODUL-PLAKET', 'Plaketler'],
                        ['PROMO-ODUL-KUPA', 'Ödül Kupaları'],
                        ['PROMO-ODUL-MADALYA', 'Madalyalar'],
                        ['PROMO-ODUL-DIGER', 'Diğer Ödül Ürünleri'],
                    ]),
                    $this->node('PROMO-SAAT', 'Saatler', 110, [
                        ['PROMO-SAAT-DUVAR', 'Duvar Saatleri'],
                        ['PROMO-SAAT-MASA', 'Masa Saatleri'],
                        ['PROMO-SAAT-BUZDOLABI', 'Buzdolabı Saatleri'],
                        ['PROMO-SAAT-DIGER', 'Diğer Saatler'],
                    ]),
                    $this->node('PROMO-TEKSTIL', 'Tekstil Ürünleri', 120, [
                        ['PROMO-TEKSTIL-SAPKA', 'Şapkalar'],
                        ['PROMO-TEKSTIL-TISORT', 'Tişörtler'],
                        ['PROMO-TEKSTIL-POLAR-MONT', 'Polar & Montlar'],
                        ['PROMO-TEKSTIL-YAGMURLUK', 'Yağmurluklar'],
                        ['PROMO-TEKSTIL-DIGER', 'Diğer Tekstil Ürünleri'],
                    ]),
                    $this->node('PROMO-OUTDOOR-ARAC', 'Outdoor & Araç Ürünleri', 130, [
                        ['PROMO-OUTDOOR-ARAC-SEMSIYE', 'Şemsiyeler'],
                        ['PROMO-OUTDOOR-ARAC-KAMP', 'Kamp Ürünleri'],
                        ['PROMO-OUTDOOR-ARAC-FENER', 'Fenerler'],
                        ['PROMO-OUTDOOR-ARAC-CAKI', 'Çakılar'],
                        ['PROMO-OUTDOOR-ARAC-CAKMAK', 'Çakmaklar'],
                        ['PROMO-OUTDOOR-ARAC-ARAC-ICI', 'Araç İçi Ürünler'],
                        ['PROMO-OUTDOOR-ARAC-DIGER', 'Diğer Outdoor & Araç Ürünleri'],
                    ]),
                    $this->node('PROMO-DOGA-DOSTU', 'Doğa Dostu & Tohumlu Ürünler', 140, [
                        ['PROMO-DOGA-DOSTU-TOHUMLU', 'Tohumlu Ürünler'],
                        ['PROMO-DOGA-DOSTU-BITKI-YETISTIRME', 'Bitki Yetiştirme Setleri'],
                        ['PROMO-DOGA-DOSTU-GERI-DONUSUMLU', 'Geri Dönüşümlü Ürünler'],
                        ['PROMO-DOGA-DOSTU-DIGER', 'Diğer Doğa Dostu Ürünler'],
                    ]),
                    $this->node('PROMO-AMBALAJ-KUTU', 'Ambalaj & Boş Kutular', 150, [
                        ['PROMO-AMBALAJ-KUTU-SET', 'Set Kutuları'],
                        ['PROMO-AMBALAJ-KUTU-KALEM', 'Kalem Kutuları'],
                        ['PROMO-AMBALAJ-KUTU-HEDIYE', 'Hediye Kutuları'],
                        ['PROMO-AMBALAJ-KUTU-TENEKE', 'Teneke Kutular'],
                        ['PROMO-AMBALAJ-KUTU-KARTON', 'Karton Kutular'],
                        ['PROMO-AMBALAJ-KUTU-PROMOSYON', 'Promosyon Kutuları'],
                        ['PROMO-AMBALAJ-KUTU-DIGER', 'Diğer Kutular'],
                    ]),
                    $this->node('PROMO-TEMALI-KISIYE-OZEL', 'Temalı & Kişiye Özel Ürünler', 160, [
                        ['PROMO-TEMALI-KISIYE-OZEL-ATATURK', 'Atatürk Temalı Ürünler'],
                        ['PROMO-TEMALI-KISIYE-OZEL-KISIYE-OZEL', 'Kişiye Özel Ürünler'],
                        ['PROMO-TEMALI-KISIYE-OZEL-ISME-OZEL', 'İsme Özel Ürünler'],
                        ['PROMO-TEMALI-KISIYE-OZEL-OZEL-GUN', 'Özel Gün / Etkinlik Ürünleri'],
                        ['PROMO-TEMALI-KISIYE-OZEL-DIGER', 'Diğer Temalı Ürünler'],
                    ]),
                    $this->node('PROMO-DIGER', 'Diğer Promosyon Ürünleri', 170, [
                        ['PROMO-DIGER-EGITIM-SET', 'Eğitim Setleri'],
                        ['PROMO-DIGER-KARISIK', 'Karışık Ürünler'],
                        ['PROMO-DIGER-GENEL', 'Diğer'],
                    ]),
                    $this->node('PROMO-ESLENMEMIS', 'Eşlenmemiş / Kontrol Gereken', 180, [
                        ['PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN', 'Kategori Bekleyen Ürünler'],
                        ['PROMO-ESLENMEMIS-MANUEL-KONTROL', 'Manuel Kontrol Gereken Ürünler'],
                    ]),
                ],
            ],
            [
                'code' => 'PRINT',
                'name' => 'Matbaa Ürünleri',
                'family' => 'print',
                'sort_order' => 20,
                'children' => [
                    $this->node('PRINT-KURUMSAL-KIMLIK', 'Kurumsal Kimlik', 10, [
                        ['PRINT-KURUMSAL-KIMLIK-KARTVIZIT', 'Kartvizitler'],
                        ['PRINT-KURUMSAL-KIMLIK-ANTETLI', 'Antetli Kağıt'],
                        ['PRINT-KURUMSAL-KIMLIK-ZARF', 'Zarflar'],
                        ['PRINT-KURUMSAL-KIMLIK-SUNUM-DOSYA', 'Sunum Dosyaları'],
                        ['PRINT-KURUMSAL-KIMLIK-DIGER', 'Diğer Kurumsal Kimlik Ürünleri'],
                    ]),
                    $this->node('PRINT-TANITIM-BASKI', 'Tanıtım Baskıları', 20, [
                        ['PRINT-TANITIM-BASKI-BROSUR', 'Broşürler'],
                        ['PRINT-TANITIM-BASKI-EL-ILANI', 'El İlanları'],
                        ['PRINT-TANITIM-BASKI-FLYER', 'Flyer / Föyler'],
                        ['PRINT-TANITIM-BASKI-AFIS-POSTER', 'Afiş / Poster'],
                        ['PRINT-TANITIM-BASKI-MENU', 'Menüler'],
                        ['PRINT-TANITIM-BASKI-DIGER', 'Diğer Tanıtım Baskıları'],
                    ]),
                    $this->node('PRINT-COK-SAYFALI', 'Çok Sayfalı Baskılar', 30, [
                        ['PRINT-COK-SAYFALI-KATALOG-DERGI', 'Katalog / Dergi'],
                        ['PRINT-COK-SAYFALI-KITAPCIK', 'Kitapçık'],
                        ['PRINT-COK-SAYFALI-BLOKNOT', 'Bloknot'],
                        ['PRINT-COK-SAYFALI-DEFTER-AJANDA', 'Defter / Ajanda Üretimi'],
                        ['PRINT-COK-SAYFALI-DIGER', 'Diğer Çok Sayfalı Baskılar'],
                    ]),
                    $this->node('PRINT-ETIKET', 'Etiketler', 40, [
                        ['PRINT-ETIKET-DUZ-KESIM', 'Düz Kesim Etiket'],
                        ['PRINT-ETIKET-RULO', 'Rulo Etiket'],
                        ['PRINT-ETIKET-OZEL-KESIM', 'Özel Kesim Etiket'],
                        ['PRINT-ETIKET-DIGER', 'Diğer Etiketler'],
                    ]),
                    $this->node('PRINT-KUTU-AMBALAJ', 'Kutu / Ambalaj', 50, [
                        ['PRINT-KUTU-AMBALAJ-KARTON', 'Karton Kutu'],
                        ['PRINT-KUTU-AMBALAJ-MIKRO-OLUKLU', 'Mikro / Oluklu Kutu'],
                        ['PRINT-KUTU-AMBALAJ-TASLAMA-LUKS', 'Taslama / Lüks Kutu'],
                        ['PRINT-KUTU-AMBALAJ-BICAK-IZI', 'Bıçak İzi / Dieline'],
                        ['PRINT-KUTU-AMBALAJ-DIGER', 'Diğer Matbaa Ambalaj Ürünleri'],
                    ]),
                    $this->node('PRINT-TAKVIM', 'Takvimler', 60, [
                        ['PRINT-TAKVIM-GEMICI', 'Gemici Takvim'],
                        ['PRINT-TAKVIM-PIRAMIT', 'Piramit Takvim'],
                        ['PRINT-TAKVIM-MASA', 'Masa Takvimi'],
                        ['PRINT-TAKVIM-DUVAR', 'Duvar Takvimi'],
                        ['PRINT-TAKVIM-MASA-SUMENI', 'Masa Sümeni'],
                    ]),
                    $this->node('PRINT-BASKI-SONRASI', 'Baskı Sonrası / Yardımcı Hizmetler', 70, [
                        ['PRINT-BASKI-SONRASI-TASARIM', 'Tasarım'],
                        ['PRINT-BASKI-SONRASI-MONTAJ', 'Montaj'],
                        ['PRINT-BASKI-SONRASI-KESIM', 'Kesim'],
                        ['PRINT-BASKI-SONRASI-SELEFON-LAK', 'Selefon / Lak'],
                        ['PRINT-BASKI-SONRASI-YALDIZ-GOFRE', 'Yaldız / Gofre'],
                        ['PRINT-BASKI-SONRASI-CILTLEME', 'Ciltleme'],
                        ['PRINT-BASKI-SONRASI-DIGER', 'Diğer Yardımcı Hizmetler'],
                    ]),
                    ['code' => 'PRINT-DIGER', 'name' => 'Diğer Matbaa Ürünleri', 'sort_order' => 80],
                ],
            ],
        ];
    }

    private function node(string $code, string $name, int $sortOrder, array $children): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'sort_order' => $sortOrder,
            'children' => collect($children)
                ->map(fn (array $child, int $index) => [
                    'code' => $child[0],
                    'name' => $child[1],
                    'sort_order' => ($index + 1) * 10,
                ])
                ->all(),
        ];
    }

    private function featureTemplates(): array
    {
        return [
            'pen' => [
                'label' => 'Kalem Şablonu',
                'category_codes' => ['PROMO-KALEM'],
                'attributes' => [
                    ['code' => 'color', 'name' => 'Renk', 'type' => 'select'],
                    ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
                    ['code' => 'mechanism', 'name' => 'Mekanizma', 'type' => 'select'],
                    ['code' => 'ink_type', 'name' => 'Mürekkep Tipi', 'type' => 'select'],
                    ['code' => 'tip_type', 'name' => 'Uç Tipi', 'type' => 'select'],
                    ['code' => 'body_type', 'name' => 'Gövde Tipi', 'type' => 'select'],
                    ['code' => 'touch_pen', 'name' => 'Dokunmatik Var/Yok', 'type' => 'boolean'],
                    ['code' => 'eco_friendly', 'name' => 'Doğa Dostu / Geri Dönüşümlü', 'type' => 'boolean'],
                    ['code' => 'print_type', 'name' => 'Baskı Tipi', 'type' => 'select'],
                ],
            ],
            'notebook_agenda' => [
                'label' => 'Defter & Ajanda Şablonu',
                'category_codes' => ['PROMO-DEFTER-AJANDA'],
                'attributes' => [
                    ['code' => 'dated_undated', 'name' => 'Tarihli / Tarihsiz', 'type' => 'select'],
                    ['code' => 'size', 'name' => 'Ebat', 'type' => 'select'],
                    ['code' => 'cover_type', 'name' => 'Kapak Tipi', 'type' => 'select'],
                    ['code' => 'cover_material', 'name' => 'Kapak Malzemesi', 'type' => 'select'],
                    ['code' => 'page_count', 'name' => 'Sayfa Sayısı', 'type' => 'number'],
                    ['code' => 'paper_type', 'name' => 'Kağıt Türü', 'type' => 'select'],
                    ['code' => 'spiral', 'name' => 'Spiralli Var/Yok', 'type' => 'boolean'],
                    ['code' => 'recycled', 'name' => 'Geri Dönüşümlü Var/Yok', 'type' => 'boolean'],
                ],
            ],
            'technology' => [
                'label' => 'Teknoloji Şablonu',
                'category_codes' => ['PROMO-TEKNOLOJI'],
                'attributes' => [
                    ['code' => 'capacity_gb', 'name' => 'Kapasite GB', 'type' => 'number', 'unit' => 'GB'],
                    ['code' => 'capacity_mah', 'name' => 'Kapasite mAh', 'type' => 'number', 'unit' => 'mAh'],
                    ['code' => 'watt', 'name' => 'Watt', 'type' => 'number', 'unit' => 'W'],
                    ['code' => 'connection_type', 'name' => 'Bağlantı Tipi', 'type' => 'select'],
                    ['code' => 'wireless_charging', 'name' => 'Kablosuz Şarj Var/Yok', 'type' => 'boolean'],
                    ['code' => 'usb_type', 'name' => 'USB Tipi', 'type' => 'select'],
                    ['code' => 'color', 'name' => 'Renk', 'type' => 'select'],
                    ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
                ],
            ],
            'drinkware' => [
                'label' => 'İçecek Ürünleri Şablonu',
                'category_codes' => ['PROMO-ICECEK'],
                'attributes' => [
                    ['code' => 'volume_ml', 'name' => 'Hacim ml', 'type' => 'number', 'unit' => 'ml'],
                    ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
                    ['code' => 'cover_type', 'name' => 'Kapak Tipi', 'type' => 'select'],
                    ['code' => 'handle_type', 'name' => 'Kulplu / Kulpsuz', 'type' => 'select'],
                    ['code' => 'heat_retention', 'name' => 'Sıcak Tutma', 'type' => 'boolean'],
                    ['code' => 'cold_retention', 'name' => 'Soğuk Tutma', 'type' => 'boolean'],
                    ['code' => 'inner_surface', 'name' => 'İç Yüzey', 'type' => 'select'],
                ],
            ],
            'bag' => [
                'label' => 'Çanta & Taşıma Şablonu',
                'category_codes' => ['PROMO-CANTA-TASIMA'],
                'attributes' => [
                    ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
                    ['code' => 'size', 'name' => 'Ebat', 'type' => 'select'],
                    ['code' => 'volume', 'name' => 'Hacim', 'type' => 'number'],
                    ['code' => 'carrying_type', 'name' => 'Taşıma Tipi', 'type' => 'select'],
                    ['code' => 'handle_type', 'name' => 'Sap Tipi', 'type' => 'select'],
                    ['code' => 'gusset', 'name' => 'Körüklü Var/Yok', 'type' => 'boolean'],
                    ['code' => 'print_area', 'name' => 'Baskı Alanı', 'type' => 'text'],
                ],
            ],
            'gift_set' => [
                'label' => 'Hediyelik Set Şablonu',
                'category_codes' => ['PROMO-HEDIYELIK-SET'],
                'attributes' => [
                    ['code' => 'set_content', 'name' => 'Set İçeriği', 'type' => 'multi'],
                    ['code' => 'set_type', 'name' => 'Set Tipi', 'type' => 'select'],
                    ['code' => 'box_type', 'name' => 'Kutu Tipi', 'type' => 'select'],
                    ['code' => 'piece_count', 'name' => 'Parça Sayısı', 'type' => 'number'],
                    ['code' => 'theme', 'name' => 'Tema', 'type' => 'select'],
                ],
            ],
            'office' => [
                'label' => 'Ofis & Masaüstü Şablonu',
                'category_codes' => ['PROMO-OFIS-MASAUSTU'],
                'attributes' => [
                    ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
                    ['code' => 'desktop_type', 'name' => 'Masaüstü Tipi', 'type' => 'select'],
                    ['code' => 'set_content', 'name' => 'Set İçeriği', 'type' => 'multi'],
                    ['code' => 'size', 'name' => 'Ebat', 'type' => 'select'],
                    ['code' => 'print_type', 'name' => 'Baskı Tipi', 'type' => 'select'],
                ],
            ],
            'award' => [
                'label' => 'Ödül / Plaket Şablonu',
                'category_codes' => ['PROMO-ODUL'],
                'attributes' => [
                    ['code' => 'award_type', 'name' => 'Plaket Tipi', 'type' => 'select'],
                    ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
                    ['code' => 'size', 'name' => 'Ebat', 'type' => 'select'],
                    ['code' => 'boxed', 'name' => 'Kutulu / Kutusuz', 'type' => 'boolean'],
                ],
            ],
            'clock' => [
                'label' => 'Saat Şablonu',
                'category_codes' => ['PROMO-SAAT'],
                'attributes' => [
                    ['code' => 'clock_type', 'name' => 'Saat Tipi', 'type' => 'select'],
                    ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
                    ['code' => 'glass_type', 'name' => 'Cam Tipi', 'type' => 'select'],
                    ['code' => 'appearance', 'name' => 'Görünüm', 'type' => 'select'],
                    ['code' => 'size', 'name' => 'Ebat', 'type' => 'select'],
                ],
            ],
            'print' => [
                'label' => 'Matbaa Şablonu',
                'category_codes' => ['PRINT'],
                'attributes' => [
                    ['code' => 'paper_size', 'name' => 'Ebat', 'type' => 'select'],
                    ['code' => 'paper_weight', 'name' => 'Kağıt Gramajı', 'type' => 'number', 'unit' => 'gr'],
                    ['code' => 'print_color', 'name' => 'Baskı Renk', 'type' => 'select'],
                    ['code' => 'binding_type', 'name' => 'Cilt Tipi', 'type' => 'select'],
                    ['code' => 'page_count', 'name' => 'Sayfa Sayısı', 'type' => 'number'],
                    ['code' => 'cutting_type', 'name' => 'Kesim Tipi', 'type' => 'select'],
                    ['code' => 'surface_finish', 'name' => 'Yüzey İşlemi', 'type' => 'select'],
                ],
            ],
            'general_promotion' => [
                'label' => 'Genel Promosyon Şablonu',
                'category_codes' => ['PROMO'],
                'attributes' => [
                    ['code' => 'color', 'name' => 'Renk', 'type' => 'select'],
                    ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
                    ['code' => 'size', 'name' => 'Ebat', 'type' => 'select'],
                    ['code' => 'print_type', 'name' => 'Baskı Tipi', 'type' => 'select'],
                    ['code' => 'theme', 'name' => 'Tema', 'type' => 'select'],
                ],
            ],
        ];
    }
}
