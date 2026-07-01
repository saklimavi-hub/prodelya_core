<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierSource;
use App\Services\ProductFieldDictionaryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleXMLElement;

class SourceParserService
{
    public function __construct(
        private readonly ProductFieldDictionaryService $fieldDictionary
    ) {
    }

    public function parse(SupplierSource $source, string $content, ?int $limit = null): array
    {
        $profileKey = $this->getSupplierProfileKey($source);
        $contentType = $this->detectContentType($source);
        $limit = $this->resolveLimit($limit);

        return match ($contentType) {
            'json' => $this->parseJson($source, $content, $limit, $profileKey),
            'csv' => $this->parseCsv($source, $content, $limit, $profileKey),
            default => $this->parseXml($source, $content, $limit, $profileKey),
        };
    }

    public function parseXml(SupplierSource $source, string $content, ?int $limit = 50, ?string $profileKey = null): array
    {
        $profileKey ??= $this->getSupplierProfileKey($source);
        $nodePath = $this->resolveNodePath($source, $profileKey);

        if ($this->containsBlockedXmlDirective($content)) {
            return $this->failedResult($profileKey, 'xml', $nodePath, [
                'XML güvenlik politikası nedeniyle reddedildi: DOCTYPE/ENTITY kullanımı desteklenmez.',
            ]);
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

            if (!$xml) {
                libxml_clear_errors();

                return $this->failedResult($profileKey, 'xml', $nodePath, ['XML içeriği ayrıştırılamadı.']);
            }

            $nodes = $this->resolveXmlNodes($xml, $nodePath, $limit);
            $rows = array_map(fn (SimpleXMLElement $node) => $this->xmlNodeToArray($node), $nodes);

            libxml_clear_errors();

            return $this->successfulResult($profileKey, 'xml', $nodePath, $rows);
        } catch (\Throwable $exception) {
            Log::warning('Source XML parse failed', [
                'source_id' => $source->id,
                'profile_key' => $profileKey,
                'node_path' => $nodePath,
                'message' => 'XML parse exception',
            ]);

            libxml_clear_errors();

            return $this->failedResult($profileKey, 'xml', $nodePath, ['XML güvenli şekilde ayrıştırılamadı.']);
        } finally {
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    public function parseJson(SupplierSource $source, string $content, ?int $limit = 50, ?string $profileKey = null): array
    {
        $profileKey ??= $this->getSupplierProfileKey($source);

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $itemsPath = $source->config['items_path'] ?? $source->config['product_node_path'] ?? null;
            $items = filled($itemsPath) ? data_get($decoded, $itemsPath) : null;

            if (!is_array($items)) {
                foreach (['items', 'data', 'products', 'urunler'] as $candidatePath) {
                    $candidate = data_get($decoded, $candidatePath);
                    if (is_array($candidate)) {
                        $items = $candidate;
                        $itemsPath = $candidatePath;
                        break;
                    }
                }
            }

            if (!is_array($items)) {
                $items = $decoded;
            }

            if (!is_array($items)) {
                return $this->failedResult($profileKey, 'json', $itemsPath, ['JSON içinde ürün listesi bulunamadı. items, data, products veya urunler alanı bekleniyor.']);
            }

            $normalizedRows = $this->normalizeJsonRows($items);
            if ($normalizedRows === []) {
                return $this->failedResult($profileKey, 'json', $itemsPath, ['JSON içinde ürün listesi bulunamadı. items, data, products veya urunler alanı bekleniyor.']);
            }

            $rows = is_null($limit)
                ? array_values($normalizedRows)
                : array_slice(array_values($normalizedRows), 0, $limit);

            return $this->successfulResult($profileKey, 'json', $itemsPath, $rows);
        } catch (\Throwable $exception) {
            Log::warning('Source JSON parse failed', [
                'source_id' => $source->id,
                'profile_key' => $profileKey,
                'message' => $exception->getMessage(),
            ]);

            return $this->failedResult($profileKey, 'json', $source->config['items_path'] ?? null, ['JSON ayrıştırma hatası: ' . $exception->getMessage()]);
        }
    }

    public function parseCsv(SupplierSource $source, string $content, ?int $limit = 50, ?string $profileKey = null): array
    {
        $profileKey ??= $this->getSupplierProfileKey($source);
        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];

        if (count($lines) < 2) {
            return $this->failedResult($profileKey, 'csv', null, ['CSV içeriğinde başlık veya veri satırı bulunamadı.']);
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $headers = str_getcsv(array_shift($lines), $delimiter);
        $rows = [];

        $csvLines = is_null($limit) ? $lines : array_slice($lines, 0, $limit);

        foreach ($csvLines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter);
            $rows[] = array_combine($headers, array_pad($values, count($headers), null)) ?: [];
        }

        return $this->successfulResult($profileKey, 'csv', null, $rows);
    }

