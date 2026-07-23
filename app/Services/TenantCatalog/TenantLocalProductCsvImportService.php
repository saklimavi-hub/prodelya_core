<?php

namespace App\Services\TenantCatalog;

use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantLocalProductCsvImportService
{
    public function __construct(
        private readonly LocalProductFieldCatalogService $fieldCatalogService,
        private readonly TenantLocalProductWriteService $writeService,
    ) {
    }

    public function preview(UploadedFile $file): array
    {
        $parsed = $this->parseCsvFile($file->getRealPath());
        $normalizedRows = collect($parsed['rows'])
            ->map(fn (array $row) => $this->normalizeImportRow($row['data'], $row['line'], $row['errors'] ?? []))
            ->values()
            ->all();

        $errors = collect($parsed['header_errors'] ?? [])
            ->merge(collect($normalizedRows)->pluck('errors')->flatten()->filter()->values())
            ->values()
            ->all();

        return [
            'delimiter' => $parsed['delimiter'],
            'headers' => $parsed['headers'],
            'rows' => $normalizedRows,
            'preview_rows' => collect($normalizedRows)->take(10)->values()->all(),
            'errors' => $errors,
            'total' => count($normalizedRows),
        ];
    }

    public function apply(TenantAccount $tenant, array $preview, string $policy, Request $request, User $user): array
    {
        $rows = collect($preview['rows'] ?? []);
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Import önizlemesi bulunamadı. Önce dosya yükleyin.',
            ]);
        }

        $errors = $rows->pluck('errors')->flatten()->filter()->values();
        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => $errors->first(),
            ]);
        }

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        DB::transaction(function () use ($tenant, $rows, $policy, $request, $user, &$result): void {
            $groupedVariants = $rows->where('product_type', 'variant')->groupBy('group_code');

            foreach ($rows->where('product_type', 'flat') as $row) {
                $existing = TenantCatalogProduct::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('catalog_source', 'local_product')
                    ->where('product_code', $row['product_code'])
                    ->first();

                if ($existing && $policy === 'skip') {
                    $result['skipped']++;
                    continue;
                }

                $payload = [
                    'product_type' => 'flat',
                    'product_name' => $row['product_name'],
                    'product_code' => $row['product_code'],
                    'standard_category_id' => $row['standard_category_id'],
                    'description' => $row['description'],
                    'product_url' => $row['product_url'],
                    'display_price' => $row['display_price'],
                    'currency' => $row['currency'],
                    'vat_rate' => $row['vat_rate'],
                    'local_stock_quantity' => $existing ? 0 : $row['initial_stock'],
                    'variant_color' => $row['variant_color'],
                    'variant_size' => $row['variant_size'],
                    'variant_dimensions' => $row['variant_dimensions'],
                    'image_url' => $row['image_url'],
                    'visible_in_catalog' => $row['visible_in_catalog'],
                    'visible_in_quote' => $row['visible_in_quote'],
                    'is_active' => $row['is_active'],
                    'is_featured' => false,
                ];

                if ($existing) {
                    $this->writeService->update($tenant, $existing, $payload, $request, $user);
                    $result['updated']++;
                } else {
                    $this->writeService->create($tenant, $payload, $request, $user);
                    $result['created']++;
                }
            }

            foreach ($groupedVariants as $groupCode => $groupRows) {
                $existingParent = TenantCatalogProduct::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('catalog_source', 'local_product')
                    ->where('product_code', $groupCode)
                    ->first();

                if ($existingParent && $policy === 'skip') {
                    $result['skipped'] += $groupRows->count();
                    continue;
                }

                $first = $groupRows->first();
                $payload = [
                    'product_type' => 'variant',
                    'group_code' => $groupCode,
                    'product_name' => $first['product_name'],
                    'standard_category_id' => $first['standard_category_id'],
                    'description' => $first['description'],
                    'product_url' => $first['product_url'],
                    'display_price' => null,
                    'currency' => $first['currency'],
                    'vat_rate' => $first['vat_rate'],
                    'image_url' => $first['image_url'],
                    'visible_in_catalog' => $first['visible_in_catalog'],
                    'visible_in_quote' => false,
                    'is_active' => $first['is_active'],
                    'is_featured' => false,
                    'variants' => $groupRows->map(function (array $row) use ($existingParent) {
                        $existingVariant = $existingParent?->variants()->where('variant_code', $row['variant_code'])->first();

                        return [
                            'id' => $existingVariant?->id,
                            'included' => true,
                            'variant_code' => $row['variant_code'],
                            'variant_name' => $row['variant_name'],
                            'variant_color' => $row['variant_color'],
                            'variant_size' => $row['variant_size'],
                            'variant_dimensions' => $row['variant_dimensions'],
                            'display_price' => $row['display_price'],
                            'currency' => $row['currency'],
                            'initial_stock' => $existingVariant ? 0 : $row['initial_stock'],
                            'image_url' => $row['image_url'],
                            'visible_in_catalog' => $row['visible_in_catalog'],
                            'visible_in_quote' => $row['visible_in_quote'],
                            'is_active' => $row['is_active'],
                        ];
                    })->all(),
                ];

                if ($existingParent) {
                    $this->writeService->update($tenant, $existingParent, $payload, $request, $user);
                    $result['updated'] += $groupRows->count();
                } else {
                    $this->writeService->create($tenant, $payload, $request, $user);
                    $result['created'] += $groupRows->count();
                }
            }
        });

        return $result;
    }

    public function templateCsv(): string
    {
        return implode("\n", [
            implode(',', $this->fieldCatalogService->csvTemplateHeaders()),
            'flat,,PRD-001,Örnek Flat Ürün,Promosyon Ürünleri,25.50,TRY,3,Mavi,11 cm,145 x 12 mm,https://example.com/flat.webp,https://example.com/urun/prd-001,20,1,1,1,Örnek açıklama',
            'variant,PRD-KALEM,PRD-KALEM-MV,Plastik Kalem,Promosyon Ürünleri,12.50,TRY,2,Mavi,11 cm,145 x 12 mm,https://example.com/mavi.webp,https://example.com/urun/prd-kalem,20,1,1,1,Grup açıklaması',
            'variant,PRD-KALEM,PRD-KALEM-K,Plastik Kalem,Promosyon Ürünleri,12.50,TRY,4,Kırmızı,11 cm,145 x 12 mm,,https://example.com/urun/prd-kalem,20,1,1,1,Grup açıklaması',
        ]);
    }

    private function normalizeImportRow(array $row, int $line, array $rowErrors = []): array
    {
        $normalized = $this->mapAliases($row);
        $productType = in_array(($normalized['product_type'] ?? 'flat'), ['flat', 'variant'], true)
            ? $normalized['product_type']
            : 'flat';

        $groupCode = $this->normalizeSku($normalized['group_code'] ?? null);
        $productCode = $this->normalizeSku($normalized['product_code'] ?? $normalized['variant_sku'] ?? null);

        $errors = $rowErrors;
        if ($productType === 'variant' && $groupCode === '') {
            $errors[] = "{$line}. satır: group_code zorunludur.";
        }
        if ($productCode === '') {
            $errors[] = "{$line}. satır: ürün kodu boş.";
        }

        $productName = trim((string) ($normalized['product_name'] ?? ''));
        if ($productName === '') {
            $errors[] = "{$line}. satır: ürün adı boş.";
        }

        $variantColor = $this->nullableTrim($normalized['color'] ?? null);
        $variantSize = $this->nullableTrim($normalized['measure'] ?? $normalized['size'] ?? null);
        $variantDimensions = $this->nullableTrim($normalized['dimensions'] ?? null);
        $variantName = $productType === 'variant'
            ? $this->buildVariantName($productName, $variantColor, $variantSize, $variantDimensions)
            : $productName;

        $imageUrl = $this->normalizeExternalImageUrl($normalized['image_url'] ?? null);
        if (filled($normalized['image_url'] ?? null) && $imageUrl === null) {
            $errors[] = "{$line}. satır: görsel URL yalnız http veya https olabilir.";
        }

        $productUrl = $this->normalizeExternalPageUrl($normalized['product_url'] ?? null);
        if (filled($normalized['product_url'] ?? null) && $productUrl === null) {
            $errors[] = "{$line}. satır: ürün URL yalnız http veya https olabilir.";
        }

        $listPrice = $this->nullableDecimal($normalized['list_price'] ?? null);
        $initialStock = $this->decimalOrDefault($normalized['initial_stock'] ?? null, 0);
        $currency = $this->normalizeCurrency($normalized['currency'] ?? null);
        $category = $this->resolveCategoryId($normalized['category'] ?? null);

        return [
            'line' => $line,
            'product_type' => $productType,
            'group_code' => $groupCode,
            'product_code' => $productCode,
            'product_name' => $productName,
            'variant_code' => $productType === 'variant' ? $productCode : null,
            'variant_name' => $variantName,
            'variant_color' => $variantColor,
            'variant_size' => $variantSize,
            'variant_dimensions' => $variantDimensions,
            'standard_category_id' => $category['id'],
            'category_label' => $category['label'],
            'display_price' => $listPrice,
            'currency' => $currency,
            'initial_stock' => max(0, $initialStock),
            'image_url' => $imageUrl,
            'product_url' => $productUrl,
            'visible_in_catalog' => $this->booleanValue($normalized['catalog_visible'] ?? null, true),
            'visible_in_quote' => $this->booleanValue($normalized['quote_visible'] ?? null, true),
            'is_active' => $this->booleanValue($normalized['status'] ?? $normalized['active'] ?? null, true),
            'vat_rate' => $this->legacyVatRate($normalized['vat_rate'] ?? null),
            'description' => $this->nullableTrim($normalized['description'] ?? null),
            'errors' => $errors,
        ];
    }

    private function mapAliases(array $row): array
    {
        $headers = [];
        foreach ($row as $key => $value) {
            $headers[Str::of((string) $key)->trim()->lower()->snake()->toString()] = $value;
        }

        return [
            'product_type' => strtolower(trim((string) ($headers['product_type'] ?? $headers['urun_turu'] ?? 'flat'))),
            'group_code' => $headers['group_code'] ?? null,
            'product_code' => $headers['product_code'] ?? $headers['urun_kodu'] ?? $headers['variant_sku'] ?? null,
            'variant_sku' => $headers['variant_sku'] ?? $headers['urun_kodu'] ?? null,
            'product_name' => $headers['product_name'] ?? $headers['urun_adi'] ?? null,
            'color' => $headers['color'] ?? $headers['urun_renk'] ?? null,
            'measure' => $headers['measure'] ?? $headers['urun_olcu'] ?? $headers['size'] ?? null,
            'dimensions' => $headers['dimensions'] ?? $headers['urun_ebat'] ?? null,
            'category' => $headers['category'] ?? $headers['kategori'] ?? $headers['urun_kategori'] ?? null,
            'list_price' => $headers['list_price'] ?? $headers['liste_fiyati'] ?? $headers['display_price'] ?? $headers['urun_fiyat'] ?? null,
            'currency' => $headers['currency'] ?? $headers['para_birimi'] ?? null,
            'initial_stock' => $headers['initial_stock'] ?? $headers['stok'] ?? $headers['baslangic_stogu'] ?? $headers['stock'] ?? $headers['urun_stok'] ?? null,
            'image_url' => $headers['image_url'] ?? $headers['gorsel_url'] ?? $headers['urun_resim'] ?? null,
            'product_url' => $headers['product_url'] ?? $headers['urun_url'] ?? null,
            'catalog_visible' => $headers['catalog_visible'] ?? $headers['katalogda_gorunsun'] ?? null,
            'quote_visible' => $headers['teklifte_kullanilsin'] ?? $headers['quote_visible'] ?? null,
            'status' => $headers['status'] ?? $headers['aktif'] ?? null,
            'active' => $headers['active'] ?? $headers['aktif'] ?? null,
            'vat_rate' => $headers['vat_rate'] ?? $headers['kdv_var'] ?? $headers['kdv_orani'] ?? $headers['urun_kdv'] ?? null,
            'description' => $headers['description'] ?? $headers['aciklama'] ?? $headers['urun_aciklama'] ?? null,
        ];
    }

    private function parseCsvFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return [
                'delimiter' => ',',
                'headers' => [],
                'rows' => [],
                'header_errors' => ['CSV dosyası açılamadı.'],
            ];
        }

        $firstMeaningfulLine = null;
        while (($rawLine = fgets($handle)) !== false) {
            if (!$this->isBlankCsvRow(str_getcsv($rawLine, ','))) {
                $firstMeaningfulLine = $rawLine;
                break;
            }
        }

        $delimiter = $this->detectDelimiter($firstMeaningfulLine);
        rewind($handle);

        $headers = null;
        $headerErrors = [];
        $rows = [];
        $lineNumber = 0;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;

            if ($this->isBlankCsvRow($data)) {
                continue;
            }

            if ($headers === null) {
                [$headers, $headerErrors] = $this->normalizeCsvHeaders($data);
                continue;
            }

            if ($headers === []) {
                break;
            }

            [$normalizedData, $rowErrors] = $this->normalizeCsvDataRow($data, $headers, $lineNumber);
            $rows[] = [
                'line' => $lineNumber,
                'data' => array_combine($headers, $normalizedData),
                'errors' => $rowErrors,
            ];
        }

        fclose($handle);

        return [
            'delimiter' => $delimiter,
            'headers' => $headers ?? [],
            'rows' => $rows,
            'header_errors' => $headerErrors,
        ];
    }

    private function detectDelimiter(?string $line): string
    {
        if ($line === null) {
            return ',';
        }

        $line = $this->stripUtf8Bom($line);
        $candidates = [',', ';', "\t"];
        $bestDelimiter = ',';
        $bestCount = 0;

        foreach ($candidates as $delimiter) {
            $count = count(str_getcsv($line, $delimiter));
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    private function normalizeCsvHeaders(array $data): array
    {
        $headers = [];
        $errors = [];

        foreach ($data as $index => $value) {
            $rawHeader = $index === 0 ? $this->stripUtf8Bom((string) $value) : (string) $value;
            $normalized = Str::of($rawHeader)->trim()->lower()->snake()->toString();

            if ($normalized === '') {
                $errors[] = 'CSV başlık satırında boş sütun adı bulundu.';
                continue;
            }

            if (in_array($normalized, $headers, true)) {
                $errors[] = "CSV başlık satırında tekrar eden sütun bulundu: {$normalized}.";
                continue;
            }

            $headers[] = $normalized;
        }

        return [$headers, $errors];
    }

    private function normalizeCsvDataRow(array $data, array $headers, int $lineNumber): array
    {
        $headerCount = count($headers);
        $rowErrors = [];
        $normalizedData = $data;

        while (count($normalizedData) > $headerCount && $this->isEmptyCsvCell($normalizedData[array_key_last($normalizedData)] ?? null)) {
            array_pop($normalizedData);
        }

        if (count($normalizedData) > $headerCount) {
            $rowErrors[] = "Satır {$lineNumber}: Başlık sayısı {$headerCount}, veri sütunu " . count($normalizedData) . '. Fazla sütunları kontrol edin.';
            $normalizedData = array_slice($normalizedData, 0, $headerCount);
        }

        if (count($normalizedData) < $headerCount) {
            $normalizedData = array_pad($normalizedData, $headerCount, null);
        }

        return [$normalizedData, $rowErrors];
    }

    private function isBlankCsvRow(array $data): bool
    {
        return count(array_filter($data, fn ($value) => !$this->isEmptyCsvCell($value))) === 0;
    }

    private function isEmptyCsvCell(mixed $value): bool
    {
        return trim((string) ($value ?? '')) === '';
    }

    private function stripUtf8Bom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    }

    private function resolveCategoryId(?string $value): array
    {
        $label = trim((string) ($value ?? ''));
        if ($label === '') {
            return ['id' => null, 'label' => '-'];
        }

        $category = \App\Models\StandardCategory::query()
            ->permanentBackbone()
            ->where(function ($query) use ($label) {
                $query->where('name', $label)
                    ->orWhere('path', 'like', '%' . $label . '%')
                    ->orWhere('full_path', 'like', '%' . $label . '%');
            })
            ->orderBy('path')
            ->first();

        return [
            'id' => $category?->id,
            'label' => $category?->full_path ?? $label,
        ];
    }

    private function normalizeSku(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        return Str::of(Str::ascii($raw))
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->toString();
    }

    private function normalizeCurrency(mixed $value): string
    {
        return match (strtoupper(trim((string) ($value ?? 'TRY')))) {
            'TL', 'TRY' => 'TRY',
            'USD' => 'USD',
            'EUR' => 'EUR',
            default => 'TRY',
        };
    }

    private function normalizeExternalImageUrl(mixed $value): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $url = trim((string) $value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function normalizeExternalPageUrl(mixed $value): ?string
    {
        return $this->normalizeExternalImageUrl($value);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if (!filled($value)) {
            return null;
        }

        if (!is_numeric(str_replace(',', '.', (string) $value))) {
            return null;
        }

        return round((float) str_replace(',', '.', (string) $value), 4);
    }

    private function decimalOrDefault(mixed $value, float $default): float
    {
        if (!filled($value)) {
            return $default;
        }

        if (!is_numeric(str_replace(',', '.', (string) $value))) {
            return $default;
        }

        return round((float) str_replace(',', '.', (string) $value), 4);
    }

    private function booleanValue(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function legacyVatRate(mixed $value): float
    {
        if (!filled($value)) {
            return 20;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'evet', 'yes', 'var'], true)) {
            return 20;
        }

        if (is_numeric(str_replace(',', '.', $normalized))) {
            return round((float) str_replace(',', '.', $normalized), 2);
        }

        return 0;
    }

    private function buildVariantName(string $productName, ?string $color, ?string $measure, ?string $dimensions): string
    {
        $parts = array_values(array_filter([$color, $measure, $dimensions]));
        if ($parts === []) {
            return $productName;
        }

        return trim($productName . ' ' . implode(' / ', $parts));
    }
}
