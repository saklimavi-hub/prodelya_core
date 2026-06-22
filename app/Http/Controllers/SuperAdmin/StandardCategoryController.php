<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CategoryMoveLog;
use App\Models\CategoryAttributeRule;
use App\Models\ProductAttributeDefinition;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\SupplierCategoryMapping;
use App\Services\ProductDataHub\CategoryAttributeTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StandardCategoryController extends Controller
{
    public function __construct()
    {
        // TODO: Add middleware/permission: manage_product_data_hub
    }

    public function index(Request $request): View
    {
        $filters = [
            'product_family' => $request->string('product_family')->toString(),
            'status' => $request->string('status')->toString(),
            'archive_status' => $request->string('archive_status')->toString() ?: 'active',
            'search' => trim($request->string('search')->toString()),
        ];

        $query = StandardCategory::query()
            ->with('parent')
            ->withCount(['children', 'supplierCategoryMappings', 'standardProducts', 'attributeRules'])
            ->orderBy('product_family')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($filters['archive_status'] === 'archived') {
            $query->archived();
        } elseif ($filters['archive_status'] !== 'all') {
            $query->notArchived();
        }

        if ($filters['product_family'] !== '') {
            $query->forFamily($filters['product_family']);
        }

        if ($filters['status'] === 'active') {
            $query->active();
        } elseif ($filters['status'] === 'inactive') {
            $query->where('is_active', false);
        }

        if ($filters['search'] !== '') {
            $query->where(function ($builder) use ($filters) {
                $builder
                    ->where('name', 'like', '%' . $filters['search'] . '%')
                    ->orWhere('code', 'like', '%' . $filters['search'] . '%')
                    ->orWhere('path', 'like', '%' . $filters['search'] . '%');
            });
        }

        $categories = $query->get();
        $duplicateWarnings = $this->buildDuplicateWarnings($categories);
        $flattenedCategories = $this->flattenTree($categories)->map(function (StandardCategory $category) use ($duplicateWarnings) {
            $category->duplicate_warnings = $duplicateWarnings[$category->id] ?? [];

            return $category;
        });

        $selectedAttributeCategoryId = (int) $request->integer('attribute_category');
        $selectedAttributeCategory = $selectedAttributeCategoryId > 0
            ? StandardCategory::query()->find($selectedAttributeCategoryId)
            : $flattenedCategories->first();

        return view('super-admin.standard-categories.index', [
            'categories' => $flattenedCategories,
            'filters' => $filters,
            'moveParentOptions' => StandardCategory::query()->permanentBackbone()->orderBy('path')->get(['id', 'name', 'path', 'product_family', 'depth']),
            'stats' => [
                'total' => StandardCategory::query()->notArchived()->count(),
                'active' => StandardCategory::query()->notArchived()->where('is_active', true)->count(),
                'visible' => StandardCategory::query()->notArchived()->where('visible_in_catalog', true)->count(),
                'promotion' => StandardCategory::query()->notArchived()->where('product_family', 'promotion')->count(),
                'print' => StandardCategory::query()->notArchived()->where('product_family', 'print')->count(),
                'archived' => StandardCategory::query()->archived()->count(),
                'pending_mappings' => SupplierCategoryMapping::query()
                    ->where(function ($builder) {
                        $builder->whereNull('standard_category_id')
                            ->orWhereIn('mapping_status', ['pending', 'needs_review', 'conflict']);
                    })
                    ->count(),
            ],
            ...$this->buildAttributePanelData($selectedAttributeCategory),
        ]);
    }

    public function create(Request $request): View
    {
        $parent = null;
        if ($request->filled('parent_id')) {
            $parent = StandardCategory::query()->find((int) $request->input('parent_id'));
        }

        return view('super-admin.standard-categories.create', [
            'parents' => $this->parentOptions(),
            'category' => new StandardCategory([
                'parent_id' => $parent?->id,
                'product_family' => $parent?->product_family ?: ($request->string('product_family')->toString() ?: 'promotion'),
                'sort_order' => 0,
                'is_active' => true,
                'visible_in_catalog' => true,
                'requires_mapping' => true,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCategory($request);

        $category = DB::transaction(function () use ($validated) {
            $category = StandardCategory::query()->create($validated);
            $category->updatePath();

            return $category;
        });

        return redirect()
            ->route('admin.super.standard-categories.edit', $category)
            ->with('success', 'Standart kategori oluşturuldu.');
    }

    public function edit(StandardCategory $category): View
    {
        return view('super-admin.standard-categories.edit', [
            'category' => $category,
            'parents' => $this->parentOptions($category->id),
        ]);
    }

    public function update(Request $request, StandardCategory $category): RedirectResponse
    {
        $validated = $this->validateCategory($request, $category->id);

        DB::transaction(function () use ($category, $validated) {
            $category->update($validated);
            $category->updatePath();
        });

        return redirect()
            ->route('admin.super.standard-categories.edit', $category)
            ->with('success', 'Standart kategori güncellendi.');
    }

    public function destroy(StandardCategory $category): RedirectResponse
    {
        if ($category->isPermanentBackbone()) {
            return redirect()
                ->route('admin.super.standard-categories.index')
                ->with('warning', 'Kalıcı Prodelya kategori omurgasındaki kategoriler silinemez. Gerekirse katalogdan gizleyin veya pasife alın.');
        }

        if ($category->isArchivedCategory()) {
            return redirect()
                ->route('admin.super.standard-categories.index', ['archive_status' => 'archived'])
                ->with('warning', 'Arşiv kategoriler doğrudan silinmez veya aktif ağaca geri alınmaz. Eski bağlantıları inceleyip export alabilirsiniz.');
        }

        if (!$this->canDeleteCategory($category)) {
            return redirect()
                ->route('admin.super.standard-categories.index')
                ->with('warning', 'Bu kategori bağlı kayıtlar nedeniyle silinemez. Pasife alabilirsiniz.');
        }

        $category->delete();

        return redirect()
            ->route('admin.super.standard-categories.index')
            ->with('success', 'Kategori silindi.');
    }

    public function toggleActive(StandardCategory $category): RedirectResponse
    {
        if ($category->isArchivedCategory()) {
            return redirect()
                ->route('admin.super.standard-categories.index', ['archive_status' => 'archived'])
                ->with('warning', 'Arşiv kategori doğrudan aktif yapılamaz. Eski ağacı yeni omurgaya karıştırmamak için geri yükleme bakım akışı gereklidir.');
        }

        $newState = !$category->is_active;

        $category->update([
            'is_active' => $newState,
        ]);

        $message = $newState ? 'Kategori aktif edildi.' : 'Kategori pasife alındı.';

        if (!$newState && $category->hasChildren()) {
            $this->cascadeActiveState($category, false);

            return redirect()
                ->route('admin.super.standard-categories.index')
                ->with('warning', 'Kategori pasife alındı. Alt kategoriler de pasife çekildi.');
        }

        return redirect()
            ->route('admin.super.standard-categories.index')
            ->with('success', $message);
    }

    public function toggleCatalog(StandardCategory $category): RedirectResponse
    {
        $category->update([
            'visible_in_catalog' => !$category->visible_in_catalog,
        ]);

        return redirect()
            ->route('admin.super.standard-categories.index')
            ->with(
                'success',
                $category->visible_in_catalog
                    ? 'Kategori katalogda görünür yapıldı.'
                    : 'Kategori katalogdan gizlendi.'
            );
    }

    public function move(Request $request, StandardCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'new_parent_id' => 'nullable|exists:standard_categories,id',
            'new_sort_order' => 'nullable|integer',
            'product_family' => 'required|in:promotion,print',
            'notes' => 'nullable|string',
            'confirm_deep_move' => 'nullable|boolean',
        ]);

        $newParentId = $validated['new_parent_id'] ?? null;
        $newParent = $newParentId ? StandardCategory::query()->findOrFail((int) $newParentId) : null;

        if ($newParent && $newParent->id === $category->id) {
            throw ValidationException::withMessages([
                'new_parent_id' => 'Bir kategori kendisinin altına taşınamaz.',
            ]);
        }

        if ($newParent && ($newParent->isArchivedCategory() || !$newParent->isPermanentBackbone())) {
            throw ValidationException::withMessages([
                'new_parent_id' => 'Yeni parent yalnız kalıcı aktif kategori omurgasından seçilebilir.',
            ]);
        }

        if ($newParent && $this->wouldCreateCycle($category->id, $newParent->id)) {
            throw ValidationException::withMessages([
                'new_parent_id' => 'Bir kategori kendi alt kırılımının altına taşınamaz.',
            ]);
        }

        if ($newParent && $newParent->product_family !== $validated['product_family']) {
            throw ValidationException::withMessages([
                'product_family' => 'Yeni parent ile kategori aynı ürün ailesinde olmalıdır.',
            ]);
        }

        $newDepth = $newParent ? $newParent->depth + 1 : 0;
        if ($newDepth >= 4 && !(bool) ($validated['confirm_deep_move'] ?? false)) {
            throw ValidationException::withMessages([
                'confirm_deep_move' => 'Bu kategori 4. seviye veya daha derine taşınıyor. Prodelya standardında detaylar kategori yerine özellik/filtre olarak yönetilmelidir.',
            ]);
        }

        $oldPath = $category->full_path;
        $oldParentId = $category->parent_id;
        $oldSortOrder = $category->sort_order;
        $newSortOrder = (int) ($validated['new_sort_order'] ?? $category->sort_order);
        $newPath = $newParent
            ? trim($newParent->full_path . ' / ' . $category->name, ' /')
            : $category->name;

        DB::transaction(function () use (
            $category,
            $validated,
            $newParent,
            $newSortOrder,
            $oldPath,
            $newPath,
            $oldParentId,
            $oldSortOrder
        ) {
            $category->update([
                'parent_id' => $newParent?->id,
                'sort_order' => $newSortOrder,
                'product_family' => $validated['product_family'],
            ]);
            $category->updatePath();

            CategoryMoveLog::query()->create([
                'category_id' => $category->id,
                'old_parent_id' => $oldParentId,
                'new_parent_id' => $newParent?->id,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'old_sort_order' => $oldSortOrder,
                'new_sort_order' => $newSortOrder,
                'moved_by' => auth()->id(),
                'moved_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return redirect()
            ->route('admin.super.standard-categories.index')
            ->with('success', "Kategori taşındı: {$oldPath} -> {$newPath}");
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'integer|exists:standard_categories,id',
            'bulk_action' => 'required|in:deactivate,activate,hide_catalog,show_catalog,safe_delete',
        ]);

        $categories = StandardCategory::query()
            ->withCount(['children', 'supplierCategoryMappings', 'standardProducts'])
            ->whereIn('id', $validated['category_ids'])
            ->get();

        $affected = 0;
        $blocked = 0;

        foreach ($categories as $category) {
            if ($category->isArchivedCategory() || ($category->isPermanentBackbone() && $validated['bulk_action'] === 'safe_delete')) {
                $blocked++;
                continue;
            }

            switch ($validated['bulk_action']) {
                case 'deactivate':
                    $category->update(['is_active' => false]);
                    $this->cascadeActiveState($category, false);
                    $affected++;
                    break;
                case 'activate':
                    $category->update(['is_active' => true]);
                    $affected++;
                    break;
                case 'hide_catalog':
                    $category->update(['visible_in_catalog' => false]);
                    $affected++;
                    break;
                case 'show_catalog':
                    $category->update(['visible_in_catalog' => true]);
                    $affected++;
                    break;
                case 'safe_delete':
                    if ($this->canDeleteCategory($category)) {
                        $category->delete();
                        $affected++;
                    } else {
                        $blocked++;
                    }
                    break;
            }
        }

        $messages = [
            'deactivate' => "{$affected} kategori pasife alındı.",
            'activate' => "{$affected} kategori aktif edildi.",
            'hide_catalog' => "{$affected} kategori katalogdan gizlendi.",
            'show_catalog' => "{$affected} kategori katalogda görünür yapıldı.",
            'safe_delete' => "{$affected} kategori güvenli şekilde silindi.",
        ];

        $flashType = $blocked > 0 ? 'warning' : 'success';
        $message = $messages[$validated['bulk_action']];

        if ($blocked > 0) {
            $message .= " {$blocked} kategori bağlı kayıt nedeniyle silinemedi.";
        }

        return redirect()
            ->route('admin.super.standard-categories.index')
            ->with($flashType, $message);
    }

    public function updateOrder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'orders' => 'required|array|min:1',
            'orders.*' => 'nullable|integer',
        ]);

        $updated = 0;
        foreach ($validated['orders'] as $categoryId => $sortOrder) {
            $category = StandardCategory::query()->find($categoryId);
            if (!$category) {
                continue;
            }

            $category->update([
                'sort_order' => (int) $sortOrder,
            ]);
            $updated++;
        }

        return redirect()
            ->route('admin.super.standard-categories.index')
            ->with('success', "{$updated} kategori için sıra güncellendi.");
    }

    public function cleanupUnused(): RedirectResponse
    {
        $categories = StandardCategory::query()
            ->withCount(['children', 'supplierCategoryMappings', 'standardProducts'])
            ->where('is_active', false)
            ->get();

        $deleted = 0;
        foreach ($categories as $category) {
            if ($this->canDeleteCategory($category)) {
                $category->delete();
                $deleted++;
            }
        }

        return redirect()
            ->route('admin.super.standard-categories.index')
            ->with('success', "Bağlantısız pasif kategoriler temizlendi. Silinen: {$deleted}.");
    }

    public function showAttributes(StandardCategory $category): View
    {
        $category->load(['parent', 'attributeRules.attributeDefinition']);

        return view('super-admin.standard-categories.attributes', [
            'category' => $category,
            'attributeDefinitions' => ProductAttributeDefinition::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'attributeRules' => $category->attributeRules->keyBy('product_attribute_definition_id'),
            'templates' => $this->attributeTemplateService()->getTemplates(),
        ]);
    }

    public function updateAttributes(Request $request, StandardCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'attributes' => 'nullable|array',
            'attributes.*.enabled' => 'nullable',
            'attributes.*.is_required' => 'nullable',
            'attributes.*.is_filterable' => 'nullable',
            'attributes.*.visible_in_catalog' => 'nullable',
            'attributes.*.sort_order' => 'nullable|integer',
        ]);

        $definitions = ProductAttributeDefinition::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $attributes = collect($validated['attributes'] ?? []);

        DB::transaction(function () use ($attributes, $definitions, $category) {
            $attributes->each(function ($payload, $definitionId) use ($definitions, $category) {
                $definitionId = (int) $definitionId;
                if (!$definitions->has($definitionId)) {
                    return;
                }

                $isEnabled = (bool) ($payload['enabled'] ?? false);
                if (!$isEnabled) {
                    $category->attributeRules()
                        ->where('product_attribute_definition_id', $definitionId)
                        ->delete();

                    return;
                }

                $definition = $definitions->get($definitionId);
                $category->attributeRules()->updateOrCreate(
                    ['product_attribute_definition_id' => $definitionId],
                    [
                        'is_required' => (bool) ($payload['is_required'] ?? $definition->is_required),
                        'is_filterable' => (bool) ($payload['is_filterable'] ?? $definition->is_filterable),
                        'visible_in_catalog' => (bool) ($payload['visible_in_catalog'] ?? true),
                        'sort_order' => (int) ($payload['sort_order'] ?? $definition->sort_order),
                        'meta' => [
                            'updated_from_ui' => true,
                        ],
                    ]
                );
            });
        });

        return redirect()
            ->route('admin.super.standard-categories.attributes', $category)
            ->with('success', 'Kategori özellik kuralları güncellendi.');
    }

    public function applyAttributeTemplate(Request $request, StandardCategory $category): RedirectResponse
    {
        $templates = $this->attributeTemplateService()->getTemplates();
        $validated = $request->validate([
            'template_key' => ['required', 'string', Rule::in(array_keys($templates))],
        ]);

        $applied = $this->attributeTemplateService()->applyTemplate($category, $validated['template_key']);

        return redirect()
            ->route('admin.super.standard-categories.attributes', $category)
            ->with('success', "{$applied} özellik kuralı şablondan uygulandı.");
    }

    public function bulkPaste(): View
    {
        return view('super-admin.standard-categories.bulk-paste');
    }

    public function bulkPastePreview(Request $request): View
    {
        $validated = $request->validate([
            'bulk_text' => 'required|string',
        ]);

        $rows = $this->parseBulkLines($validated['bulk_text']);

        return view('super-admin.standard-categories.bulk-preview', [
            'bulkText' => $validated['bulk_text'],
            'rows' => $rows,
            'encodedRows' => base64_encode(json_encode($rows, JSON_UNESCAPED_UNICODE)),
        ]);
    }

    public function bulkPasteStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rows_payload' => 'required|string',
        ]);

        $rows = json_decode(base64_decode($validated['rows_payload'], true) ?: '[]', true);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, &$created, &$updated, &$skipped) {
            $pendingRows = collect($rows)
                ->reject(fn ($row) => ($row['status'] ?? 'ok') === 'error')
                ->values();

            $skipped += count($rows) - $pendingRows->count();

            $attempts = 0;
            while ($pendingRows->isNotEmpty() && $attempts < max($pendingRows->count() + 1, 2)) {
                $attempts++;
                $processedThisRound = 0;

                $pendingRows = $pendingRows->reject(function (array $row) use (&$created, &$updated, &$processedThisRound, &$skipped) {
                    $parentId = null;
                    if (!empty($row['parent_code'])) {
                        $parent = StandardCategory::query()->where('code', $row['parent_code'])->first();
                        if (!$parent) {
                            return false;
                        }

                        if ($parent->product_family !== $row['product_family']) {
                            $skipped++;

                            return true;
                        }

                        $parentId = $parent->id;
                    }

                    $payload = [
                        'parent_id' => $parentId,
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'slug' => StandardCategory::generateSlug($row['name']),
                        'product_family' => $row['product_family'],
                        'sort_order' => (int) ($row['sort_order'] ?? 999),
                        'is_active' => true,
                        'visible_in_catalog' => true,
                        'requires_mapping' => true,
                    ];

                    $existing = StandardCategory::query()->where('code', $row['code'])->first();
                    if ($existing) {
                        $existing->update($payload);
                        $existing->updatePath();
                        $updated++;
                    } else {
                        $category = StandardCategory::query()->create($payload);
                        $category->updatePath();
                        $created++;
                    }

                    $processedThisRound++;

                    return true;
                })->values();

                if ($processedThisRound === 0) {
                    break;
                }
            }

            $skipped += $pendingRows->count();
        });

        return redirect()
            ->route('admin.super.standard-categories.index')
            ->with('success', "{$created} kategori eklendi, {$updated} kategori güncellendi, {$skipped} satır hata nedeniyle işlenmedi.");
    }

    public function import(): View
    {
        return view('super-admin.standard-categories.import');
    }

    public function template(): Response
    {
        $template = implode("\n", [
            'code;name;parent_code;product_family;sort_order',
            'PROMO;Promosyon Ürünleri;;promotion;1',
            'PROMO-KALEMLER;Kalemler;PROMO;promotion;10',
            'PROMO-KALEMLER-PLASTIK;Plastik Kalemler;PROMO-KALEMLER;promotion;100',
            'PRINT;Matbaa Teklif ve Sipariş Ürünleri;;print;2',
        ]);

        return response($template, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=standard-category-template.txt',
        ]);
    }

    private function validateCategory(Request $request, ?int $categoryId = null): array
    {
        $normalizedCode = $this->normalizeCategoryCode($request->input('code', ''));
        $normalizedSlug = trim((string) $request->input('slug', ''));

        $request->merge([
            'code' => $normalizedCode,
            'slug' => $normalizedSlug !== '' ? StandardCategory::generateSlug($normalizedSlug) : null,
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                Rule::unique('standard_categories', 'code')->ignore($categoryId),
            ],
            'parent_id' => 'nullable|exists:standard_categories,id',
            'product_family' => 'required|in:promotion,print',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'visible_in_catalog' => 'nullable|boolean',
            'requires_mapping' => 'nullable|boolean',
        ]);

        if (!empty($validated['parent_id'])) {
            $parentId = (int) $validated['parent_id'];
            $parent = StandardCategory::query()->find($parentId);

            if ($parent && ($parent->isArchivedCategory() || !$parent->isPermanentBackbone())) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Üst kategori yalnız kalıcı aktif kategori omurgasından seçilebilir.',
                ]);
            }

            if ($parent && $parent->product_family !== $validated['product_family']) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Üst kategori ile alt kategori aynı ürün ailesinde olmalıdır.',
                ]);
            }

            if ($parentId === $categoryId) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Bir kategori kendisini üst kategori yapamaz.',
                ]);
            }

            if ($categoryId !== null && $this->wouldCreateCycle($categoryId, $parentId)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Bu üst kategori seçimi döngü oluşturur.',
                ]);
            }
        }

        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);
        $validated['visible_in_catalog'] = (bool) ($validated['visible_in_catalog'] ?? false);
        $validated['requires_mapping'] = (bool) ($validated['requires_mapping'] ?? false);
        $validated['slug'] = $validated['slug'] ?: StandardCategory::generateSlug($validated['name']);

        return $validated;
    }

    private function parentOptions(?int $ignoreId = null)
    {
        return StandardCategory::query()
            ->permanentBackbone()
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->orderBy('path')
            ->get();
    }

    private function flattenTree($categories)
    {
        $grouped = $categories->groupBy('parent_id');
        $result = collect();

        $walk = function ($parentId = null) use (&$walk, $grouped, $result) {
            foreach ($grouped->get($parentId, collect())->sortBy(fn ($category) => sprintf('%08d-%s', $category->sort_order, $category->name)) as $category) {
                $result->push($category);
                $walk($category->id);
            }
        };

        $walk();

        return $result;
    }

    private function parseBulkLines(string $bulkText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $bulkText) ?: [];
        $knownCodes = StandardCategory::query()->pluck('code')->all();
        $parsedCodes = [];
        $preparedRows = [];
        $rows = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($index === 0 && preg_match('/^code\s*;\s*name\s*;\s*parent_code\s*;\s*product_family\s*;\s*sort_order$/i', $trimmed)) {
                continue;
            }

            $parts = array_map('trim', explode(';', $trimmed));
            $parts = array_pad($parts, 5, '');

            [$code, $name, $parentCode, $productFamily, $sortOrder] = $parts;
            $preparedRows[] = [
                'line' => $index + 1,
                'code' => $this->normalizeCategoryCode($code),
                'name' => $name,
                'parent_code' => $this->normalizeCategoryCode($parentCode),
                'product_family' => trim($productFamily),
                'sort_order' => is_numeric($sortOrder) ? (int) $sortOrder : 999,
            ];
        }

        $allPayloadCodes = collect($preparedRows)->pluck('code')->filter()->all();
        $payloadRowMap = collect($preparedRows)->keyBy('code');

        foreach ($preparedRows as $row) {
            $lineNumber = $row['line'];
            $code = $row['code'];
            $name = $row['name'];
            $parentCode = $row['parent_code'];
            $productFamily = $row['product_family'];
            $sortOrder = $row['sort_order'];

            $warnings = [];
            $status = 'ok';
            $existing = in_array($code, $knownCodes, true);

            if ($code === '' || $name === '' || $productFamily === '') {
                $warnings[] = 'Kod, kategori adı ve ürün ailesi zorunludur.';
                $status = 'error';
            }

            if (!in_array($productFamily, ['promotion', 'print'], true)) {
                $warnings[] = 'Ürün ailesi promotion veya print olmalıdır.';
                $status = 'error';
            }

            if ($existing) {
                $warnings[] = 'Kod zaten var. Mevcut kayıt güncellenecek.';
                $status = $status === 'error' ? 'error' : 'warning';
            } else {
                $warnings[] = 'Yeni kayıt.';
            }

            if (in_array($code, $parsedCodes, true)) {
                $warnings[] = 'Aynı payload içinde duplicate kod var.';
                $status = 'error';
            }

            if ($parentCode !== '' && !in_array($parentCode, $knownCodes, true) && !in_array($parentCode, $allPayloadCodes, true)) {
                $warnings[] = 'Üst kategori bulunamadı.';
                $status = $status === 'error' ? 'error' : 'warning';
            }

            $existingParent = null;
            if ($parentCode !== '') {
                $existingParent = StandardCategory::query()->where('code', $parentCode)->first();
            }

            if ($existingParent && $existingParent->product_family !== $productFamily) {
                $warnings[] = 'Üst kategori ürün ailesi uyuşmuyor.';
                $status = 'error';
            } elseif (!$existingParent && $parentCode !== '' && $payloadRowMap->has($parentCode)) {
                $parentPayload = $payloadRowMap->get($parentCode);
                if (($parentPayload['product_family'] ?? null) !== $productFamily) {
                    $warnings[] = 'Üst kategori ürün ailesi uyuşmuyor.';
                    $status = 'error';
                }
            }

            $parsedCodes[] = $code;

            $rows[] = [
                'line' => $lineNumber,
                'code' => $code,
                'name' => $name,
                'parent_code' => $parentCode,
                'product_family' => $productFamily,
                'sort_order' => is_numeric($sortOrder) ? (int) $sortOrder : 999,
                'status' => $status,
                'warning' => implode(' ', array_unique($warnings)),
                'is_existing' => $existing,
            ];
        }

        return $rows;
    }

    private function buildDuplicateWarnings(Collection $categories): array
    {
        $warnings = [];
        $groups = $categories->groupBy(fn (StandardCategory $category) => ($category->parent_id ?? 0) . '|' . $category->product_family);

        foreach ($groups as $group) {
            foreach ($group as $category) {
                $currentWarnings = [];
                $normalizedName = $this->normalizeCategoryKey($category->name);

                foreach ($group as $candidate) {
                    if ($candidate->id === $category->id) {
                        continue;
                    }

                    $candidateNormalized = $this->normalizeCategoryKey($candidate->name);
                    $similarity = 0.0;
                    if ($normalizedName !== '' && $candidateNormalized !== '') {
                        similar_text($normalizedName, $candidateNormalized, $similarity);
                    }

                    if ($category->code === $candidate->code) {
                        $currentWarnings[] = 'Aynı Kod';
                    }

                    if ($normalizedName !== '' && $normalizedName === $candidateNormalized) {
                        $currentWarnings[] = 'Aynı İsim';
                    } elseif (
                        $normalizedName !== ''
                        && $candidateNormalized !== ''
                        && (
                            str_contains($normalizedName, $candidateNormalized)
                            || str_contains($candidateNormalized, $normalizedName)
                            || $similarity >= 75
                        )
                    ) {
                        $currentWarnings[] = 'Benzer Ad';
                    }
                }

                if (!empty($currentWarnings)) {
                    $currentWarnings[] = 'Aynı Parent Altında Benzer';
                }

                $warnings[$category->id] = array_values(array_unique($currentWarnings));
            }
        }

        return $warnings;
    }

    private function normalizeCategoryKey(string $value): string
    {
        $normalized = StandardCategory::generateSlug($value);
        $normalized = str_replace(['-', '_', 'urunleri', 'urunler', 'kategori'], '', $normalized);

        return Str::lower(trim($normalized));
    }

    private function normalizeCategoryCode(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $normalized = Str::upper(StandardCategory::generateSlug($trimmed));
        $normalized = preg_replace('/[^A-Z0-9-]/', '', $normalized) ?: '';
        $normalized = preg_replace('/-+/', '-', $normalized) ?: '';

        return trim($normalized, '-');
    }

    private function canDeleteCategory(StandardCategory $category): bool
    {
        $childrenCount = (int) ($category->children_count ?? $category->children()->count());
        $mappingCount = (int) ($category->supplier_category_mappings_count ?? $category->supplierCategoryMappings()->count());
        $standardProductsCount = (int) ($category->standard_products_count ?? $category->standardProducts()->count());

        return $childrenCount === 0
            && $mappingCount === 0
            && $standardProductsCount === 0;
    }

    private function wouldCreateCycle(int $categoryId, int $parentId): bool
    {
        $currentParentId = $parentId;

        while ($currentParentId !== 0) {
            if ($currentParentId === $categoryId) {
                return true;
            }

            $currentParentId = (int) (StandardCategory::query()
                ->whereKey($currentParentId)
                ->value('parent_id') ?? 0);
        }

        return false;
    }

    private function cascadeActiveState(StandardCategory $category, bool $active): void
    {
        $category->children()->get()->each(function (StandardCategory $child) use ($active) {
            $child->update(['is_active' => $active]);
            $this->cascadeActiveState($child, $active);
        });
    }

    private function buildAttributePanelData(?StandardCategory $selectedCategory): array
    {
        $attributeDefinitions = ProductAttributeDefinition::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $pickerCategories = StandardCategory::query()
            ->permanentBackbone()
            ->orderBy('product_family')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(10)
            ->get();

        if ($selectedCategory) {
            $selectedCategory->load(['parent', 'attributeRules.attributeDefinition']);
        }

        return [
            'attributeDefinitions' => $attributeDefinitions,
            'attributeTemplates' => $this->attributeTemplateService()->getTemplates(),
            'attributePickerCategories' => $pickerCategories,
            'selectedAttributeCategory' => $selectedCategory,
            'selectedAttributeRules' => $selectedCategory?->attributeRules->keyBy('product_attribute_definition_id') ?? collect(),
        ];
    }

    private function attributeTemplateService(): CategoryAttributeTemplateService
    {
        return app(CategoryAttributeTemplateService::class);
    }
}
