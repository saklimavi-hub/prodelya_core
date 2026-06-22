<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CategoryCleanupDecision;
use App\Models\CategoryTreeDraft;
use App\Models\CategoryTreeDraftItem;
use App\Models\StandardCategory;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryCleanupController extends Controller
{
    public function index(): View
    {
        $categories = StandardCategory::query()
            ->notArchived()
            ->with(['parent'])
            ->withCount(['children', 'supplierCategoryMappings', 'standardProducts'])
            ->orderBy('path')
            ->get();

        $draft = $this->ensureInitialDraft();
        $analysis = $this->buildTreeAnalysis($categories);
        $reviewGroups = $this->buildReviewGroups($categories);
        $preview = $this->buildPreview($draft);
        $decisionSummary = $this->ensureDecisionList($draft, $categories);
        $decisionRows = CategoryCleanupDecision::query()
            ->where('draft_id', $draft->id)
            ->orderByRaw("CASE risk_level WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE proposed_action WHEN 'needs_review' THEN 0 WHEN 'merge' THEN 1 WHEN 'alias' THEN 2 WHEN 'twin_view' THEN 3 ELSE 4 END")
            ->orderBy('current_path')
            ->get();
        $mappingDecisions = $this->buildMappingDecisionSamples();
        $featureTemplates = $this->buildFeatureTemplates();
        $recentLogs = SupplierCategoryMappingLog::query()
            ->with(['mapping.supplier', 'oldStandardCategory', 'newStandardCategory', 'changedBy'])
            ->latest()
            ->limit(20)
            ->get();

        return view('super-admin.product-data-hub.category-cleanup', [
            'analysis' => $analysis,
            'reviewGroups' => $reviewGroups,
            'draft' => $draft->load(['items.children']),
            'preview' => $preview,
            'decisionRows' => $decisionRows,
            'decisionSummary' => $decisionSummary,
            'mappingDecisions' => $mappingDecisions,
            'featureTemplates' => $featureTemplates,
            'recentLogs' => $recentLogs,
        ]);
    }

    public function exportDecisions(string $format)
    {
        $categories = StandardCategory::query()
            ->notArchived()
            ->with(['parent'])
            ->withCount(['children', 'supplierCategoryMappings', 'standardProducts'])
            ->orderBy('path')
            ->get();
        $draft = $this->ensureInitialDraft();

        $this->ensureDecisionList($draft, $categories);

        $decisions = CategoryCleanupDecision::query()
            ->where('draft_id', $draft->id)
            ->orderBy('current_path')
            ->get();

        if ($format === 'json') {
            return response()->json([
                'draft' => [
                    'id' => $draft->id,
                    'name' => $draft->name,
                    'status' => $draft->status,
                ],
                'active_tree_changed' => false,
                'decisions' => $decisions->map(fn (CategoryCleanupDecision $decision) => $this->decisionExportRow($decision))->values(),
            ]);
        }

        $headers = [
            'current_id',
            'current_code',
            'current_name',
            'current_path',
            'product_count',
            'mapping_count',
            'proposed_action',
            'proposed_code',
            'proposed_name',
            'proposed_path',
            'risk_level',
            'reason',
            'decision_status',
        ];

        $lines = [implode(',', $headers)];
        foreach ($decisions as $decision) {
            $row = $this->decisionExportRow($decision);
            $lines[] = implode(',', array_map(fn ($value) => $this->csvValue((string) $value), [
                $row['current_id'],
                $row['current_code'],
                $row['current_name'],
                $row['current_path'],
                $row['product_count'],
                $row['mapping_count'],
                $row['proposed_action'],
                $row['proposed_code'],
                $row['proposed_name'],
                $row['proposed_path'],
                $row['risk_level'],
                $row['reason'],
                $row['decision_status'],
            ]));
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kategori-karar-listesi.csv"',
        ]);
    }

    public function featureTemplates(): View
    {
        return view('super-admin.product-data-hub.category-feature-templates', [
            'featureTemplates' => $this->buildFeatureTemplates(),
        ]);
    }

    public function reorderDraftItem(Request $request, CategoryTreeDraftItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|integer|exists:category_tree_draft_items,id',
            'sort_order' => 'required|integer|min:0|max:100000',
            'new_path_preview' => 'nullable|string|max:500',
        ]);

        $newParentId = $validated['parent_id'] ?? null;

        if ($newParentId === $item->id || ($newParentId && $this->draftItemIsDescendant($newParentId, $item))) {
            return back()
                ->withErrors(['parent_id' => 'Kategori kendi altına veya kendi alt kategorisinin altına taşınamaz.'])
                ->withInput();
        }

        if ($newParentId) {
            $newParent = CategoryTreeDraftItem::query()->where('draft_id', $item->draft_id)->findOrFail($newParentId);
            $newPathPreview = $this->buildDraftPathPreview($item, $newParent);
        } else {
            $newPathPreview = $item->proposed_name;
        }

        $item->update([
            'parent_id' => $newParentId,
            'sort_order' => $validated['sort_order'],
            'action_type' => $item->action_type === 'create_new' ? 'create_new' : 'move',
            'notes' => trim(($item->notes ? $item->notes . "\n" : '') . 'Taslak taşıma önizlemesi: ' . $newPathPreview),
        ]);

        return redirect()
            ->route('admin.super.product-data-hub.category-cleanup.index')
            ->with('success', 'Taslak kategori sırası güncellendi. Aktif kategori ağacı değişmedi.');
    }

    private function ensureInitialDraft(): CategoryTreeDraft
    {
        $draft = CategoryTreeDraft::query()->firstOrCreate(
            ['name' => 'Prodelya Temiz Standart Kategori Taslağı'],
            [
                'description' => 'Aktif kategori ağacını değiştirmeden promosyon ve matbaa kategorilerini sadeleştiren ilk taslak.',
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]
        );

        if ($draft->items()->exists()) {
            return $draft;
        }

        foreach ($this->initialDraftTree() as $rootIndex => $root) {
            $rootItem = CategoryTreeDraftItem::query()->create([
                'draft_id' => $draft->id,
                'parent_id' => null,
                'proposed_code' => $root['code'],
                'proposed_name' => $root['name'],
                'product_family' => $root['family'],
                'sort_order' => ($rootIndex + 1) * 10,
                'action_type' => 'create_new',
                'notes' => 'Taslak kök kategori. Aktif ağaca uygulanmadı.',
            ]);

            foreach ($root['children'] as $childIndex => $childName) {
                CategoryTreeDraftItem::query()->create([
                    'draft_id' => $draft->id,
                    'parent_id' => $rootItem->id,
                    'proposed_code' => Str::upper(StandardCategory::generateSlug($root['code'] . '-' . $childName)),
                    'proposed_name' => $childName,
                    'product_family' => $root['family'],
                    'sort_order' => ($childIndex + 1) * 10,
                    'action_type' => 'create_new',
                    'notes' => 'İlk temiz taslak önerisi. Mevcut kategori ve ürün bağlantılarına dokunmaz.',
                ]);
            }
        }

        return $draft->refresh();
    }

    private function ensureDecisionList(CategoryTreeDraft $draft, Collection $categories): array
    {
        $summary = [
            'total' => 0,
            'by_action' => [],
            'by_risk' => [],
            'needs_review' => 0,
        ];

        foreach ($categories as $category) {
            $proposal = $this->proposeDecisionForCategory($category);
            $existing = CategoryCleanupDecision::query()
                ->where('draft_id', $draft->id)
                ->where('current_category_id', $category->id)
                ->first();

            $status = $existing && in_array($existing->decision_status, ['approved', 'rejected', 'needs_discussion'], true)
                ? $existing->decision_status
                : 'proposed';

            CategoryCleanupDecision::query()->updateOrCreate(
                [
                    'draft_id' => $draft->id,
                    'current_category_id' => $category->id,
                ],
                array_merge($proposal, [
                    'current_code' => $category->code,
                    'current_name' => $category->name,
                    'current_path' => $category->full_path,
                    'current_parent' => $category->parent?->name,
                    'level' => (int) $category->depth,
                    'product_family' => $category->product_family,
                    'product_count' => (int) $category->standard_products_count,
                    'supplier_mapping_count' => (int) $category->supplier_category_mappings_count,
                    'child_count' => (int) $category->children_count,
                    'is_active' => (bool) $category->is_active,
                    'is_visible' => (bool) $category->visible_in_catalog,
                    'decision_status' => $status,
                ])
            );

            $summary['total']++;
            $summary['by_action'][$proposal['proposed_action']] = ($summary['by_action'][$proposal['proposed_action']] ?? 0) + 1;
            $summary['by_risk'][$proposal['risk_level']] = ($summary['by_risk'][$proposal['risk_level']] ?? 0) + 1;
            $summary['needs_review'] += $proposal['needs_user_review'] ? 1 : 0;
        }

        return $summary;
    }

    private function proposeDecisionForCategory(StandardCategory $category): array
    {
        $text = $this->normalizeName(implode(' ', [
            $category->code,
            $category->name,
            $category->path,
        ]));
        $upperCode = Str::upper((string) $category->code);
        $path = $category->full_path;
        $productFamily = $category->product_family ?: 'promotion';
        $isEmptyLeaf = (int) $category->standard_products_count === 0
            && (int) $category->supplier_category_mappings_count === 0
            && (int) $category->children_count === 0;

        $payload = [
            'warning_flags' => [],
            'proposed_action' => 'keep',
            'proposed_category_code' => $category->code,
            'proposed_category_name' => $category->name,
            'proposed_category_path' => $path,
            'proposed_parent' => $category->parent?->name,
            'confidence_score' => 82,
            'reason' => 'Mevcut kategori anlamı korunabilir görünüyor; aktif ağaca uygulanacak bir değişiklik yapılmadı.',
            'risk_level' => 'low',
            'needs_user_review' => false,
            'feature_template_key' => $this->suggestFeatureTemplateKey($text, $path),
        ];

        if (Str::contains($upperCode, ['TMP', 'TEMP', 'DEMO', 'TEST']) || (!$category->is_active && $isEmptyLeaf)) {
            return array_merge($payload, [
                'warning_flags' => ['temp_or_inactive_empty'],
                'proposed_action' => 'deactivate',
                'proposed_category_path' => 'Pasif / Arşiv',
                'proposed_parent' => 'Pasif / Arşiv',
                'confidence_score' => 70,
                'reason' => 'Temp/demo/test veya pasif boş kategori adayı; hard delete yok, sadece pasif/alias kararı önerilir.',
                'risk_level' => 'medium',
                'needs_user_review' => true,
            ]);
        }

        if (Str::contains($text, ['termos', 'matara', 'kupa', 'icecek', 'içecek', 'french press', 'cam urun'])) {
            $isDuplicateRoot = Str::contains($upperCode, ['PROMO-TERMOS', 'PROMO-ICECEK']);

            return array_merge($payload, [
                'warning_flags' => ['drink_family_duplicate_candidate'],
                'proposed_action' => $isDuplicateRoot ? 'alias' : 'move',
                'proposed_category_code' => 'PROMOSYON-ICECEK-URUNLERI',
                'proposed_category_name' => $this->drinkCategoryName($text),
                'proposed_category_path' => 'Promosyon Ürünleri / İçecek Ürünleri / ' . $this->drinkCategoryName($text),
                'proposed_parent' => 'İçecek Ürünleri',
                'confidence_score' => $isDuplicateRoot ? 88 : 78,
                'reason' => 'Termos, matara, kupa ve içecek ürünleri tek canonical aile altında toplanmalı; PROMO-TERMOS/PROMO-ICECEK tekrarları alias/merge incelemesine alınır.',
                'risk_level' => $isDuplicateRoot ? 'medium' : 'low',
                'needs_user_review' => $isDuplicateRoot,
                'feature_template_key' => 'icecek',
            ]);
        }

        if (Str::contains($text, ['set kutu', 'set kutusu', 'set kutulari', 'ambalaj', 'kutu'])) {
            return array_merge($payload, [
                'warning_flags' => ['packaging_or_set_box_review'],
                'proposed_action' => 'needs_review',
                'proposed_category_code' => 'PROMOSYON-AMBALAJ-KUTULAR',
                'proposed_category_name' => 'Ambalaj & Kutular',
                'proposed_category_path' => 'Promosyon Ürünleri / Ambalaj & Kutular',
                'proposed_parent' => 'Ambalaj & Kutular',
                'confidence_score' => 64,
                'reason' => 'Boş kutu/ambalaj Ambalaj & Kutular; ürün seti Hediyelik Setler altında kalmalı. İçerik ayrımı için kullanıcı incelemesi gerekir.',
                'risk_level' => 'high',
                'needs_user_review' => true,
                'feature_template_key' => 'genel_promosyon',
            ]);
        }

        if (Str::contains($text, ['hediyelik set', 'promo set', 'setler', 'vip set', 'kutulu set', 'kalemli set', 'defterli set', 'termoslu set', 'teknolojik set', 'hazir paket'])) {
            return array_merge($payload, [
                'warning_flags' => ['gift_set_duplicate_candidate'],
                'proposed_action' => Str::contains($upperCode, ['PROMO-SET', 'PROMO-HEDIYELIK-SET']) ? 'merge' : 'move',
                'proposed_category_code' => 'PROMOSYON-HEDIYELIK-SETLER',
                'proposed_category_name' => $this->giftSetCategoryName($text, $category->name),
                'proposed_category_path' => 'Promosyon Ürünleri / Hediyelik Setler / ' . $this->giftSetCategoryName($text, $category->name),
                'proposed_parent' => 'Hediyelik Setler',
                'confidence_score' => 84,
                'reason' => 'Hediyelik set tekrarları canonical Hediyelik Setler altında incelenmeli; gerçek merge bu fazda uygulanmaz.',
                'risk_level' => 'medium',
                'needs_user_review' => true,
                'feature_template_key' => 'genel_promosyon',
            ]);
        }

        if (Str::contains($text, ['takvim'])) {
            $isPrint = $productFamily === 'print' || Str::contains($upperCode . ' ' . $text, ['PRINT', 'MATBAA']);
            $targetPath = $isPrint
                ? 'Matbaa Ürünleri / Takvimler'
                : 'Promosyon Ürünleri / Baskılı Kağıt & Masa Ürünleri / Takvimler';

            return array_merge($payload, [
                'warning_flags' => ['calendar_twin_view_candidate'],
                'proposed_action' => 'twin_view',
                'proposed_category_code' => $isPrint ? 'MATBAA-TAKVIMLER' : 'PROMOSYON-BASKILI-KAGIT-TAKVIMLER',
                'proposed_category_name' => 'Takvimler',
                'proposed_category_path' => $targetPath,
                'proposed_parent' => $isPrint ? 'Matbaa Ürünleri' : 'Baskılı Kağıt & Masa Ürünleri',
                'confidence_score' => 78,
                'reason' => 'Promosyon takvimleri ve matbaa takvimleri ayrı ailelerde tutulmalı; aynı ürün iki katalogda görünecekse ikiz görünüm önerilir.',
                'risk_level' => 'medium',
                'needs_user_review' => true,
                'feature_template_key' => $isPrint ? 'matbaa' : 'baskili_kagit_masa',
            ]);
        }

        if (Str::contains($text, ['mousepad', 'mouse pad', 'bardakalti', 'bardak altligi', 'sumen', 'sümen'])) {
            $isWireless = Str::contains($text, ['wireless', 'kablosuz']);
            $targetPath = $isWireless
                ? 'Promosyon Ürünleri / Teknolojik Ürünler / Wireless Mousepad'
                : 'Promosyon Ürünleri / Ofis & Masaüstü Ürünleri / Mousepad & Sümen';

            return array_merge($payload, [
                'warning_flags' => ['mousepad_attribute_review'],
                'proposed_action' => 'filter_attribute',
                'proposed_category_code' => $isWireless ? 'PROMOSYON-TEKNOLOJI-WIRELESS-MOUSEPAD' : 'PROMOSYON-OFIS-MOUSEPAD',
                'proposed_category_name' => $isWireless ? 'Wireless Mousepad' : 'Mousepad & Sümen',
                'proposed_category_path' => $targetPath,
                'proposed_parent' => $isWireless ? 'Teknolojik Ürünler' : 'Ofis & Masaüstü Ürünleri',
                'confidence_score' => 72,
                'reason' => 'Mousepad ölçü, malzeme ve baskı tipi kategori yerine özellik/filtre olmalı; wireless ürünler teknoloji ailesine ayrılır.',
                'risk_level' => 'medium',
                'needs_user_review' => true,
                'feature_template_key' => $isWireless ? 'teknoloji' : 'baskili_kagit_masa',
            ]);
        }

        if (Str::contains($text, ['acacak', 'açacak', 'magnet'])) {
            return array_merge($payload, [
                'warning_flags' => ['similar_but_distinct_accessory'],
                'proposed_action' => 'separate_keep',
                'proposed_category_code' => $category->code,
                'proposed_category_name' => $category->name,
                'proposed_category_path' => $path,
                'confidence_score' => 76,
                'reason' => 'Açacak, Magnet ve Açacaklı Magnet benzer ama aynı ürün değildir; otomatik merge önerilmez.',
                'risk_level' => 'medium',
                'needs_user_review' => true,
                'feature_template_key' => 'genel_promosyon',
            ]);
        }

        if ($isEmptyLeaf) {
            return array_merge($payload, [
                'warning_flags' => ['empty_leaf'],
                'proposed_action' => 'deactivate',
                'confidence_score' => 62,
                'reason' => 'Ürün, mapping ve alt kategori bağlantısı olmayan boş yaprak kategori; pasif/alias kararı için incelenebilir.',
                'risk_level' => 'medium',
                'needs_user_review' => true,
            ]);
        }

        return $payload;
    }

    private function buildTreeAnalysis(Collection $categories): array
    {
        $duplicateNames = $categories
            ->groupBy(fn (StandardCategory $category) => $this->normalizeName($category->name))
            ->filter(fn (Collection $group, string $key) => $key !== '' && $group->count() > 1);

        $duplicateCodes = $categories
            ->filter(fn (StandardCategory $category) => filled($category->code))
            ->groupBy(fn (StandardCategory $category) => Str::upper((string) $category->code))
            ->filter(fn (Collection $group) => $group->count() > 1);

        $repeatedAcrossParents = $duplicateNames
            ->filter(fn (Collection $group) => $group->pluck('parent_id')->unique()->count() > 1);

        $sameParentSimilar = $categories
            ->groupBy(fn (StandardCategory $category) => ($category->parent_id ?: 0) . '|' . $this->normalizeName($category->name))
            ->filter(fn (Collection $group) => $group->count() > 1);

        $tempRoots = $categories->filter(function (StandardCategory $category) {
            if ($category->parent_id !== null) {
                return false;
            }

            $haystack = Str::upper(($category->code ?? '') . ' ' . $category->name);

            return Str::contains($haystack, ['TMP', 'TEMP', 'DEMO', 'TEST']);
        });

        return [
            'total' => $categories->count(),
            'root_count' => $categories->whereNull('parent_id')->count(),
            'active_count' => $categories->where('is_active', true)->count(),
            'passive_count' => $categories->where('is_active', false)->count(),
            'visible_count' => $categories->where('visible_in_catalog', true)->count(),
            'hidden_count' => $categories->where('visible_in_catalog', false)->count(),
            'deep_count' => $categories->filter(fn (StandardCategory $category) => (int) $category->depth >= 2)->count(),
            'with_products_count' => $categories->filter(fn (StandardCategory $category) => (int) $category->standard_products_count > 0)->count(),
            'with_mappings_count' => $categories->filter(fn (StandardCategory $category) => (int) $category->supplier_category_mappings_count > 0)->count(),
            'empty_count' => $categories->filter(fn (StandardCategory $category) => (int) $category->standard_products_count === 0 && (int) $category->supplier_category_mappings_count === 0 && (int) $category->children_count === 0)->count(),
            'duplicate_name_count' => $duplicateNames->count(),
            'duplicate_code_count' => $duplicateCodes->count(),
            'same_parent_similar_count' => $sameParentSimilar->count(),
            'repeated_across_parent_count' => $repeatedAcrossParents->count(),
            'temp_root_count' => $tempRoots->count(),
            'duplicate_names' => $this->summarizeGroups($duplicateNames),
            'duplicate_codes' => $this->summarizeGroups($duplicateCodes, useCode: true),
            'repeated_across_parents' => $this->summarizeGroups($repeatedAcrossParents),
            'temp_roots' => $tempRoots->map(fn (StandardCategory $category) => [
                'title' => $category->name,
                'code' => $category->code,
                'paths' => [$category->full_path],
            ])->values()->all(),
        ];
    }

    private function buildReviewGroups(Collection $categories): array
    {
        $definitions = [
            [
                'title' => 'Termos, Matara & Kupa',
                'keywords' => ['termos', 'matara', 'kupa', 'icecek', 'içecek'],
                'recommendation' => 'İçecek Ürünleri altında sade kategori; hacim ve malzeme filtre olmalı.',
                'type' => 'alias',
            ],
            [
                'title' => 'Hediyelik Setler',
                'keywords' => ['hediyelik set', 'setler', 'set kutu', 'set-kutu'],
                'recommendation' => 'Hediyelik Setler ana kategorisi korunmalı; kutu/ambalaj ayrı özellik veya Ambalaj & Kutular altında yönetilmeli.',
                'type' => 'merge',
            ],
            [
                'title' => 'Takvimler',
                'keywords' => ['takvim'],
                'recommendation' => 'Promosyon takvimi ve matbaa takvimi ayrımı kök aileye göre yapılmalı; TMP/PRINT tekrarları alias/pasif adayı.',
                'type' => 'twin_view',
            ],
            [
                'title' => 'Mousepad / Wireless Mousepad',
                'keywords' => ['mousepad', 'mouse pad', 'sümen', 'sumen'],
                'recommendation' => 'Mousepad tek ürün ailesi olmalı; wireless, baskılı ve sümen bilgisi özellik/filtreye taşınmalı.',
                'type' => 'filter',
            ],
            [
                'title' => 'Etiket / Sticker',
                'keywords' => ['etiket', 'sticker'],
                'recommendation' => 'Matbaa Etiketleri ve promosyon sticker kullanımı ayrıştırılmalı; aynı ürün ailesi alias ile kontrol edilmeli.',
                'type' => 'alias',
            ],
            [
                'title' => 'Set Kutuları / Ambalaj',
                'keywords' => ['ambalaj', 'kutu', 'set kutu'],
                'recommendation' => 'Ambalaj & Kutular altında sadeleşmeli; Hediyelik Setler ile karışanlar review adayı.',
                'type' => 'merge',
            ],
        ];

        return collect($definitions)->map(function (array $definition) use ($categories) {
            $matches = $categories->filter(function (StandardCategory $category) use ($definition) {
                $text = $this->normalizeName(($category->code ?? '') . ' ' . $category->name . ' ' . ($category->path ?? ''));

                return collect($definition['keywords'])->contains(fn (string $keyword) => Str::contains($text, $this->normalizeName($keyword)));
            });

            return [
                'title' => $definition['title'],
                'type' => $definition['type'],
                'recommendation' => $definition['recommendation'],
                'count' => $matches->count(),
                'paths' => $matches->pluck('full_path')->take(8)->values()->all(),
            ];
        })->all();
    }

    private function buildPreview(CategoryTreeDraft $draft): array
    {
        $items = $draft->items()->get();
        $sourceCategoryIds = $items->pluck('source_category_id')->filter()->unique()->values();

        return [
            'rename_count' => $items->where('action_type', 'rename')->count(),
            'move_count' => $items->where('action_type', 'move')->count(),
            'alias_count' => $items->where('action_type', 'alias')->count(),
            'deactivate_count' => $items->where('action_type', 'deactivate')->count(),
            'merge_count' => $items->where('action_type', 'merge')->count(),
            'create_new_count' => $items->where('action_type', 'create_new')->count(),
            'affected_products' => $sourceCategoryIds->isEmpty()
                ? 0
                : StandardCategory::query()->whereIn('id', $sourceCategoryIds)->withCount('standardProducts')->get()->sum('standard_products_count'),
            'affected_mappings' => $sourceCategoryIds->isEmpty()
                ? 0
                : SupplierCategoryMapping::query()->whereIn('standard_category_id', $sourceCategoryIds)->count(),
            'affected_tenant_products' => 0,
            'risk_note' => 'Bu önizleme gerçek kategori, ürün veya eşleme kaydı değiştirmez.',
        ];
    }

    private function buildMappingDecisionSamples(): Collection
    {
        return SupplierCategoryMapping::query()
            ->with(['supplier', 'source', 'standardCategory'])
            ->orderByRaw("CASE WHEN mapping_status IN ('pending', 'needs_review', 'conflict', 'cancelled') THEN 0 ELSE 1 END")
            ->orderByDesc('confidence_score')
            ->limit(6)
            ->get()
            ->map(function (SupplierCategoryMapping $mapping) {
                $similar = StandardCategory::query()
                    ->withCount(['standardProducts', 'supplierCategoryMappings', 'children'])
                    ->when($mapping->standard_category_id, fn ($query) => $query->where('id', '!=', $mapping->standard_category_id))
                    ->when(filled($mapping->source_category), function ($query) use ($mapping) {
                        $search = $this->normalizeName($mapping->source_category ?: '');
                        $terms = collect(explode(' ', $search))->filter(fn ($term) => mb_strlen($term) >= 4)->take(3);

                        $query->where(function ($nested) use ($terms) {
                            foreach ($terms as $term) {
                                $nested->orWhere('name', 'like', '%' . $term . '%')
                                    ->orWhere('path', 'like', '%' . $term . '%');
                            }
                        });
                    })
                    ->orderByDesc('standard_products_count')
                    ->limit(4)
                    ->get();

                return [
                    'mapping' => $mapping,
                    'similar_categories' => $similar,
                    'risk_level' => $this->mappingRiskLevel($mapping, $similar),
                ];
            });
    }

    private function buildFeatureTemplates(): array
    {
        return [
            [
                'name' => 'Kalem Şablonu',
                'category' => 'Kalemler',
                'key' => 'kalem',
                'fields' => ['renk: select', 'malzeme: select', 'gövde tipi: select', 'mekanizma: select', 'baskı tipi: multi'],
                'purpose' => 'Web filtresi, tenant katalog filtresi, XML/API export, import normalizasyonu ve kategori öneri motoru.',
            ],
            [
                'name' => 'Defter & Ajanda Şablonu',
                'category' => 'Defter & Ajandalar',
                'key' => 'defter',
                'fields' => ['ebat: select cm', 'tarihli/tarihsiz: boolean', 'kapak tipi: select', 'sayfa sayısı: number', 'kağıt türü: select'],
                'purpose' => 'Tenant katalog, web filtreleri, import eşleme ve export alanları.',
            ],
            [
                'name' => 'Teknoloji Şablonu',
                'category' => 'Teknolojik Ürünler',
                'key' => 'teknoloji',
                'fields' => ['kapasite GB: number', 'kapasite mAh: number', 'watt: number', 'bağlantı tipi: select', 'kablosuz şarj: boolean', 'USB tipi: select'],
                'purpose' => 'Powerbank, USB ve elektronik ürünlerde filtre, export ve öneri sinyali.',
            ],
            [
                'name' => 'İçecek Ürünleri Şablonu',
                'category' => 'İçecek Ürünleri',
                'key' => 'icecek',
                'fields' => ['hacim ml: number', 'malzeme: select', 'kapak tipi: select', 'sıcak/soğuk tutma: boolean', 'iç yüzey: select'],
                'purpose' => 'Termos, matara, kupa ve cam ürünlerde import normalizasyonu, katalog filtreleri ve export alanları.',
            ],
            [
                'name' => 'Çanta & Taşıma Şablonu',
                'category' => 'Çanta & Taşıma Ürünleri',
                'key' => 'canta',
                'fields' => ['malzeme: select', 'ebat: text cm', 'hacim: number', 'taşıma tipi: select'],
                'purpose' => 'Çanta ailesinde web filtresi, tenant katalog ve XML/API export alanları.',
            ],
            [
                'name' => 'Baskılı Kağıt & Masa Şablonu',
                'category' => 'Baskılı Kağıt & Masa Ürünleri',
                'key' => 'baskili_kagit_masa',
                'fields' => ['ebat: text cm', 'malzeme: select', 'gramaj: number', 'baskı tipi: multi', 'yüzey: select'],
                'purpose' => 'Masaüstü, mousepad, takvim ve kağıt promosyon ürünlerinde filtre ve export standardı.',
            ],
            [
                'name' => 'Matbaa Ürün Şablonu',
                'category' => 'Matbaa Ürünleri',
                'key' => 'matbaa',
                'fields' => ['ebat: text cm', 'kağıt gramajı: number', 'baskı renk: select', 'cilt tipi: select', 'sayfa sayısı: number'],
                'purpose' => 'Matbaa katalog/export alanlarını ve import eşlemesini standartlaştırır.',
            ],
            [
                'name' => 'Genel Promosyon Şablonu',
                'category' => 'Diğer Promosyon Ürünleri',
                'key' => 'genel_promosyon',
                'fields' => ['renk: select', 'malzeme: select', 'ölçü: text cm', 'baskı tipi: multi'],
                'purpose' => 'Net kategori şablonu olmayan promosyon ürünlerinde güvenli ortak alan seti.',
            ],
        ];
    }

    private function decisionExportRow(CategoryCleanupDecision $decision): array
    {
        return [
            'current_id' => $decision->current_category_id,
            'current_code' => $decision->current_code,
            'current_name' => $decision->current_name,
            'current_path' => $decision->current_path,
            'product_count' => $decision->product_count,
            'mapping_count' => $decision->supplier_mapping_count,
            'proposed_action' => $decision->proposed_action,
            'proposed_code' => $decision->proposed_category_code,
            'proposed_name' => $decision->proposed_category_name,
            'proposed_path' => $decision->proposed_category_path,
            'risk_level' => $decision->risk_level,
            'reason' => $decision->reason,
            'decision_status' => $decision->decision_status,
        ];
    }

    private function csvValue(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function drinkCategoryName(string $text): string
    {
        if (Str::contains($text, ['matara'])) {
            return 'Mataralar';
        }

        if (Str::contains($text, ['kupa', 'seramik', 'porselen'])) {
            return 'Kupalar';
        }

        if (Str::contains($text, ['cam urun'])) {
            return 'Cam Ürünler';
        }

        if (Str::contains($text, ['french press'])) {
            return 'French Press / Özel İçecek Ürünleri';
        }

        if (Str::contains($text, ['termos'])) {
            return 'Termoslar';
        }

        return 'Diğer İçecek Ürünleri';
    }

    private function giftSetCategoryName(string $text, string $fallback): string
    {
        $map = [
            'vip' => 'VIP Setler',
            'kutulu' => 'Kutulu Setler',
            'kalemli' => 'Kalemli Setler',
            'defterli' => 'Defterli Setler',
            'termoslu' => 'Termoslu Setler',
            'teknolojik' => 'Teknolojik Setler',
            'hazir paket' => 'Hazır Paket Ürünler',
        ];

        foreach ($map as $needle => $label) {
            if (Str::contains($text, $needle)) {
                return $label;
            }
        }

        return Str::contains($text, ['hediyelik set', 'setler', 'promo set'])
            ? 'Hediyelik Setler'
            : $fallback;
    }

    private function suggestFeatureTemplateKey(string $text, string $path): string
    {
        $haystack = $this->normalizeName($text . ' ' . $path);

        return match (true) {
            Str::contains($haystack, ['kalem']) => 'kalem',
            Str::contains($haystack, ['defter', 'ajanda']) => 'defter',
            Str::contains($haystack, ['teknoloji', 'usb', 'powerbank', 'wireless', 'kablosuz']) => 'teknoloji',
            Str::contains($haystack, ['termos', 'matara', 'kupa', 'icecek']) => 'icecek',
            Str::contains($haystack, ['canta', 'çanta', 'tasima']) => 'canta',
            Str::contains($haystack, ['baskili kagit', 'mousepad', 'takvim', 'masa']) => 'baskili_kagit_masa',
            Str::contains($haystack, ['matbaa', 'print', 'etiket', 'afis', 'poster']) => 'matbaa',
            default => 'genel_promosyon',
        };
    }

    private function mappingRiskLevel(SupplierCategoryMapping $mapping, Collection $similar): string
    {
        if (($mapping->mapping_status ?? '') === 'conflict' || $similar->count() >= 3) {
            return 'Yüksek risk';
        }

        if (($mapping->confidence_score ?? 0) < 60 || in_array($mapping->decision_type, ['merge_candidate', 'twin_view'], true)) {
            return 'Orta risk';
        }

        return 'Düşük risk';
    }

    private function summarizeGroups(Collection $groups, bool $useCode = false): array
    {
        return $groups
            ->map(function (Collection $group, string $key) use ($useCode) {
                $first = $group->first();

                return [
                    'title' => $useCode ? $key : $first->name,
                    'count' => $group->count(),
                    'paths' => $group->pluck('full_path')->take(8)->values()->all(),
                ];
            })
            ->values()
            ->take(20)
            ->all();
    }

    private function initialDraftTree(): array
    {
        return [
            [
                'code' => 'PROMOSYON-URUNLERI',
                'name' => 'Promosyon Ürünleri',
                'family' => 'promotion',
                'children' => [
                    'Kalemler',
                    'Defter & Ajandalar',
                    'Teknolojik Ürünler',
                    'İçecek Ürünleri',
                    'Çanta & Taşıma Ürünleri',
                    'Ofis & Masaüstü Ürünleri',
                    'Anahtarlık, Rozet & Aksesuarlar',
                    'Hediyelik Setler',
                    'Ödül & Plaket Ürünleri',
                    'Saatler',
                    'Tekstil Ürünleri',
                    'Outdoor & Araç Ürünleri',
                    'Doğa Dostu Ürünler',
                    'Baskılı Kağıt & Masa Ürünleri',
                    'Ambalaj & Kutular',
                    'Temalı & Kişiye Özel Ürünler',
                    'Diğer Promosyon Ürünleri',
                    'Eşlenmemiş / Kategorisiz',
                ],
            ],
            [
                'code' => 'MATBAA-URUNLERI',
                'name' => 'Matbaa Ürünleri',
                'family' => 'print',
                'children' => [
                    'Kurumsal Kimlik',
                    'Tanıtım Baskıları',
                    'Çok Sayfalı Baskılar',
                    'Etiketler',
                    'Afiş & Poster',
                    'Kutu / Ambalaj',
                    'Takvimler',
                    'Diğer Matbaa Ürünleri',
                ],
            ],
        ];
    }

    private function draftItemIsDescendant(int $candidateParentId, CategoryTreeDraftItem $item): bool
    {
        $children = CategoryTreeDraftItem::query()
            ->where('draft_id', $item->draft_id)
            ->where('parent_id', $item->id)
            ->pluck('id');

        if ($children->contains($candidateParentId)) {
            return true;
        }

        foreach ($children as $childId) {
            $child = CategoryTreeDraftItem::query()->find($childId);

            if ($child && $this->draftItemIsDescendant($candidateParentId, $child)) {
                return true;
            }
        }

        return false;
    }

    private function buildDraftPathPreview(CategoryTreeDraftItem $item, CategoryTreeDraftItem $newParent): string
    {
        $segments = [$item->proposed_name];
        $current = $newParent;

        while ($current) {
            array_unshift($segments, $current->proposed_name);
            $current = $current->parent;
        }

        return implode(' / ', $segments);
    }

    private function normalizeName(string $value): string
    {
        $map = [
            'ç' => 'c', 'Ç' => 'c',
            'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's',
            'ü' => 'u', 'Ü' => 'u',
        ];

        $normalized = strtr($value, $map);
        $normalized = Str::lower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?: $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);
    }
}
