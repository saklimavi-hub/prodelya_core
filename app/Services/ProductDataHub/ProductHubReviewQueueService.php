<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductDataHubSyncChange;
use Illuminate\Support\Collection;

class ProductHubReviewQueueService
{
    public function build(Collection $rows): array
    {
        $rows = $rows->values();
        $sourceIds = $rows->pluck('supplier_source_id')->filter()->unique()->values();
        $productIds = $rows->pluck('standard_product_id')->filter()->unique()->values();

        $changes = ProductDataHubSyncChange::query()
            ->openReview()
            ->when($sourceIds->isNotEmpty(), fn ($query) => $query->whereIn('supplier_source_id', $sourceIds))
            ->when($productIds->isNotEmpty(), function ($query) use ($productIds) {
                $query->where(function ($inner) use ($productIds) {
                    $inner->whereIn('standard_product_id', $productIds)
                        ->orWhereNotNull('supplier_product_key');
                });
            })
            ->get();

        $newItemChanges = $changes->whereIn('change_type', ['new_product', 'new_variant']);
        $identityIssueChanges = $changes->whereIn('change_type', ['missing_product', 'missing_variant', 'variant_structure_changed']);

        $bucketMaps = [
            'new_items' => $this->matchRowsToChanges($rows, $newItemChanges),
            'identity_issues' => $this->matchRowsToChanges($rows, $identityIssueChanges),
            'category_waiting' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'category_waiting')
                ->pluck('row_key')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'projection_issues' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'projection_lagging')
                ->pluck('row_key')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'tenant_output_blocks' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'tenant_output_closed')
                ->pluck('row_key')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'anomaly_flags' => $rows->filter(function (array $row) {
                $keys = $row['diagnostic_badge_keys'] ?? [];

                return in_array('stale_price', $keys, true)
                    || in_array('stale_stock', $keys, true)
                    || in_array('standard_variant_outdated', $keys, true)
                    || in_array('quote_price_outdated', $keys, true);
            })
                ->pluck('row_key')
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];

        $bucketProductIds = [
            'new_items' => $newItemChanges->pluck('standard_product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'identity_issues' => $identityIssueChanges->pluck('standard_product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'category_waiting' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'category_waiting')
                ->pluck('standard_product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'projection_issues' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'projection_lagging')
                ->pluck('standard_product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'tenant_output_blocks' => $rows->filter(fn (array $row) => ($row['operation_state_key'] ?? null) === 'tenant_output_closed')
                ->pluck('standard_product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'anomaly_flags' => $rows->filter(function (array $row) {
                $keys = $row['diagnostic_badge_keys'] ?? [];

                return in_array('stale_price', $keys, true)
                    || in_array('stale_stock', $keys, true)
                    || in_array('standard_variant_outdated', $keys, true)
                    || in_array('quote_price_outdated', $keys, true);
            })->pluck('standard_product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];

        $summary = collect($bucketMaps)->map(fn (array $rowKeys) => count($rowKeys))->all();
        $summary['total'] = array_sum($summary);

        return [
            'summary' => $summary,
            'bucket_maps' => $bucketMaps,
            'bucket_product_ids' => $bucketProductIds,
            'cards' => [
                [
                    'key' => 'new_items',
                    'title' => 'Yeni Ürünler',
                    'count' => $summary['new_items'],
                    'tone' => 'blue',
                    'copy' => 'İlk kez görülen ürün veya varyantlar satış zincirine girmeden önce kontrol ister.',
                    'action' => 'Yeni kayıtları incele',
                ],
                [
                    'key' => 'category_waiting',
                    'title' => 'Kategori Bekleyenler',
                    'count' => $summary['category_waiting'],
                    'tone' => 'amber',
                    'copy' => 'Kategori kararı bekleyen satırlar teklif ve katalog akışını temiz tamamlayamaz.',
                    'action' => 'Kategori kararını aç',
                ],
                [
                    'key' => 'identity_issues',
                    'title' => 'Kimlik / Variant Sorunları',
                    'count' => $summary['identity_issues'],
                    'tone' => 'red',
                    'copy' => 'Eksik ürün, eksik varyant veya yapı değişimi review kuyruğunda ayrı tutulur.',
                    'action' => 'Kimlik sorunlarını aç',
                ],
                [
                    'key' => 'anomaly_flags',
                    'title' => 'Anomali / Freshness',
                    'count' => $summary['anomaly_flags'],
                    'tone' => 'amber',
                    'copy' => 'Fiyat, stok veya teklif görünürlüğü uyumsuzlukları manuel kontrol ister.',
                    'action' => 'Anomalileri incele',
                ],
                [
                    'key' => 'projection_issues',
                    'title' => 'Projection Sorunları',
                    'count' => $summary['projection_issues'],
                    'tone' => 'red',
                    'copy' => 'Katalog yansıması geri kalan satırlar satışta eski fiyat ve stok doğurabilir.',
                    'action' => 'Projection sorunlarını aç',
                ],
                [
                    'key' => 'tenant_output_blocks',
                    'title' => 'Tenant Çıkışı Blokajları',
                    'count' => $summary['tenant_output_blocks'],
                    'tone' => 'purple',
                    'copy' => 'Tedarikçi erişimi veya görünürlük kapalıysa ürün doğru olsa bile satışa açılamaz.',
                    'action' => 'Blokajları aç',
                ],
            ],
        ];
    }

    private function matchRowsToChanges(Collection $rows, Collection $changes): array
    {
        if ($changes->isEmpty()) {
            return [];
        }

        $productIds = $changes->pluck('standard_product_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $supplierKeys = $changes->pluck('supplier_product_key')->filter()->map(fn ($key) => mb_strtolower(trim((string) $key)))->unique();

        return $rows->filter(function (array $row) use ($productIds, $supplierKeys) {
            if ($productIds->contains((int) ($row['standard_product_id'] ?? 0))) {
                return true;
            }

            if ($supplierKeys->isEmpty()) {
                return false;
            }

            $candidates = collect([
                $row['display_code'] ?? null,
                $row['group_code'] ?? null,
                data_get($row, 'raw_snapshot.supplier_product_code'),
                data_get($row, 'raw_snapshot.supplier_variant_code'),
            ])->filter()->map(fn ($value) => mb_strtolower(trim((string) $value)));

            return $candidates->intersect($supplierKeys)->isNotEmpty();
        })
            ->pluck('row_key')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
