<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierSource;
use App\Services\ProductFieldDictionaryService;
use App\Services\ProductDataHub\SourceFetchService;
use App\Services\ProductDataHub\SourceParserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuperAdminFieldMappingController extends Controller
{
    public function __construct(
        private readonly ProductFieldDictionaryService $fieldDictionary,
        private readonly SourceFetchService $sourceFetch,
        private readonly SourceParserService $sourceParser,
    ) {
    }

    public function index(Request $request): View
    {
        $sourceId = $request->integer('source_id') ?: null;

        if ($sourceId) {
            return $this->show($request, SupplierSource::query()->findOrFail($sourceId));
        }

        $sources = SupplierSource::query()
            ->with(['supplier', 'fieldMappings'])
            ->orderBy('source_name')
            ->get()
            ->map(function (SupplierSource $source) {
                $sourceFields = $this->resolveIndexSourceFields($source);
                $existingMappings = $source->fieldMappings
                    ->keyBy('source_field')
                    ->map(fn (SupplierFieldMapping $mapping) => [
                        'standard_field_key' => $mapping->target_field,
                    ])
                    ->all();
                $requiredSummary = $this->fieldDictionary->buildRequiredMappingSummary($existingMappings);
                $mappedCount = $source->fieldMappings->whereNotNull('target_field')->count();

                return [
                    'source' => $source,
                    'profile_key' => $this->profileKeyForSource($source),
                    'source_fields_count' => count($sourceFields),
                    'mapped_count' => $mappedCount,
                    'missing_required_count' => $requiredSummary['count'],
                    'missing_required_labels' => array_column($requiredSummary['missing'], 'label'),
                    'mapping_status' => $requiredSummary['count'] === 0 && $mappedCount > 0 ? 'hazir' : 'eksik',
                ];
            });

        return view('super-admin.product-data-hub.field-mappings.index', [
            'sources' => $sources,
            'standardFields' => $this->fieldDictionary->getStandardFields(),
            'standardFieldGroups' => $this->fieldDictionary->getFieldGroups(),
            'supplierProfiles' => config('prodelya_product_data_hub.supplier_profiles', []),
        ]);
    }

    public function show(Request $request, SupplierSource $source): View
    {
        $source->load(['supplier', 'fieldMappings.reviewer']);

        $supplierKey = $this->profileKeyForSource($source);
        $profile = $this->fieldDictionary->getSupplierProfile($supplierKey);
        $fieldContext = $this->buildSourceFieldContext($source, $supplierKey);
        $sourceFields = $fieldContext['fields'];
        $standardFields = $this->fieldDictionary->getStandardFields();
        $standardFieldGroups = $this->fieldDictionary->getFieldGroups();
        $mappingStatusOptions = $this->fieldDictionary->getMappingStatusOptions();
        $suggestedMappings = $this->fieldDictionary->suggestMappings($sourceFields, $supplierKey);
        $existingMappings = $source->fieldMappings->keyBy('source_field');

        $mappingRows = collect($sourceFields)->map(function (string $sourceField) use ($existingMappings, $suggestedMappings, $standardFields, $supplierKey, $fieldContext) {
            $existing = $existingMappings->get($sourceField);
            $suggestion = $suggestedMappings[$sourceField] ?? null;
            $selectedField = $existing?->target_field;
            $candidateField = $selectedField ?? ($suggestion['standard_field_key'] ?? null);
            $fieldMeta = $candidateField ? ($standardFields[$candidateField] ?? null) : null;

            return [
                'source_field_name' => $sourceField,
                'sample_value' => $fieldContext['samples'][$sourceField] ?? null,
                'normalized_source_field' => $this->fieldDictionary->normalizeSourceField($sourceField, $supplierKey),
                'suggested_standard_field' => $suggestion['standard_field_key'] ?? null,
                'selected_standard_field' => $selectedField,
                'standard_field_group' => $this->fieldDictionary->getFieldGroupForStandardField($candidateField),
                'is_required' => $fieldMeta['required'] ?? false,
                'type' => $fieldMeta['type'] ?? 'text',
                'type_label' => $this->fieldDictionary->getTypeLabel($fieldMeta['type'] ?? 'text'),
                'mapping_status' => $existing?->mapping_status ?? ($suggestion['mapping_status'] ?? 'pending'),
                'mapping_status_label' => $mappingStatusOptions[$existing?->mapping_status ?? ($suggestion['mapping_status'] ?? 'pending')] ?? 'Bekliyor',
                'transform_rule' => $existing?->transform_rule,
                'note' => $existing?->note,
                'confidence_score' => $existing?->confidence_score ?? ($suggestion['confidence_score'] ?? null),
                'reviewer_name' => $existing?->reviewer?->name,
                'legacy_field_name' => $existing?->legacy_field_name ?? ($suggestion['legacy_field_name'] ?? null),
            ];
        });

        $requiredSummary = $this->fieldDictionary->buildRequiredMappingSummary(
            $mappingRows->keyBy('source_field_name')->map(fn (array $row) => [
                'standard_field_key' => $row['selected_standard_field'],
            ])->all()
        );
        $requiredWarnings = array_column($requiredSummary['missing'], 'message');
        $missingRequiredLabels = array_column($requiredSummary['missing'], 'label');
        $missingRequiredFieldKeys = collect($requiredSummary['missing'])
            ->pluck('accepted_fields')
            ->flatten()
            ->unique()
            ->values()
            ->all();

        $activeFilter = $request->string('filter')->toString() ?: 'all';
        $mappingRows = $this->filterMappingRows($mappingRows, $activeFilter, $missingRequiredFieldKeys);

        $stats = [
            'source_fields' => count($sourceFields),
            'mapped' => $existingMappings->filter(fn (SupplierFieldMapping $mapping) => filled($mapping->target_field))->count(),
            'missing_required' => $requiredSummary['count'],
            'alias_count' => collect($sourceFields)->filter(fn (string $sourceField) => filled($suggestedMappings[$sourceField]['standard_field_key'] ?? null))->count(),
            'warning_count' => $existingMappings->where('mapping_status', 'needs_review')->count() + $requiredSummary['count'],
        ];

        return view('super-admin.product-data-hub.field-mappings.source', [
            'source' => $source,
            'profile' => $profile,
            'profileKey' => $supplierKey,
            'mappingRows' => $mappingRows->values()->all(),
            'standardFields' => $standardFields,
            'standardFieldGroups' => $standardFieldGroups,
            'mappingStatusOptions' => $mappingStatusOptions,
            'requiredWarnings' => $requiredWarnings,
            'requiredSummary' => $requiredSummary,
            'missingRequiredLabels' => $missingRequiredLabels,
            'stats' => $stats,
            'profileAliases' => $profile['field_aliases'] ?? [],
            'sourceFieldContext' => $fieldContext,
            'activeFilter' => $activeFilter,
            'availableFilters' => [
                'all' => 'Tümü',
                'required_missing' => 'Zorunlu Eksikler',
                'mapped' => 'Eşlenmiş',
                'unmapped' => 'Eşlenmemiş',
                'suggested' => 'Önerilenler',
                'warnings' => 'Uyarılar',
                'images' => 'Görsel',
                'pricing' => 'Fiyat / Stok',
                'category' => 'Kategori',
                'variant' => 'Varyant',
            ],
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

        $supplierKey = $this->profileKeyForSource($source);
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

        $requiredSummary = $this->fieldDictionary->buildRequiredMappingSummary(
            SupplierFieldMapping::query()
                ->forSource($source->id)
                ->get()
                ->keyBy('source_field')
                ->map(fn (SupplierFieldMapping $mapping) => [
                    'standard_field_key' => $mapping->target_field,
                ])
                ->all()
        );

        $successMessage = $requiredSummary['count'] === 0
            ? 'Global alan eşlemeleri kaydedildi. Zorunlu alanlar tamam.'
            : 'Global alan eşlemeleri taslak olarak kaydedildi. Zorunlu eksik: ' . $requiredSummary['count'];

        return redirect()
            ->route('admin.super.product-data-hub.field-mappings.source', $source)
            ->with('success', $successMessage);
    }

    private function profileKeyForSource(SupplierSource $source): ?string
    {
        return $this->fieldDictionary->resolveProfileTemplateKey(
            (array) ($source->config ?? []),
            $source->supplier?->code,
            $source->supplier?->name
        );
    }

    private function resolveIndexSourceFields(SupplierSource $source): array
    {
        $sourceFields = $this->fieldDictionary->getSourceFields($this->profileKeyForSource($source));

        return collect($sourceFields)
            ->merge($source->fieldMappings->pluck('source_field'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function buildSourceFieldContext(SupplierSource $source, ?string $profileKey): array
    {
        $parserResult = [
            'ok' => false,
            'rows' => [],
            'node_path' => $source->config['product_node_path']
                ?? $source->config['items_path']
                ?? ($profileKey ? ($this->fieldDictionary->getSupplierProfile($profileKey)['product_node_path'] ?? null) : null),
            'records_read' => 0,
        ];

        if (filled($source->url) || filled($source->config['source_file_path'] ?? null)) {
            $fetchResult = $this->sourceFetch->fetch($source);

            if ($fetchResult['ok']) {
                $parserResult = $this->sourceParser->parse($source, (string) $fetchResult['content'], 3);
            }
        }

        $parsedFields = $parserResult['ok']
            ? array_keys($this->extractFieldSamplesFromRows($parserResult['rows']))
            : [];

        $profileFields = $this->fieldDictionary->getSourceFields($profileKey);
        $mappedFields = $source->fieldMappings->pluck('source_field')->all();
        $allFields = collect($profileFields)
            ->merge($parsedFields)
            ->merge($mappedFields)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $samples = $parserResult['ok']
            ? $this->extractFieldSamplesFromRows($parserResult['rows'], $allFields)
            : [];

        return [
            'fields' => $allFields,
            'samples' => $samples,
            'records_read' => $parserResult['records_read'] ?? 0,
            'node_path' => $parserResult['node_path'] ?? null,
            'source_mode' => ($parserResult['ok'] ?? false) ? 'live_source' : 'profile_fallback',
        ];
    }

    private function extractFieldSamplesFromRows(array $rows, array $fieldList = []): array
    {
        $samplePool = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $samplePool = array_replace($samplePool, $this->flattenRowSamples($row));
        }

        if ($fieldList === []) {
            return $samplePool;
        }

        $samples = [];
        foreach ($fieldList as $field) {
            $samples[$field] = $samplePool[$field] ?? $this->resolveSampleValueForField($rows, $field);
        }

        return $samples;
    }

    private function flattenRowSamples(array $row, string $prefix = ''): array
    {
        $samples = [];

        foreach ($row as $key => $value) {
            $fieldKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                if (array_is_list($value)) {
                    $firstValue = collect($value)->first(fn ($item) => !is_array($item) && filled($item));
                    if (filled($firstValue)) {
                        $samples[$fieldKey] = (string) $firstValue;
                    }

                    $firstArray = collect($value)->first(fn ($item) => is_array($item));
                    if (is_array($firstArray)) {
                        $samples = array_replace($samples, $this->flattenRowSamples($firstArray, $fieldKey));
                    }

                    continue;
                }

                $samples = array_replace($samples, $this->flattenRowSamples($value, $fieldKey));
                continue;
            }

            if (filled($value) && !array_key_exists($fieldKey, $samples)) {
                $samples[$fieldKey] = (string) $value;
            }
        }

        return $samples;
    }

    private function resolveSampleValueForField(array $rows, string $field): ?string
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = data_get($row, $field);

            if (is_scalar($value) && filled($value)) {
                return (string) $value;
            }

            if (is_array($value) && filled($value['_value'] ?? null)) {
                return (string) $value['_value'];
            }
        }

        return null;
    }

    private function filterMappingRows(Collection $rows, string $activeFilter, array $missingRequiredFieldKeys): Collection
    {
        return match ($activeFilter) {
            'required_missing' => $rows->filter(function (array $row) use ($missingRequiredFieldKeys) {
                if (filled($row['selected_standard_field'])) {
                    return false;
                }

                return in_array($row['suggested_standard_field'], $missingRequiredFieldKeys, true);
            }),
            'mapped' => $rows->filter(fn (array $row) => filled($row['selected_standard_field'])),
            'unmapped' => $rows->filter(fn (array $row) => blank($row['selected_standard_field'])),
            'suggested' => $rows->filter(fn (array $row) => blank($row['selected_standard_field']) && filled($row['suggested_standard_field'])),
            'warnings' => $rows->filter(fn (array $row) => in_array($row['mapping_status'], ['needs_review', 'pending'], true)),
            'images' => $rows->filter(fn (array $row) => $row['standard_field_group'] === 'Görsel'),
            'pricing' => $rows->filter(fn (array $row) => in_array($row['standard_field_group'], ['Fiyat', 'Stok'], true)),
            'category' => $rows->filter(fn (array $row) => $row['standard_field_group'] === 'Kategori'),
            'variant' => $rows->filter(fn (array $row) => $row['standard_field_group'] === 'Varyant'),
            default => $rows,
        };
    }
}
