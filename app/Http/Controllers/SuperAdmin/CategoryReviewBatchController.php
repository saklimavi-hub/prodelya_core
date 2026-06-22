<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CategoryReviewDecision;
use App\Models\StandardCategory;
use App\Models\SupplierCategoryMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategoryReviewBatchController extends Controller
{
    public function show(Request $request, string $batch): View
    {
        $batchData = $this->loadBatch($batch);
        $rows = $this->decorateRows($batchData['rows'], $batch);
        $filters = [
            'supplier' => trim($request->string('supplier')->toString()),
            'risk_group' => trim($request->string('risk_group')->toString()),
            'decision_status' => trim($request->string('decision_status')->toString()),
            'suggested_class' => trim($request->string('suggested_class')->toString()),
            'target_state' => trim($request->string('target_state')->toString()),
            'q' => trim($request->string('q')->toString()),
        ];

        $filteredRows = $this->applyFilters($rows, $filters)->values();
        $summary = $this->summary($rows, $batchData);

        return view('super-admin.product-data-hub.category-review-batch', [
            'batch' => $batch,
            'batchData' => $batchData,
            'rows' => $filteredRows,
            'summary' => $summary,
            'filters' => $filters,
            'supplierOptions' => $rows->pluck('supplier')->filter()->unique()->sort()->values(),
            'riskOptions' => $rows->pluck('risk_group')->filter()->unique()->sort()->values(),
            'classOptions' => $rows->pluck('suggested_class')->filter()->unique()->sort()->values(),
        ]);
    }

    public function storeDecision(Request $request, string $batch): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_category_mapping_id' => 'required|exists:supplier_category_mappings,id',
            'supplier' => 'nullable|string',
            'supplier_category_code' => 'nullable|string',
            'supplier_category_name' => 'nullable|string',
            'supplier_category_path' => 'nullable|string',
            'suggested_target_category' => 'nullable|string',
            'final_target_category_id' => 'nullable|exists:standard_categories,id',
            'suggested_decision' => 'nullable|string',
            'final_decision' => 'required|in:map,alias,feature_attribute,separate_keep,reject,hold',
            'suggested_feature' => 'nullable|string',
            'final_feature' => 'nullable|string',
            'user_decision_status' => 'required|in:approved,changed,held,rejected,separate_keep',
            'user_note' => 'nullable|string|max:1000',
        ]);

        $targetCategory = !empty($validated['final_target_category_id'])
            ? StandardCategory::query()->findOrFail((int) $validated['final_target_category_id'])
            : null;

        if ($targetCategory) {
            abort_if(
                $targetCategory->isArchivedCategory() || !$targetCategory->isPermanentBackbone(),
                422,
                'Arşiv veya kalıcı omurga dışı kategori review karar hedefi olarak seçilemez.'
            );
        }

        $mapping = SupplierCategoryMapping::query()->findOrFail((int) $validated['supplier_category_mapping_id']);
        $suggestedTargetCategoryId = $this->resolveSuggestedTargetCategoryId($validated['suggested_target_category'] ?? null);

        CategoryReviewDecision::query()->updateOrCreate(
            [
                'batch_code' => $batch,
                'supplier_category_mapping_id' => $mapping->id,
            ],
            [
                'supplier' => $validated['supplier'] ?? $mapping->supplier?->name,
                'supplier_category_code' => $validated['supplier_category_code'] ?? $mapping->supplier_category_code,
                'supplier_category_name' => $validated['supplier_category_name'] ?? $mapping->source_category,
                'supplier_category_path' => $validated['supplier_category_path'] ?? $mapping->supplier_category_path,
                'suggested_target_category_id' => $suggestedTargetCategoryId,
                'final_target_category_id' => $targetCategory?->id,
                'suggested_decision' => $validated['suggested_decision'] ?? null,
                'final_decision' => $validated['final_decision'],
                'suggested_feature' => $validated['suggested_feature'] ?? null,
                'final_feature' => $validated['final_feature'] ?? null,
                'user_decision_status' => $validated['user_decision_status'],
                'user_note' => $validated['user_note'] ?? null,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
            ]
        );

        return redirect()
            ->route('admin.super.product-data-hub.category-review-batches.show', $batch)
            ->with('success', 'Review kararı kaydedildi. Mapping apply veya category refresh çalıştırılmadı.');
    }

    public function export(string $batch, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'json'], true), 404);

        $batchData = $this->loadBatch($batch);
        $rows = $this->decorateRows($batchData['rows'], $batch)->values();

        if ($format === 'json') {
            return response()->streamDownload(function () use ($rows, $batch) {
                echo json_encode([
                    'batch' => $batch,
                    'exported_at' => now()->toIso8601String(),
                    'row_count' => $rows->count(),
                    'rows' => $rows,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }, "category_review_batch_{$batch}_with_decisions.json", ['Content-Type' => 'application/json; charset=UTF-8']);
        }

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            $columns = [
                'priority',
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
                'final_target_category',
                'final_feature',
                'user_note',
                'decided_by',
                'decided_at',
            ];

            fputcsv($handle, $columns);
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? '', $columns));
            }
            fclose($handle);
        }, "category_review_batch_{$batch}_with_decisions.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function loadBatch(string $batch): array
    {
        $path = "product-data-hub/category-review/category_review_batch_{$batch}.json";

        abort_unless(Storage::disk('local')->exists($path), 404, "Review batch {$batch} bulunamadı.");

        $payload = json_decode(Storage::disk('local')->get($path), true) ?: [];

        return [
            'batch' => $payload['batch'] ?? $batch,
            'generated_at' => $payload['generated_at'] ?? null,
            'row_count' => (int) ($payload['row_count'] ?? count($payload['rows'] ?? [])),
            'source_path' => Storage::disk('local')->path($path),
            'rows' => collect($payload['rows'] ?? [])->map(fn (array $row) => $this->normalizeBatchRow($row))->values(),
        ];
    }

    private function normalizeBatchRow(array $row): array
    {
        $mappingId = $row['supplier_category_mapping_id'] ?? $row['mapping_id'] ?? null;

        if (!$mappingId) {
            $mappingId = SupplierCategoryMapping::query()
                ->where('source_category', $row['supplier_category_name'] ?? '')
                ->when(!empty($row['supplier_category_code']), fn ($query) => $query->where('supplier_category_code', $row['supplier_category_code']))
                ->value('id');
        }

        $row['supplier_category_mapping_id'] = $mappingId ? (int) $mappingId : null;

        return $row;
    }

    private function decorateRows(Collection $rows, string $batch): Collection
    {
        $mappingIds = $rows->pluck('supplier_category_mapping_id')->filter()->unique()->values();
        $decisions = CategoryReviewDecision::query()
            ->with(['finalTargetCategory', 'decidedBy'])
            ->where('batch_code', $batch)
            ->whereIn('supplier_category_mapping_id', $mappingIds->all())
            ->get()
            ->keyBy('supplier_category_mapping_id');

        return $rows->map(function (array $row) use ($decisions) {
            $decision = $decisions->get($row['supplier_category_mapping_id'] ?? null);

            return array_merge($row, [
                'user_decision' => $decision?->user_decision_status ?? ($row['user_decision'] ?? ''),
                'final_target_category' => $decision?->finalTargetCategory?->full_path ?? '',
                'final_target_category_id' => $decision?->final_target_category_id,
                'final_decision' => $decision?->final_decision ?? '',
                'final_feature' => $decision?->final_feature ?? ($row['suggested_feature'] ?? ''),
                'user_note' => $decision?->user_note ?? '',
                'decided_by' => $decision?->decidedBy?->name ?? '',
                'decided_at' => $decision?->decided_at?->format('Y-m-d H:i') ?? '',
                'decision_record_id' => $decision?->id,
            ]);
        });
    }

    private function applyFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when($filters['supplier'] !== '', fn (Collection $collection) => $collection->where('supplier', $filters['supplier']))
            ->when($filters['risk_group'] !== '', fn (Collection $collection) => $collection->where('risk_group', $filters['risk_group']))
            ->when($filters['suggested_class'] !== '', fn (Collection $collection) => $collection->where('suggested_class', $filters['suggested_class']))
            ->when($filters['decision_status'] !== '', function (Collection $collection) use ($filters) {
                return $filters['decision_status'] === 'pending'
                    ? $collection->filter(fn (array $row) => blank($row['user_decision'] ?? null))
                    : $collection->where('user_decision', $filters['decision_status']);
            })
            ->when($filters['target_state'] !== '', function (Collection $collection) use ($filters) {
                return $filters['target_state'] === 'with_target'
                    ? $collection->filter(fn (array $row) => filled($row['suggested_target_category'] ?? null))
                    : $collection->filter(fn (array $row) => blank($row['suggested_target_category'] ?? null));
            })
            ->when($filters['q'] !== '', function (Collection $collection) use ($filters) {
                $needle = mb_strtolower($filters['q'], 'UTF-8');

                return $collection->filter(function (array $row) use ($needle) {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $row['supplier'] ?? '',
                        $row['supplier_category_code'] ?? '',
                        $row['supplier_category_name'] ?? '',
                        $row['supplier_category_path'] ?? '',
                        $row['sample_products'] ?? '',
                        $row['suggested_target_category'] ?? '',
                    ])), 'UTF-8');

                    return str_contains($haystack, $needle);
                });
            });
    }

    private function summary(Collection $rows, array $batchData): array
    {
        return [
            'row_count' => $rows->count(),
            'generated_at' => $batchData['generated_at'],
            'source_path' => $batchData['source_path'],
            'risk_distribution' => $rows->countBy('risk_group')->all(),
            'supplier_distribution' => $rows->countBy('supplier')->all(),
            'pending' => $rows->filter(fn (array $row) => blank($row['user_decision'] ?? null))->count(),
            'approved' => $rows->where('user_decision', 'approved')->count(),
            'changed' => $rows->where('user_decision', 'changed')->count(),
            'held' => $rows->where('user_decision', 'held')->count(),
            'rejected' => $rows->where('user_decision', 'rejected')->count(),
            'separate_keep' => $rows->where('user_decision', 'separate_keep')->count(),
        ];
    }

    private function resolveSuggestedTargetCategoryId(?string $path): ?int
    {
        if (!filled($path)) {
            return null;
        }

        return StandardCategory::query()
            ->permanentBackbone()
            ->where(function ($query) use ($path) {
                $query->where('path', $path)
                    ->orWhere('name', $path)
                    ->orWhere('code', $path);
            })
            ->value('id');
    }
}
