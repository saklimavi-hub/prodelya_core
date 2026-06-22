<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CategoryAlias;
use App\Models\CategoryTwinView;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierCategoryMappingLog;
use App\Models\SupplierSource;
use App\Models\TenantCatalogProduct;
use App\Services\ProductDataHub\SupplierCategoryDiscoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SuperAdminCategoryMappingController extends Controller
{
    private const BULK_APPLY_CONFIRM = 'Seçili kategori eşlemelerini kaydetmek istiyorum.';

    private const RISKY_BULK_GROUPS = [
        'desk_sumen',
        'mousepad',
        'set_boxes',
        'calendar',
        'gift_sets',
        'cups',
        'accessory',
    ];

    public function index(Request $request): View
    {
        $mode = $request->string('mode')->toString();
        $viewMode = $request->string('view_mode')->toString();
        $search = trim($request->string('search')->toString());
        $search = $search !== '' ? $search : trim($request->string('q')->toString());
        
        // Default to simple mode unless explicitly advanced
        $isSimpleMode = !in_array($mode, ['advanced', 'detail'], true) && $viewMode !== 'detail';

        $filters = [
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'status' => $request->string('status')->toString(),
            'decision_type' => $request->string('decision_type')->toString(),
            'standard_category_id' => $request->integer('standard_category_id') ?: null,
            'search' => $search,
            'queue' => $request->string('queue')->toString() ?: 'queue',
            'review_group' => $request->string('review_group')->toString() ?: 'all',
            'view_mode' => in_array($viewMode, ['quick', 'detail'], true) ? $viewMode : 'quick',
            'limit' => in_array($request->integer('limit'), [25, 50, 100], true) ? $request->integer('limit') : 25,
            'mode' => $isSimpleMode ? 'simple' : 'advanced',
        ];

        $baseQuery = SupplierCategoryMapping::query()
            ->with(['supplier', 'source', 'standardCategory', 'reviewer'])
            ->orderByDesc('confidence_score')
            ->orderBy('supplier_id')
            ->orderBy('source_category');

        $statsQuery = clone $baseQuery;

        // Simple mode: only show pending/approval needed mappings
        if ($isSimpleMode && $filters['queue'] === 'queue' && $filters['status'] === '') {
            $baseQuery->whereIn('mapping_status', ['pending', 'target_missing', 'review_required', 'low_confidence']);
        }

        if ($filters['supplier_id']) {
            $baseQuery->where('supplier_id', $filters['supplier_id']);
        }

        if ($filters['status'] !== '') {
            $baseQuery->where('mapping_status', $filters['status']);
        }

        if ($filters['decision_type'] !== '') {
            $baseQuery->where('decision_type', $filters['decision_type']);
        }

        if ($filters['standard_category_id']) {
            $baseQuery->where('standard_category_id', $filters['standard_category_id']);
        }

        if ($filters['search'] !== '') {
            $baseQuery->where(function ($query) use ($filters) {
                $query->where('source_category', 'like', '%' . $filters['search'] . '%')
                    ->orWhere('supplier_category_code', 'like', '%' . $filters['search'] . '%')
                    ->orWhere('normalized_name', 'like', '%' . $filters['search'] . '%')
                    ->orWhere('target_category', 'like', '%' . $filters['search'] . '%')
                    ->orWhere('supplier_category_path', 'like', '%' . $filters['search'] . '%')
                    ->orWhere('sample_product_names', 'like', '%' . $filters['search'] . '%')
                    ->orWhereHas('supplier', function ($supplierQuery) use ($filters) {
                        $supplierQuery->where('name', 'like', '%' . $filters['search'] . '%')
                            ->orWhere('code', 'like', '%' . $filters['search'] . '%');
                    })
                    ->orWhereHas('source', function ($sourceQuery) use ($filters) {
                        $sourceQuery->where('source_name', 'like', '%' . $filters['search'] . '%');
                    });
            });
        }

        $this->applyQueueFilter($baseQuery, $filters['queue']);

        $mappings = $baseQuery->get()->map(function (SupplierCategoryMapping $mapping) {
            $suggestionMeta = (array) ($mapping->suggestion_meta ?? []);
            $mapping->sample_keywords_preview = array_slice((array) ($suggestionMeta['sample_keywords'] ?? []), 0, 8);
            $mapping->reason_codes = (array) ($suggestionMeta['reason_codes'] ?? []);
            $mapping->suggestion_reasons = $this->parseReasonText($mapping->description);

            return $this->decorateQuickMapping($mapping);
        });

        if ($filters['queue'] === 'risk_groups' && $filters['review_group'] !== 'all') {
            $mappings = $mappings
                ->filter(fn (SupplierCategoryMapping $mapping) => $mapping->quick_risk_group_key === $filters['review_group'])
                ->values();
        }

        $sources = SupplierSource::query()
            ->visibleInProductDataHub()
            ->with('supplier')
            ->orderBy('id')
            ->get();
        $standardCategories = StandardCategory::query()->permanentBackbone()->orderBy('path')->get();
        $suppliers = $this->buildSupplierFilterOptions($sources);
        $aliases = CategoryAlias::query()
            ->with(['standardCategory', 'supplier'])
            ->where('is_active', true)
            ->orderBy('alias_name')
            ->get();
        $twinViews = CategoryTwinView::query()
            ->with(['canonicalCategory', 'visibleParentCategory'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'mapped' => (clone $statsQuery)->whereNotNull('standard_category_id')->count(),
            'approved' => (clone $statsQuery)->where('mapping_status', 'approved')->count(),
            'pending' => (clone $statsQuery)->where('mapping_status', 'pending')->count(),
            'needs_review' => (clone $statsQuery)->whereIn('mapping_status', ['needs_review', 'conflict'])->count(),
            'high_confidence' => (clone $statsQuery)->where('mapping_status', 'auto_approved')->count(),
            'safe_apply' => (clone $statsQuery)
                ->where('suggestion_meta->safe_auto_approve', true)
                ->where(function ($query) {
                    $query->whereNull('suggestion_meta->review_required')
                        ->orWhere('suggestion_meta->review_required', false);
                })
                ->whereNotNull('standard_category_id')
                ->whereIn('decision_type', ['map', 'alias'])
                ->whereIn('mapping_status', ['pending', 'needs_review', 'auto_approved'])
                ->count(),
            'no_target' => (clone $statsQuery)->whereNull('standard_category_id')->count(),
            'review_required' => (clone $statsQuery)->where('suggestion_meta->review_required', true)->count(),
            'special_rule' => (clone $statsQuery)->whereNotNull('suggestion_meta->special_rule')->count(),
            'refresh_waiting_products' => StandardProduct::query()->whereNull('standard_category_id')->count(),
            'refresh_waiting_tenant_catalog' => TenantCatalogProduct::query()->whereNull('standard_category_id')->count(),
            'alias_candidate' => (clone $statsQuery)->where('decision_type', 'alias')->count(),
            'twin_candidate' => (clone $statsQuery)->where('decision_type', 'twin_view')->count(),
            'merge_candidate' => (clone $statsQuery)->where('decision_type', 'merge_candidate')->count(),
            'filter_candidate' => (clone $statsQuery)->where('decision_type', 'filter_candidate')->count(),
            'conflict_count' => (clone $statsQuery)->where('mapping_status', 'conflict')->count(),
            'approved_aliases' => $aliases->count(),
            'twin_views' => $twinViews->count(),
        ];

        $sourceStats = $this->buildSourceStats($sources);
        $reviewReport = $this->buildReviewReport($sources, $filters['review_group'], $filters['limit']);
        $cleanup = $this->buildCleanupPanels($standardCategories, $mappings);
        $workflowSteps = [
            ['no' => 1, 'title' => 'Tedarikçiden Oku', 'copy' => 'XML/JSON/CSV kategori listesini tarayın.'],
            ['no' => 2, 'title' => 'Standart Kategori Öner', 'copy' => 'Alias, ad benzerliği ve ürün sinyalleri ile skoru hesaplayın.'],
            ['no' => 3, 'title' => 'Karar Ver', 'copy' => 'Kabul et, alias yap, ikiz yap veya ayrı bırak.'],
            ['no' => 4, 'title' => 'Katalogda Yayınla', 'copy' => 'Temizlenen eşlemeyi standart kategori ağacına bağlayın.'],
        ];

        return view('super-admin.product-data-hub.category-mappings', compact(
            'mappings',
            'standardCategories',
            'suppliers',
            'sources',
            'aliases',
            'twinViews',
            'stats',
            'filters',
            'sourceStats',
            'reviewReport',
            'cleanup',
            'workflowSteps'
        ));
    }

    public function exportReview(Request $request, string $format): JsonResponse|StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'json'], true), 404);

        $sources = SupplierSource::query()
            ->visibleInProductDataHub()
            ->with('supplier')
            ->orderBy('id')
            ->get();
        $report = $this->buildReviewReport(
            $sources,
            $request->string('review_group')->toString() ?: 'all',
            10000
        );
        $rows = collect($report['rows'])->map(fn (array $row) => [
            'supplier' => $row['supplier'],
            'supplier_category_name' => $row['supplier_category_name'],
            'supplier_category_path' => $row['supplier_category_path'],
            'product_count' => $row['product_count'],
            'sample_products' => implode(' | ', $row['sample_products']),
            'current_status' => $row['current_status'],
            'suggested_class' => $row['suggested_class'],
            'suggested_target_category' => $row['suggested_target_category'],
            'suggested_decision' => $row['suggested_decision'],
            'risk_group' => $row['risk_group'],
            'risk_level' => $row['risk_level'],
            'reason' => $row['reason'],
        ])->values();

        if ($format === 'json') {
            return response()->json([
                'summary' => $report['summary'],
                'rows' => $rows,
            ]);
        }

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'supplier',
                'supplier_category_name',
                'supplier_category_path',
                'product_count',
                'sample_products',
                'current_status',
                'suggested_class',
                'suggested_target_category',
                'suggested_decision',
                'risk_group',
                'risk_level',
                'reason',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'category-review-list.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function categorySearch(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $categories = StandardCategory::query()
            ->permanentBackbone()
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', '%' . $term . '%')
                    ->orWhere('code', 'like', '%' . $term . '%')
                    ->orWhere('path', 'like', '%' . $term . '%');
            })
            ->orderBy('path')
            ->limit(25)
            ->get(['id', 'name', 'code', 'path']);

        return response()->json($categories->map(fn (StandardCategory $category) => [
            'id' => $category->id,
            'name' => $category->name,
            'code' => $category->code,
            'path' => $category->full_path,
        ])->values());
    }

    public function scan(Request $request, SupplierCategoryDiscoveryService $discoveryService): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_source_id' => 'nullable|exists:supplier_sources,id',
        ]);

        if (!empty($validated['supplier_source_id'])) {
            $source = SupplierSource::query()
                ->selectable()
                ->with('supplier')
                ->findOrFail($validated['supplier_source_id']);
            $result = $discoveryService->scanSource($source, persist: true);

            return redirect()
                ->route('admin.super.product-data-hub.category-mappings.index', ['supplier_id' => $source->supplier_id])
                ->with(
                    'success',
                    sprintf(
                        'Kategori taraması tamamlandı: %s için %d kategori bulundu, %d eşleme bekliyor, %d yüksek güvenli öneri üretildi.',
                        $source->supplier?->name ?? $source->source_name,
                        data_get($result, 'summary.category_count', 0),
                        data_get($result, 'summary.pending_count', 0),
                        data_get($result, 'summary.high_confidence_count', 0)
                    )
                );
        }

        $result = $discoveryService->scanAllActiveSources(true);

        return redirect()
            ->route('admin.super.product-data-hub.category-mappings.index')
            ->with(
                'success',
                sprintf(
                    'Tedarikçi kategori taraması tamamlandı: %d kaynakta %d kategori işlendi, %d yüksek güvenli öneri üretildi.',
                    data_get($result, 'totals.supplier_count', 0),
                    data_get($result, 'totals.supplier_category_count', 0),
                    data_get($result, 'totals.high_confidence_count', 0)
                )
            );
    }

    public function autoApprove(Request $request, SupplierCategoryDiscoveryService $discoveryService): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        $result = $discoveryService->autoApproveHighConfidence($validated['supplier_id'] ?? null);

        return redirect()
            ->route('admin.super.product-data-hub.category-mappings.index', array_filter([
                'supplier_id' => $validated['supplier_id'] ?? null,
            ]))
            ->with(
                'success',
                "Toplu öneri kabulü tamamlandı. Kabul edilen: {$result['approved']}, Alias oluşturulan: {$result['alias_created']}."
            );
    }

    public function update(Request $request, SupplierCategoryMapping $mapping): RedirectResponse
    {
        $mode = $request->string('mode')->toString() === 'advanced' ? 'advanced' : 'simple';
        $viewMode = $request->string('view_mode')->toString() === 'detail' ? 'detail' : 'quick';
        $redirectParams = $mode === 'advanced' || $viewMode === 'detail'
            ? $this->redirectModeParams($mode, $viewMode, true)
            : [];

        $validated = $request->validate([
            'standard_category_id' => 'nullable|exists:standard_categories,id',
            'mapping_status' => 'required|in:pending,mapped,ignored,needs_review,approved,rejected,auto_approved,conflict,cancelled',
            'decision_type' => 'nullable|in:map,alias,twin_view,merge_candidate,ignore,filter_candidate,separate,review',
            'confidence_score' => 'nullable|numeric|min:0|max:100',
            'note' => 'nullable|string',
        ]);

        $this->ensureTargetCategoryIsPermanent($validated['standard_category_id'] ?? null);
        $this->saveMappingDecision($mapping, $validated);

        return redirect()
            ->route('admin.super.product-data-hub.category-mappings.index', $redirectParams)
            ->with('success', 'Eşleme kaydedildi. Ürün listesine yansıtma ayrı adımda yapılır.');
    }

    public function accept(Request $request, SupplierCategoryMapping $mapping): RedirectResponse
    {
        $mode = $request->string('mode')->toString() === 'advanced' ? 'advanced' : 'simple';
        $viewMode = $request->string('view_mode')->toString() === 'detail' ? 'detail' : 'quick';
        $redirectParams = $this->redirectModeParams($mode, $viewMode, $mode === 'advanced' || $viewMode === 'detail');

        $mapping->loadMissing(['standardCategory', 'source']);

        $targetCategoryId = $mapping->standard_category_id;
        $this->ensureTargetCategoryIsPermanent($targetCategoryId);

        if (blank($targetCategoryId)) {
            return redirect()
                ->route('admin.super.product-data-hub.category-mappings.index', $redirectParams)
                ->with('error', 'Hedef kategori bulunamadı. Önce yeni omurgadan hedef kategori seçin.');
        }

        if ($this->isReviewRequired($mapping) && !$request->boolean('controlled_confirm')) {
            return redirect()
                ->route('admin.super.product-data-hub.category-mappings.index', $redirectParams)
                ->with('error', 'Kontrol gereken kayıtlar için kontrollü onay gerekir.');
        }

        $this->saveMappingDecision($mapping, [
            'standard_category_id' => $targetCategoryId,
            'mapping_status' => 'approved',
            'decision_type' => in_array($mapping->decision_type, ['map', 'alias'], true) ? $mapping->decision_type : 'map',
            'confidence_score' => $mapping->confidence_score,
            'note' => $mapping->decision_note ?: $mapping->description ?: 'Tek tık hızlı eşleme kabul edildi.',
        ]);

        return redirect()
            ->route('admin.super.product-data-hub.category-mappings.index', $redirectParams)
            ->with('success', 'Eşleme kaydedildi. Ürün listesine yansıtma ayrı adımda yapılır.');
    }

    public function cancel(Request $request, SupplierCategoryMapping $mapping): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $reason = $validated['reason'] ?? 'Eşleme kullanıcı tarafından iptal edildi.';

        SupplierCategoryMappingLog::query()->create([
            'mapping_id' => $mapping->id,
            'old_standard_category_id' => $mapping->standard_category_id,
            'new_standard_category_id' => null,
            'action' => 'cancelled',
            'reason' => $reason,
            'changed_by' => auth()->id(),
        ]);

        $mapping->update([
            'standard_category_id' => null,
            'target_category' => '',
            'mapping_status' => 'cancelled',
            'decision_type' => 'review',
            'decision_note' => $reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'is_active' => true,
        ]);

        return redirect()
            ->route('admin.super.product-data-hub.category-mappings.index', ['queue' => 'pending'])
            ->with('success', 'Kategori eşlemesi iptal edildi. Tedarikçi kategorisi tekrar kontrol kuyruğuna alındı.');
    }

    public function bulkUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mappings' => 'required|array',
            'mappings.*.standard_category_id' => 'nullable|exists:standard_categories,id',
            'mappings.*.mapping_status' => 'required|in:pending,mapped,ignored,needs_review,approved,rejected,auto_approved,conflict,cancelled',
            'mappings.*.decision_type' => 'nullable|in:map,alias,twin_view,merge_candidate,ignore,filter_candidate,separate,review',
            'mappings.*.confidence_score' => 'nullable|numeric|min:0|max:100',
            'mappings.*.note' => 'nullable|string',
        ]);

        $updated = 0;
        $skipped = 0;

        foreach ($validated['mappings'] as $mappingId => $payload) {
            $mapping = SupplierCategoryMapping::query()->find($mappingId);

            if (!$mapping) {
                $skipped++;
                continue;
            }

            $this->ensureTargetCategoryIsPermanent($payload['standard_category_id'] ?? null);
            $this->saveMappingDecision($mapping, $payload);
            $updated++;
        }

        return redirect()
            ->route('admin.super.product-data-hub.category-mappings.index')
            ->with('success', "Kategori eşlemeleri güncellendi. Güncellenen: {$updated}, Atlanan: {$skipped}.");
    }

    public function bulkApply(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mapping_ids' => 'required|array|min:1',
            'mapping_ids.*' => 'integer|exists:supplier_category_mappings,id',
            'mode' => 'required|in:selected,only_safe',
            'confirm' => 'required|string',
        ]);

        if ($validated['confirm'] !== self::BULK_APPLY_CONFIRM) {
            return redirect()
                ->route('admin.super.product-data-hub.category-mappings.index', ['view_mode' => 'quick'])
                ->with('error', 'Toplu eşleme için güçlü onay metni doğrulanamadı.');
        }

        $updated = 0;
        $skipped = 0;
        $skippedReasons = [];

        SupplierCategoryMapping::query()
            ->with(['standardCategory', 'source'])
            ->whereIn('id', array_unique($validated['mapping_ids']))
            ->get()
            ->each(function (SupplierCategoryMapping $mapping) use ($validated, &$updated, &$skipped, &$skippedReasons) {
                $decision = $this->bulkApplyDecision($mapping, $validated['mode']);

                if (!$decision['apply']) {
                    $skipped++;
                    $skippedReasons[$decision['reason']] = ($skippedReasons[$decision['reason']] ?? 0) + 1;
                    return;
                }

                $this->saveMappingDecision($mapping, [
                    'standard_category_id' => $mapping->standard_category_id,
                    'mapping_status' => 'approved',
                    'decision_type' => in_array($mapping->decision_type, ['map', 'alias'], true) ? $mapping->decision_type : 'map',
                    'confidence_score' => $mapping->confidence_score,
                    'note' => $mapping->decision_note ?: $mapping->description ?: 'Hızlı toplu eşleme ile kaydedildi.',
                ]);

                $updated++;
            });

        $reasonText = collect($skippedReasons)
            ->map(fn (int $count, string $reason) => "{$reason}: {$count}")
            ->implode(', ');

        return redirect()
            ->route('admin.super.product-data-hub.category-mappings.index', ['view_mode' => 'quick'])
            ->with(
                'success',
                trim("{$updated} eşleme kaydedildi, {$skipped} kayıt atlandı. Ürün/projection refresh çalıştırılmadı." . ($reasonText ? " Atlananlar: {$reasonText}." : ''))
            );
    }

    private function saveMappingDecision(SupplierCategoryMapping $mapping, array $payload): void
    {
        $oldStandardCategoryId = $mapping->standard_category_id;
        $category = !empty($payload['standard_category_id'])
            ? StandardCategory::query()->find($payload['standard_category_id'])
            : null;
        $decisionType = $payload['decision_type'] ?? $mapping->decision_type ?? 'map';
        $mappingStatus = $payload['mapping_status'];

        if (in_array($mappingStatus, ['rejected', 'ignored'], true)) {
            $category = null;
        }

        $mapping->update([
            'standard_category_id' => $category?->id,
            'target_category' => $category?->full_path ?? '',
            'mapping_status' => $mappingStatus,
            'decision_type' => $decisionType,
            'confidence_score' => $payload['confidence_score'] ?? $mapping->confidence_score,
            'description' => $payload['note'] ?? $mapping->description,
            'decision_note' => $payload['note'] ?? $mapping->decision_note,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        SupplierCategoryMappingLog::query()->create([
            'mapping_id' => $mapping->id,
            'old_standard_category_id' => $oldStandardCategoryId,
            'new_standard_category_id' => $mapping->standard_category_id,
            'action' => in_array($mappingStatus, ['approved', 'auto_approved', 'mapped'], true) ? 'approved' : 'changed',
            'reason' => $payload['note'] ?? $mapping->decision_note ?? $mapping->description,
            'changed_by' => auth()->id(),
        ]);

        if ($decisionType === 'alias' && $category && filled($mapping->source_category)) {
            CategoryAlias::query()->updateOrCreate(
                [
                    'standard_category_id' => $category->id,
                    'supplier_id' => $mapping->supplier_id,
                    'normalized_alias' => $this->normalizeText($mapping->source_category),
                ],
                [
                    'alias_name' => $mapping->source_category,
                    'source_type' => 'manual',
                    'confidence_score' => $payload['confidence_score'] ?? $mapping->confidence_score,
                    'is_active' => true,
                ]
            );
        }
    }

    private function applyQueueFilter($query, string $queue): void
    {
        match ($queue) {
            'queue', 'pending' => $query->where(function ($builder) {
                $builder->whereNull('mapping_status')
                    ->orWhereIn('mapping_status', ['pending', 'needs_review', 'conflict'])
                    ->orWhereNull('standard_category_id')
                    ->orWhere('suggestion_meta->review_required', true);
            })->whereNotIn('mapping_status', ['approved', 'auto_approved', 'mapped', 'rejected', 'ignored', 'cancelled']),
            'all' => null,
            'warning', 'review' => $query->whereIn('mapping_status', ['needs_review', 'conflict']),
            'alias' => $query->where('decision_type', 'alias'),
            'twin' => $query->where('decision_type', 'twin_view'),
            'merge' => $query->where('decision_type', 'merge_candidate'),
            'filter' => $query->where('decision_type', 'filter_candidate'),
            'cancelled' => $query->where('mapping_status', 'cancelled'),
            'rejected' => $query->whereIn('mapping_status', ['rejected', 'ignored']),
            'separate', 'separate_keep' => $query->where('decision_type', 'separate'),
            'approved', 'mapped' => $query->whereIn('mapping_status', ['approved', 'auto_approved', 'mapped']),
            'high_confidence', 'safe_approved' => $query
                ->where('suggestion_meta->safe_auto_approve', true)
                ->whereIn('mapping_status', ['approved', 'auto_approved', 'mapped']),
            'safe', 'safe_candidate' => $query
                ->where('suggestion_meta->safe_auto_approve', true)
                ->where(function ($builder) {
                    $builder->whereNull('suggestion_meta->review_required')
                        ->orWhere('suggestion_meta->review_required', false);
                })
                ->whereNotNull('standard_category_id')
                ->whereIn('decision_type', ['map', 'alias'])
                ->whereIn('mapping_status', ['pending', 'needs_review', 'conflict']),
            'no_target', 'target_missing' => $query->whereNull('standard_category_id'),
            'review_required' => $query->where('suggestion_meta->review_required', true),
            'special_rule' => $query->whereNotNull('suggestion_meta->special_rule'),
            'risk_groups' => $query->where(function ($builder) {
                $builder->whereNotNull('suggestion_meta->special_rule')
                    ->orWhereIn('suggestion_meta->risk_group_key', self::RISKY_BULK_GROUPS)
                    ->orWhereIn('suggestion_meta->risk_group', self::RISKY_BULK_GROUPS);
            }),
            default => null,
        };
    }

    private function decorateQuickMapping(SupplierCategoryMapping $mapping): SupplierCategoryMapping
    {
        $classification = $this->classifyReviewMapping($mapping);
        $targetCategory = $mapping->standardCategory;
        $targetIsPermanent = $targetCategory?->isPermanentBackbone() && !$targetCategory->isArchivedCategory();
        $reviewRequired = $this->isReviewRequired($mapping);

        $mapping->quick_status_label = $this->quickStatusLabel($mapping);
        $mapping->quick_status_badge = $this->quickStatusBadge($mapping);
        $mapping->quick_risk_group = $classification['risk_group'];
        $mapping->quick_risk_group_key = $classification['risk_group_key'];
        $mapping->quick_is_risky = $this->isRiskyBulkGroup($mapping);
        $mapping->quick_review_required = $reviewRequired;
        $mapping->quick_target_is_permanent = (bool) $targetIsPermanent;
        $mapping->quick_target_path = $targetIsPermanent ? ($targetCategory->full_path ?: $mapping->target_category) : '';
        $mapping->quick_target_id = $targetIsPermanent ? $targetCategory->id : null;
        $mapping->quick_is_safe = $this->isSafeBulkCandidate($mapping);
        $mapping->quick_can_accept = $targetIsPermanent && !in_array($mapping->mapping_status, ['approved', 'auto_approved', 'mapped', 'rejected', 'ignored', 'cancelled'], true);
        $mapping->quick_disabled_reason = $mapping->quick_can_accept
            ? ''
            : ($targetIsPermanent ? 'Bu kayıt artık kuyruk aksiyonu beklemiyor.' : 'Hedef kategori bulunamadı. Önce kategori seçin.');

        return $mapping;
    }

    private function bulkApplyDecision(SupplierCategoryMapping $mapping, string $mode): array
    {
        if (!$this->isQueueActionable($mapping)) {
            return ['apply' => false, 'reason' => 'kuyruk dışı'];
        }

        if (!$mapping->standardCategory || $mapping->standardCategory->isArchivedCategory() || !$mapping->standardCategory->isPermanentBackbone()) {
            return ['apply' => false, 'reason' => 'hedef yok/arşiv'];
        }

        if ($mode === 'only_safe' && !$this->isSafeBulkCandidate($mapping)) {
            return ['apply' => false, 'reason' => 'safe değil'];
        }

        if (!in_array($mapping->decision_type ?: 'map', ['map', 'alias'], true)) {
            return ['apply' => false, 'reason' => 'karar tipi uygun değil'];
        }

        if ($mode === 'only_safe' && ($this->isReviewRequired($mapping) || $this->isRiskyBulkGroup($mapping))) {
            return ['apply' => false, 'reason' => 'review/riskli'];
        }

        return ['apply' => true, 'reason' => 'uygulanabilir'];
    }

    private function isQueueActionable(SupplierCategoryMapping $mapping): bool
    {
        return !in_array($mapping->mapping_status, ['approved', 'auto_approved', 'mapped', 'rejected', 'ignored', 'cancelled'], true);
    }

    private function isSafeBulkCandidate(SupplierCategoryMapping $mapping): bool
    {
        $targetCategory = $mapping->standardCategory;

        return $this->isQueueActionable($mapping)
            && $targetCategory
            && $targetCategory->isPermanentBackbone()
            && !$targetCategory->isArchivedCategory()
            && (float) ($mapping->confidence_score ?? 0) >= 95
            && !$this->isReviewRequired($mapping)
            && !$this->isRiskyBulkGroup($mapping)
            && in_array($mapping->decision_type ?: 'map', ['map', 'alias'], true)
            && (bool) data_get($mapping->suggestion_meta, 'safe_auto_approve', false);
    }

    private function isReviewRequired(SupplierCategoryMapping $mapping): bool
    {
        return data_get($mapping->suggestion_meta, 'review_required') === true
            || in_array($mapping->mapping_status, ['needs_review', 'conflict'], true);
    }

    private function isRiskyBulkGroup(SupplierCategoryMapping $mapping): bool
    {
        $meta = (array) ($mapping->suggestion_meta ?? []);
        $riskGroup = $this->normalizeText((string) (data_get($meta, 'risk_group_key') ?: data_get($meta, 'risk_group') ?: data_get($meta, 'special_rule')));

        if (in_array($riskGroup, self::RISKY_BULK_GROUPS, true)) {
            return true;
        }

        $text = $this->normalizeText(implode(' ', array_filter([
            $mapping->source_category,
            $mapping->supplier_category_path,
            implode(' ', (array) ($mapping->sample_product_names ?? [])),
            data_get($meta, 'special_rule'),
        ])));

        return in_array($this->classifyRiskGroup($text, (string) data_get($meta, 'special_rule', ''))['key'], self::RISKY_BULK_GROUPS, true);
    }

    private function quickStatusLabel(SupplierCategoryMapping $mapping): string
    {
        return match ($mapping->mapping_status) {
            'approved', 'mapped' => 'Eşlendi',
            'auto_approved' => 'Otomatik Kabul Edildi',
            'needs_review', 'conflict' => 'Kontrol Gerekli',
            'cancelled' => 'İptal Edildi',
            'rejected', 'ignored' => 'Reddedildi',
            default => $mapping->standard_category_id ? 'Bekliyor' : 'Hedef Yok',
        };
    }

    private function quickStatusBadge(SupplierCategoryMapping $mapping): string
    {
        return match ($mapping->mapping_status) {
            'approved', 'mapped', 'auto_approved' => 'pd-badge-green',
            'needs_review', 'conflict' => 'pd-badge-amber',
            'cancelled' => 'pd-badge-gray',
            'rejected', 'ignored' => 'pd-badge-red',
            default => $mapping->standard_category_id ? 'pd-badge-blue' : 'pd-badge-red',
        };
    }

    private function buildSupplierFilterOptions(Collection $sources): Collection
    {
        return $sources
            ->filter(fn (SupplierSource $source) => $source->supplier !== null)
            ->sortBy(fn (SupplierSource $source) => $source->supplier?->name ?? $source->source_name)
            ->unique(fn (SupplierSource $source) => $this->normalizeText((string) ($source->supplier?->name ?? $source->source_name)))
            ->map(fn (SupplierSource $source) => $source->supplier)
            ->values();
    }

    private function buildSourceStats(Collection $sources): array
    {
        $mappingStats = SupplierCategoryMapping::query()
            ->selectRaw('supplier_source_id, COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN standard_category_id IS NOT NULL THEN 1 ELSE 0 END) as mapped_count')
            ->selectRaw("SUM(CASE WHEN mapping_status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN mapping_status = 'auto_approved' THEN 1 ELSE 0 END) as auto_approved_count")
            ->selectRaw("SUM(CASE WHEN mapping_status IN ('needs_review', 'conflict') THEN 1 ELSE 0 END) as review_count")
            ->groupBy('supplier_source_id')
            ->get()
            ->keyBy('supplier_source_id');

        return $sources
            ->groupBy(fn (SupplierSource $source) => $this->normalizeText((string) ($source->supplier?->name ?? $source->source_name)))
            ->map(function (Collection $supplierSources) use ($mappingStats) {
                $primarySource = $supplierSources->first();
                $sourceIds = $supplierSources->pluck('id');
                $aggregated = $sourceIds
                    ->map(fn (int $sourceId) => $mappingStats->get($sourceId))
                    ->filter();

                return [
                    'source_id' => $primarySource?->id,
                    'source_ids' => $sourceIds->values()->all(),
                    'supplier_name' => $primarySource?->supplier?->name ?? $primarySource?->source_name ?? 'Tedarikçi',
                    'source_name' => $primarySource?->source_name,
                    'category_count' => $aggregated->sum(fn ($stats) => (int) ($stats?->total_count ?? 0)),
                    'mapped_count' => $aggregated->sum(fn ($stats) => (int) ($stats?->mapped_count ?? 0)),
                    'pending_count' => $aggregated->sum(fn ($stats) => (int) ($stats?->pending_count ?? 0)),
                    'auto_approved_count' => $aggregated->sum(fn ($stats) => (int) ($stats?->auto_approved_count ?? 0)),
                    'review_count' => $aggregated->sum(fn ($stats) => (int) ($stats?->review_count ?? 0)),
                ];
            })
            ->sortBy('supplier_name')
            ->values()
            ->all();
    }

    private function buildReviewReport(Collection $sources, string $group = 'all', int $limit = 25): array
    {
        $sourceIds = $sources->pluck('id')->all();
        $query = SupplierCategoryMapping::query()
            ->with(['supplier', 'standardCategory'])
            ->whereIn('supplier_source_id', $sourceIds)
            ->whereNotIn('mapping_status', ['approved', 'auto_approved', 'mapped', 'rejected', 'ignored'])
            ->orderByDesc('product_count')
            ->orderBy('source_category');

        $allRows = $query->get()->map(fn (SupplierCategoryMapping $mapping) => $this->buildReviewRow($mapping));
        $filteredRows = $group === 'all'
            ? $allRows
            : $allRows->filter(fn (array $row) => $row['risk_group_key'] === $group || $row['suggested_class_key'] === $group);

        return [
            'summary' => [
                'total_reviewable' => $allRows->count(),
                'shown' => $filteredRows->take($limit)->count(),
                'limit' => $limit,
                'group' => $group,
                'by_class' => $allRows->countBy('suggested_class_key')->all(),
                'by_risk_group' => $allRows->countBy('risk_group_key')->all(),
            ],
            'supplier_summary' => $this->buildSupplierReviewSummary($sources),
            'groups' => [
                'all' => 'Tümü',
                'target_missing' => 'Hedef Bulunamayanlar',
                'desk_sumen' => 'Masa Sümeni',
                'mousepad' => 'Mousepad',
                'calendar' => 'Takvim',
                'set_boxes' => 'Set Kutuları',
                'gift_sets' => 'Hediyelik Setler',
                'cups' => 'Kupa',
                'accessory' => 'Aksesuar',
                'alias_candidate' => 'Alias Adayı',
                'feature_attribute' => 'Özellik / Filtre Adayı',
                'new_category_candidate' => 'Yeni Kategori Adayı',
                'manual_review' => 'Manuel Review',
            ],
            'rows' => $filteredRows->take($limit)->values()->all(),
        ];
    }

    private function buildSupplierReviewSummary(Collection $sources): array
    {
        $mappingStats = SupplierCategoryMapping::query()
            ->whereIn('supplier_source_id', $sources->pluck('id')->all())
            ->get()
            ->groupBy('supplier_source_id');

        return $sources
            ->groupBy(fn (SupplierSource $source) => $this->normalizeText((string) ($source->supplier?->name ?? $source->source_name)))
            ->map(function (Collection $supplierSources) use ($mappingStats) {
                $primarySource = $supplierSources->first();
                $sourceIds = $supplierSources->pluck('id');
                $stats = $sourceIds
                    ->flatMap(fn (int $sourceId) => $mappingStats->get($sourceId, collect()))
                    ->values();

                $problemSamples = SupplierCategoryMapping::query()
                    ->whereIn('supplier_source_id', $sourceIds->all())
                    ->where(function ($query) {
                        $query->whereNull('standard_category_id')
                            ->orWhereIn('mapping_status', ['pending', 'needs_review', 'conflict'])
                            ->orWhere('suggestion_meta->review_required', true);
                    })
                    ->whereNotIn('mapping_status', ['approved', 'auto_approved', 'mapped', 'rejected', 'ignored'])
                    ->orderByDesc('product_count')
                    ->limit(3)
                    ->pluck('source_category')
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'supplier_name' => $primarySource?->supplier?->name ?? $primarySource?->source_name ?? 'Tedarikçi',
                    'source_id' => $primarySource?->id,
                    'source_ids' => $sourceIds->values()->all(),
                    'total' => $stats->count(),
                    'approved' => $stats->whereIn('mapping_status', ['approved', 'auto_approved', 'mapped'])->count(),
                    'pending' => $stats->where('mapping_status', 'pending')->count(),
                    'review_required' => $stats->filter(fn (SupplierCategoryMapping $mapping) => in_array($mapping->mapping_status, ['needs_review', 'conflict'], true) || data_get($mapping->suggestion_meta, 'review_required') === true)->count(),
                    'target_missing' => $stats->whereNull('standard_category_id')->count(),
                    'problem_samples' => $problemSamples,
                ];
            })
            ->sortBy('supplier_name')
            ->values()
            ->all();
    }

    private function buildReviewRow(SupplierCategoryMapping $mapping): array
    {
        $classification = $this->classifyReviewMapping($mapping);

        return [
            'id' => $mapping->id,
            'supplier' => $mapping->supplier?->name ?? 'Tedarikçi',
            'supplier_category_name' => $mapping->source_category ?: '-',
            'supplier_category_path' => $mapping->supplier_category_path ?: 'Kategori yolu yok',
            'product_count' => (int) $mapping->product_count,
            'sample_products' => collect($mapping->sample_product_names ?? [])->take(3)->values()->all(),
            'current_status' => $mapping->standard_category_id ? ($mapping->mapping_status ?: 'pending') : 'target_missing',
            'suggested_class' => $classification['class'],
            'suggested_class_key' => $classification['class_key'],
            'suggested_target_category' => $classification['target'],
            'suggested_decision' => $classification['decision'],
            'risk_group' => $classification['risk_group'],
            'risk_group_key' => $classification['risk_group_key'],
            'risk_level' => $classification['risk_level'],
            'reason' => $classification['reason'],
        ];
    }

    private function classifyReviewMapping(SupplierCategoryMapping $mapping): array
    {
        $meta = (array) ($mapping->suggestion_meta ?? []);
        $text = $this->normalizeText(implode(' ', array_filter([
            $mapping->source_category,
            $mapping->supplier_category_path,
            implode(' ', (array) ($mapping->sample_product_names ?? [])),
            data_get($meta, 'special_rule'),
        ])));
        $specialRule = (string) data_get($meta, 'special_rule', '');
        $targetPath = $mapping->standardCategory?->isPermanentBackbone() ? $mapping->standardCategory->full_path : null;

        $risk = $this->classifyRiskGroup($text, $specialRule);
        $targetPath ??= $this->suggestTargetPathForRiskGroup($risk['key'], $text);

        if ($risk['key'] === 'set_boxes') {
            return $this->classification('feature_attribute', 'Özellik / filtre olmalı', $targetPath, 'Eşle veya Özellik/Filtre Yap', $risk, 'Set kutuları boş ambalaj mı ürünlü set mi kontrol edilmeli; review_required kalır.');
        }

        if ($risk['key'] === 'gift_sets') {
            return $this->classification('direct_match', 'Mevcut kategoriye doğrudan eşlenebilir', $targetPath, 'Eşle', $risk, 'Set alt türleri kategori değil Hediyelik Setler altında özellik olarak tutulmalı.');
        }

        if ($risk['key'] === 'cups') {
            return $this->classification('feature_attribute', 'Özellik / filtre olmalı', $targetPath, 'Eşle', $risk, 'Kupa malzemesi kategori değil malzeme özelliği olarak saklanmalı.');
        }

        if ($risk['key'] === 'calendar') {
            return $this->classification('direct_match', 'Mevcut kategoriye doğrudan eşlenebilir', $targetPath, 'Eşle', $risk, 'Takvimler promosyon altında değil Matbaa > Takvimler altında değerlendirilir.');
        }

        if ($risk['key'] === 'mousepad' || $risk['key'] === 'desk_sumen') {
            return $this->classification('manual_review', 'Manuel inceleme gerekir', $targetPath, 'Eşle', $risk, 'Ürün sinyalleri hedef ayrımını etkiliyor; kullanıcı kontrolü gerekir.');
        }

        if ($risk['key'] === 'accessory') {
            return $this->classification('direct_match', 'Mevcut kategoriye doğrudan eşlenebilir', $targetPath, 'Eşle', $risk, 'Açacak, Magnet ve Açacaklı Magnet ayrı hedeflerde kalmalı.');
        }

        if (($mapping->decision_type === 'alias' || data_get($meta, 'alias_candidate') === true) && $targetPath) {
            return $this->classification('alias_candidate', 'Mevcut kategoriye alias ile bağlanabilir', $targetPath, 'Alias Yap', $risk, 'Sistem alias sinyali üretti; yeni kategori açmadan hedefe bağlanabilir.');
        }

        if ($targetPath) {
            return $this->classification('direct_match', 'Mevcut kategoriye doğrudan eşlenebilir', $targetPath, 'Eşle', $risk, 'Kalıcı kategori hedefi var; operatör onayı bekliyor.');
        }

        if (str_contains($text, 'renk') || str_contains($text, 'metal') || str_contains($text, 'plastik') || str_contains($text, 'seramik') || str_contains($text, 'porselen')) {
            return $this->classification('feature_attribute', 'Özellik / filtre olmalı', '', 'Özellik/Filtre Yap', $risk, 'Kategori adı ürün özelliği gibi görünüyor; otomatik Diğer’e atanmadı.');
        }

        if (str_contains($text, 'diger') || str_contains($text, 'karisik') || str_contains($text, 'muhtelif')) {
            return $this->classification('manual_review', 'Manuel inceleme gerekir', '', 'Manuel incele', $risk, 'Genel/karışık tedarikçi kategorisi; otomatik Diğer hedefi verilmedi.');
        }

        return $this->classification('new_category_candidate', 'Yeni standart kategori önerisi gerekebilir', '', 'Yeni kategori gerektirir', $risk, 'Yeni omurgada net hedef bulunamadı; kullanıcı kararı gerekir.');
    }

    private function classifyRiskGroup(string $text, string $specialRule = ''): array
    {
        $rules = [
            'desk_sumen' => ['label' => 'Masa Sümeni', 'risk' => 'high', 'needles' => ['sumen', 'sümen', 'masa sumeni', 'masa sümeni', 'desk_sumen']],
            'mousepad' => ['label' => 'Mousepad', 'risk' => 'medium', 'needles' => ['mousepad', 'bardak altligi', 'bardak altlığı', 'wireless mousepad', 'kablosuz']],
            'calendar' => ['label' => 'Takvim', 'risk' => 'medium', 'needles' => ['takvim', 'gemici', 'piramit']],
            'gift_sets' => ['label' => 'Hediyelik Setler', 'risk' => 'low', 'needles' => ['hediyelik set', 'vip set', 'kutulu set', 'kalemli set', 'defterli set', 'termoslu set', 'teknolojik set', 'hazir paket', 'kurumsal set']],
            'cups' => ['label' => 'Kupa / Malzeme', 'risk' => 'low', 'needles' => ['kupa', 'seramik', 'porselen', 'cam kupa', 'metal kupa']],
            'set_boxes' => ['label' => 'Set Kutuları', 'risk' => 'high', 'needles' => ['set kutu', 'set kutusu', 'kutu', 'ambalaj', 'set_boxes']],
            'accessory' => ['label' => 'Açacak / Magnet', 'risk' => 'low', 'needles' => ['acacak', 'açacak', 'magnet', 'acacakli magnet', 'açacaklı magnet']],
        ];

        $haystack = trim($text . ' ' . $this->normalizeText($specialRule));

        foreach ($rules as $key => $rule) {
            foreach ($rule['needles'] as $needle) {
                if (str_contains($haystack, $this->normalizeText($needle))) {
                    return ['key' => $key, 'label' => $rule['label'], 'risk' => $rule['risk']];
                }
            }
        }

        return ['key' => 'general', 'label' => 'Genel Review', 'risk' => 'medium'];
    }

    private function suggestTargetPathForRiskGroup(string $riskGroup, string $text): string
    {
        $code = match ($riskGroup) {
            'gift_sets' => 'PROMO-HEDIYELIK-SET',
            'cups' => 'PROMO-ICECEK-KUPA',
            'calendar' => str_contains($text, 'gemici') ? 'PRINT-TAKVIM-GEMICI' : 'PRINT-TAKVIM',
            'mousepad' => (str_contains($text, 'wireless') || str_contains($text, 'kablosuz') || str_contains($text, 'sarj') || str_contains($text, 'qi'))
                ? 'PROMO-TEKNOLOJI-WIRELESS-MOUSEPAD'
                : (str_contains($text, 'bardak') ? 'PROMO-KAGIT-URETIM-BARDAK-ALTLIK' : 'PROMO-KAGIT-URETIM-KLASIK-MOUSEPAD'),
            'desk_sumen' => (str_contains($text, 'matbaa') || str_contains($text, 'takvim'))
                ? 'PRINT-TAKVIM-MASA-SUMENI'
                : ((str_contains($text, 'bloknot') || str_contains($text, 'baskili') || str_contains($text, 'kağıt') || str_contains($text, 'kagit'))
                    ? 'PROMO-KAGIT-URETIM-BASKILI-MASA-SUMENI'
                    : 'PROMO-OFIS-MASAUSTU-SUMEN'),
            'set_boxes' => 'PROMO-AMBALAJ-KUTU-SET',
            'accessory' => str_contains($text, 'acacakli') || str_contains($text, 'açacaklı')
                ? 'PROMO-AKSESUAR-ACACAKLI-MAGNET'
                : (str_contains($text, 'magnet') ? 'PROMO-AKSESUAR-MAGNET' : 'PROMO-AKSESUAR-ACACAK'),
            default => null,
        };

        if (!$code) {
            return '';
        }

        return StandardCategory::query()
            ->permanentBackbone()
            ->where('code', $code)
            ->value('path') ?: '';
    }

    private function classification(string $classKey, string $class, ?string $target, string $decision, array $risk, string $reason): array
    {
        return [
            'class_key' => $classKey,
            'class' => $class,
            'target' => $target ?: '',
            'decision' => $decision,
            'risk_group_key' => $risk['key'],
            'risk_group' => $risk['label'],
            'risk_level' => $risk['risk'],
            'reason' => $reason,
        ];
    }

    private function redirectModeParams(string $mode, string $viewMode, bool $preserveMode = false): array
    {
        return array_filter([
            'mode' => $preserveMode && $mode === 'advanced' ? 'advanced' : null,
            'view_mode' => $viewMode !== 'quick' ? $viewMode : ($preserveMode ? 'quick' : 'quick'),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function buildCleanupPanels(Collection $standardCategories, Collection $mappings): array
    {
        $duplicateNames = $standardCategories
            ->groupBy(fn (StandardCategory $category) => $this->normalizeText($category->name))
            ->filter(fn (Collection $group, string $key) => $key !== '' && $group->count() > 1)
            ->map(function (Collection $group) {
                return [
                    'title' => $group->first()->name,
                    'count' => $group->count(),
                    'paths' => $group->pluck('full_path')->take(4)->all(),
                ];
            })
            ->values()
            ->take(6)
            ->all();

        $repeatedParents = $standardCategories
            ->groupBy(fn (StandardCategory $category) => $this->normalizeText($category->name))
            ->filter(fn (Collection $group) => $group->pluck('parent_id')->filter()->unique()->count() > 1)
            ->map(function (Collection $group) {
                return [
                    'title' => $group->first()->name,
                    'parents' => $group->map(fn (StandardCategory $category) => $category->parent?->name ?: 'Kök')->unique()->take(4)->all(),
                ];
            })
            ->values()
            ->take(6)
            ->all();

        $filterCandidates = $mappings
            ->filter(fn (SupplierCategoryMapping $mapping) => $mapping->decision_type === 'filter_candidate')
            ->map(fn (SupplierCategoryMapping $mapping) => [
                'title' => $mapping->source_category,
                'reason' => $mapping->description ?: 'Detay alanı özellik / filtre katmanına taşınmalı.',
            ])
            ->take(6)
            ->values()
            ->all();

        $mergeCandidates = $mappings
            ->filter(fn (SupplierCategoryMapping $mapping) => $mapping->decision_type === 'merge_candidate')
            ->map(fn (SupplierCategoryMapping $mapping) => [
                'title' => $mapping->source_category,
                'target' => $mapping->target_category ?: 'Karar bekliyor',
            ])
            ->take(6)
            ->values()
            ->all();

        return [
            'duplicate_names' => $duplicateNames,
            'repeated_parents' => $repeatedParents,
            'filter_candidates' => $filterCandidates,
            'merge_candidates' => $mergeCandidates,
        ];
    }

    private function parseReasonText(?string $text): array
    {
        if (!filled($text)) {
            return [];
        }

        return collect(explode('·', (string) $text))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeText(string $value): string
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
        $normalized = mb_strtolower($normalized, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?: $normalized);
    }

    private function ensureTargetCategoryIsPermanent(mixed $categoryId): void
    {
        if (blank($categoryId)) {
            return;
        }

        $category = StandardCategory::query()->findOrFail((int) $categoryId);
        abort_if(
            $category->isArchivedCategory() || !$category->isPermanentBackbone(),
            422,
            'Arşiv veya kalıcı omurga dışında kalan kategoriler yeni eşleme hedefi olarak kullanılamaz.'
        );
    }
}
