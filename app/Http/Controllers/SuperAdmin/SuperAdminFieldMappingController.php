<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierSource;
use App\Services\ProductFieldDictionaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuperAdminFieldMappingController extends Controller
{
    public function __construct(
        private readonly ProductFieldDictionaryService $fieldDictionary
    ) {
    }

    public function index(Request $request): View
    {
        $sourceId = $request->integer('source_id') ?: null;

        if ($sourceId) {
            return $this->show(SupplierSource::query()->findOrFail($sourceId));
        }

        $sources = SupplierSource::query()
            ->with(['supplier', 'fieldMappings'])
            ->orderBy('source_name')
            ->get()
            ->map(function (SupplierSource $source) {
                $supplierKey = $this->supplierKeyForSource($source);
                $sourceFields = $this->fieldDictionary->getSourceFields($supplierKey);
                $existingMappings = $source->fieldMappings
                    ->keyBy('source_field')
                    ->map(fn (SupplierFieldMapping $mapping) => [
                        'standard_field_key' => $mapping->target_field,
                    ])
                    ->all();
                $requiredErrors = $this->fieldDictionary->validateRequiredMappings($existingMappings);

                return [
                    'source' => $source,
                    'profile_key' => $supplierKey,
                    'source_fields_count' => count($sourceFields),
                    'mapped_count' => $source->fieldMappings->whereNotNull('target_field')->count(),
                    'missing_required_count' => count($requiredErrors),
                    'mapping_status' => empty($requiredErrors) && $source->fieldMappings->isNotEmpty() ? 'hazir' : 'eksik',
                ];
            });

        return view('super-admin.product-data-hub.field-mappings.index', [
            'sources' => $sources,
            'standardFields' => $this->fieldDictionary->getStandardFields(),
            'supplierProfiles' => config('prodelya_product_data_hub.supplier_profiles', []),
        ]);
    }

    public function show(SupplierSource $source): View
    {
        $source->load(['supplier', 'fieldMappings.reviewer']);

        $supplierKey = $this->supplierKeyForSource($source);
        $profile = $this->fieldDictionary->getSupplierProfile($supplierKey);
        $sourceFields = $this->fieldDictionary->getSourceFields($supplierKey);
        $standardFields = $this->fieldDictionary->getStandardFields();
        $mappingStatusOptions = $this->fieldDictionary->getMappingStatusOptions();
        $suggestedMappings = $this->fieldDictionary->suggestMappings($sourceFields, $supplierKey);
        $existingMappings = $source->fieldMappings->keyBy('source_field');

        $mappingRows = collect($sourceFields)->map(function (string $sourceField) use ($existingMappings, $suggestedMappings, $standardFields, $supplierKey) {
            $existing = $existingMappings->get($sourceField);
            $suggestion = $suggestedMappings[$sourceField] ?? null;
            $selectedField = $existing?->target_field ?? ($suggestion['standard_field_key'] ?? null);
            $fieldMeta = $selectedField ? ($standardFields[$selectedField] ?? null) : null;

            return [
                'source_field_name' => $sourceField,
                'normalized_source_field' => $this->fieldDictionary->normalizeSourceField($sourceField, $supplierKey),
                'suggested_standard_field' => $suggestion['standard_field_key'] ?? null,
                'selected_standard_field' => $selectedField,
                'is_required' => $fieldMeta['required'] ?? false,
                'type' => $fieldMeta['type'] ?? 'text',
                'type_label' => $this->fieldDictionary->getTypeLabel($fieldMeta['type'] ?? 'text'),
                'mapping_status' => $existing?->mapping_status ?? ($suggestion['mapping_status'] ?? 'pending'),
                'transform_rule' => $existing?->transform_rule,
                'note' => $existing?->note,
                'confidence_score' => $existing?->confidence_score ?? ($suggestion['confidence_score'] ?? null),
                'reviewer_name' => $existing?->reviewer?->name,
                'legacy_field_name' => $existing?->legacy_field_name ?? ($suggestion['legacy_field_name'] ?? null),
            ];
        })->all();

        $requiredWarnings = $this->fieldDictionary->validateRequiredMappings(
            collect($mappingRows)->keyBy('source_field_name')->map(fn (array $row) => [
                'standard_field_key' => $row['selected_standard_field'],
            ])->all()
        );

        $stats = [
            'source_fields' => count($sourceFields),
            'mapped' => collect($mappingRows)->filter(fn (array $row) => filled($row['selected_standard_field']))->count(),
            'missing_required' => count($requiredWarnings),
            'alias_count' => collect($mappingRows)->filter(fn (array $row) => filled($row['suggested_standard_field']))->count(),
            'warning_count' => collect($mappingRows)->where('mapping_status', 'needs_review')->count() + count($requiredWarnings),
        ];

        return view('super-admin.product-data-hub.field-mappings.source', [
            'source' => $source,
            'profile' => $profile,
            'profileKey' => $supplierKey,
            'mappingRows' => $mappingRows,
            'standardFields' => $standardFields,
            'mappingStatusOptions' => $mappingStatusOptions,
            'requiredWarnings' => $requiredWarnings,
            'stats' => $stats,
            'profileAliases' => $profile['field_aliases'] ?? [],
        ]);
    }

    public function storeOrUpdate(Request $request, SupplierSource $source): RedirectResponse
    {
        $validated = $request->validate([
            'mappings' => 'required|array',
            'mappings.*.standard_field_key' => 'nullable|string|max:255',
            'mappings.*.mapping_status' => 'required|in:pending,suggested,mapped,ignored,needs_review',
            'mappings.*.transform_rule' => 'nullable|string|max:255',
            'mappings.*.note' => 'nullable|string|max:1000',
        ]);

        $supplierKey = $this->supplierKeyForSource($source);
        $standardFields = $this->fieldDictionary->getStandardFields();
        $suggested = $this->fieldDictionary->suggestMappings(array_keys($validated['mappings']), $supplierKey);

        foreach ($validated['mappings'] as $sourceField => $payload) {
            $targetField = $payload['standard_field_key'] ?: null;
            $fieldMeta = $targetField ? ($standardFields[$targetField] ?? []) : [];
            $normalizedSourceField = $this->fieldDictionary->normalizeSourceFieldWithoutAlias($sourceField);
            $mappingStatus = $payload['mapping_status'] ?? null;

            if (blank($targetField)) {
                $mappingStatus = in_array($mappingStatus, ['ignored', 'needs_review'], true) ? $mappingStatus : 'pending';
            } elseif (blank($mappingStatus) || $mappingStatus === 'pending') {
                $mappingStatus = 'mapped';
            }

            SupplierFieldMapping::query()->updateOrCreate(
                [
                    'supplier_source_id' => $source->id,
                    'source_field' => $sourceField,
                ],
                [
                    'supplier_id' => $source->supplier_id,
                    'tenant_account_id' => null,
                    'legacy_field_name' => $normalizedSourceField,
                    'target_field' => $targetField,
                    'field_type' => $fieldMeta['type'] ?? 'text',
                    'mapping_status' => $mappingStatus,
                    'confidence_score' => $payload['confidence_score'] ?? ($suggested[$sourceField]['confidence_score'] ?? null),
                    'transform_rule' => $payload['transform_rule'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                    'is_required' => (bool) ($fieldMeta['required'] ?? false),
                    'meta' => [
                        'supplier_profile' => $supplierKey,
                        'normalized_source_field' => $normalizedSourceField,
                        'scope' => 'global',
                    ],
                ]
            );
        }

        return redirect()
            ->route('admin.super.product-data-hub.field-mappings.source', $source)
            ->with('success', 'Global alan eşlemeleri kaydedildi.');
    }

    private function supplierKeyForSource(SupplierSource $source): ?string
    {
        return $this->fieldDictionary->detectSupplierKey(
            $source->supplier?->code,
            $source->supplier?->name
        );
    }
}