    public function xmlNodeToArray(SimpleXMLElement $node): array|string|null
    {
        $attributes = [];
        foreach ($node->attributes() as $key => $value) {
            $attributes[$key] = trim((string) $value);
        }

        $children = $node->children();
        if ($children->count() === 0) {
            $textValue = trim((string) $node);

            if ($attributes === []) {
                return $textValue;
            }

            $payload = [
                '_attributes' => $attributes,
            ];

            if ($textValue !== '') {
                $payload['_value'] = $textValue;
            }

            return $payload;
        }

        $result = [];

        if ($attributes !== []) {
            $result['_attributes'] = $attributes;
        }

        $textValue = trim((string) $node);
        if ($textValue !== '') {
            $result['_value'] = $textValue;
        }

        foreach ($children as $child) {
            $childName = $child->getName();
            $childValue = $this->xmlNodeToArray($child);

            if (array_key_exists($childName, $result)) {
                if (!is_array($result[$childName]) || !array_is_list($result[$childName])) {
                    $result[$childName] = [$result[$childName]];
                }

                $result[$childName][] = $childValue;
            } else {
                $result[$childName] = $childValue;
            }
        }

        return $result;
    }

    private function resolveLimit(?int $limit): ?int
    {
        if ($limit === 0) {
            return null;
        }

        $limit = $limit ?? 50;

        return max(1, min(500, $limit));
    }

    private function detectContentType(SupplierSource $source): string
    {
        $format = Str::lower((string) ($source->config['format'] ?? $source->source_type));

        return match ($format) {
            'json', 'api' => 'json',
            'csv', 'excel', 'txt' => 'csv',
            default => 'xml',
        };
    }

    private function getSupplierProfileKey(SupplierSource $source): string
    {
        return $this->fieldDictionary->resolveProfileTemplateKey(
            (array) ($source->config ?? []),
            $source->supplier?->code,
            $source->supplier?->name
        ) ?? 'ETKIN';
    }

    private function resolveNodePath(SupplierSource $source, string $profileKey): ?string
    {
        return $source->config['product_node_path']
            ?? config("prodelya_product_data_hub.supplier_profiles.{$profileKey}.product_node_path");
    }

    /**
     * @return array<int, SimpleXMLElement>
     */
    private function resolveXmlNodes(SimpleXMLElement $xml, ?string $nodePath, ?int $limit): array
    {
        if (blank($nodePath)) {
            $children = iterator_to_array($xml->children());

            return is_null($limit) ? $children : array_slice($children, 0, $limit);
        }

        $segments = array_values(array_filter(explode('/', trim((string) $nodePath, '/'))));
        $candidates = [$xml];

        foreach ($segments as $segment) {
            $next = [];

            foreach ($candidates as $candidate) {
                foreach ($candidate->{$segment} as $child) {
                    $next[] = $child;
                }
            }

            if ($next !== []) {
                $candidates = $next;
                continue;
            }

            $xpathNodes = $xml->xpath('//' . $segment) ?: [];
            if ($xpathNodes !== []) {
                $candidates = $xpathNodes;
                continue;
            }

            return [];
        }

        return is_null($limit) ? $candidates : array_slice($candidates, 0, $limit);
    }

    private function normalizeJsonRows(array $items): array
    {
        if (array_is_list($items)) {
            return $items;
        }

        if ($this->looksLikeRootObjectProductMap($items)) {
            return array_values(array_map(function ($row, $key) {
                if (!is_array($row)) {
                    return ['_root_key' => $key, 'value' => $row];
                }

                $row['_root_key'] = (string) $key;

                return $row;
            }, $items, array_keys($items)));
        }

        $firstArray = collect($items)->first(fn ($item) => is_array($item));

        if (is_array($firstArray) && array_is_list($firstArray)) {
            return $firstArray;
        }

        return $this->looksLikeJsonProductRow($items) ? [$items] : [];
    }

    private function looksLikeJsonProductRow(array $row): bool
    {
        $keys = array_keys($row);
        $signals = ['uid', 'kod', 'product_code', 'urun_kodu', 'urunkodu', 'urun_id', 'urun_isim', 'urun_baslik', 'id', 'name', 'title'];

        return count(array_intersect($keys, $signals)) > 0;
    }

    private function looksLikeRootObjectProductMap(array $items): bool
    {
        if ($items === [] || array_is_list($items)) {
            return false;
        }

        $sample = array_slice($items, 0, 3, true);

        foreach ($sample as $key => $value) {
            if (!is_array($value) || !$this->looksLikeJsonProductRow($value)) {
                return false;
            }

            if (!is_string($key) && !is_int($key)) {
                return false;
            }
        }

        return true;
    }

    private function detectDelimiter(string $headerLine): string
    {
        $semicolonCount = substr_count($headerLine, ';');
        $commaCount = substr_count($headerLine, ',');
        $tabCount = substr_count($headerLine, "\t");

        return match (max($semicolonCount, $commaCount, $tabCount)) {
            $tabCount => "\t",
            $semicolonCount => ';',
            default => ',',
        };
    }

    private function successfulResult(string $profileKey, string $contentType, ?string $nodePath, array $rows): array
    {
        return [
            'ok' => true,
            'rows' => $rows,
            'profile_key' => $profileKey,
            'content_type' => $contentType,
            'node_path' => $nodePath,
            'records_read' => count($rows),
            'warnings' => [],
            'errors' => [],
        ];
    }

    private function failedResult(string $profileKey, string $contentType, ?string $nodePath, array $errors): array
    {
        return [
            'ok' => false,
            'rows' => [],
            'profile_key' => $profileKey,
            'content_type' => $contentType,
            'node_path' => $nodePath,
            'records_read' => 0,
            'warnings' => [],
            'errors' => $errors,
        ];
    }

    private function containsBlockedXmlDirective(string $content): bool
    {
        return preg_match('/<!DOCTYPE/i', $content) === 1
            || preg_match('/<!ENTITY/i', $content) === 1;
    }
}
