<?php

namespace App\Services\ProductDataHub;

use App\Models\ProductDataHubSyncRun;
use Illuminate\Support\Collection;

class ProductHubSyncDecisionService
{
    public function summarize(?ProductDataHubSyncRun $run, ?Collection $changes = null): array
    {
        if (!$run) {
            return [
                'summary_key' => null,
                'has_delta_report' => false,
                'automatic_updates' => 0,
                'review_required' => 0,
                'new_items' => 0,
                'category_waiting' => 0,
                'identity_issues' => 0,
                'projection_pending' => 0,
                'price_changed' => 0,
                'stock_changed' => 0,
                'anomaly_flags' => 0,
                'state' => 'missing',
                'state_label' => 'Henüz delta raporu yok',
                'state_tone' => 'blue',
                'note' => 'Henüz delta raporu yok. İlk kontrol çalıştığında normal değişiklikler ve review gerektiren istisnalar ayrılacaktır.',
            ];
        }

        $payload = $run->report_payload ?? [];
        $summaryKey = data_get($payload, 'delta_apply_summary') ? 'delta_apply_summary' : 'delta_summary';
        $counts = data_get($payload, $summaryKey . '.counts', []);

        $priceChanged = (int) data_get($counts, 'price_changed', 0) + (int) data_get($counts, 'price_and_stock_changed', 0);
        $stockChanged = (int) data_get($counts, 'stock_changed', 0) + (int) data_get($counts, 'price_and_stock_changed', 0);
        $automaticUpdates = (int) data_get($payload, $summaryKey . '.price_stock_applied', 0);

        if ($automaticUpdates === 0) {
            $automaticUpdates = (int) data_get($payload, $summaryKey . '.price_changed_applied', 0)
                + (int) data_get($payload, $summaryKey . '.stock_changed_applied', 0)
                + (int) data_get($payload, $summaryKey . '.price_and_stock_changed_applied', 0);
        }

        $newItems = (int) data_get($counts, 'new_product', 0) + (int) data_get($counts, 'new_variant', 0);
        $categoryWaiting = (int) data_get($counts, 'category_changed', 0);
        $identityIssues = (int) data_get($counts, 'missing_product', 0)
            + (int) data_get($counts, 'missing_variant', 0)
            + (int) data_get($counts, 'variant_structure_changed', 0)
            + (int) data_get($counts, 'blocked_required_field_missing', 0);
        $reviewRequired = (int) data_get($payload, $summaryKey . '.review_only_changes_detected', 0)
            + (int) data_get($payload, $summaryKey . '.skipped_required_field_missing', 0);
        $projectionPending = (int) data_get($payload, $summaryKey . '.projection_skipped_review_only_change', 0)
            + (int) data_get($payload, $summaryKey . '.would_project_dirty_products', 0)
            + (int) data_get($payload, $summaryKey . '.projection.blocked_missing_category', 0)
            + (int) data_get($payload, $summaryKey . '.projection.blocked_missing_price', 0);
        $anomalyFlags = 0;

        foreach ([
            data_get($payload, $summaryKey . '.flags.suspicious_price_jump', false),
            data_get($payload, $summaryKey . '.flags.feed_degraded', false),
            data_get($payload, $summaryKey . '.flags.suspicious_feed_drop', false),
        ] as $flag) {
            if ($flag) {
                $anomalyFlags++;
            }
        }

        if ($changes) {
            $reviewRequired = max($reviewRequired, $changes->whereNotNull('review_status')->count());
            $newItems = max($newItems, $changes->whereIn('change_type', ['new_product', 'new_variant'])->count());
            $categoryWaiting = max($categoryWaiting, $changes->where('change_type', 'category_changed')->count());
            $identityIssues = max($identityIssues, $changes->whereIn('change_type', ['missing_product', 'missing_variant', 'variant_structure_changed', 'blocked_required_field_missing'])->count());
        }

        $state = 'automatic_flow';
        $stateLabel = 'Normal değişiklikler güvenli akışta';
        $stateTone = 'green';
        $note = 'Normal fiyat ve stok değişimleri ekstra komut beklemeden ilerler. Bu koşu operatöre yalnız istisna varsa iş çıkarmalıdır.';

        if ($newItems > 0 || $categoryWaiting > 0 || $identityIssues > 0 || $reviewRequired > 0 || $anomalyFlags > 0) {
            $state = 'review_required';
            $stateLabel = 'İstisnalar review bekliyor';
            $stateTone = 'amber';
            $note = 'Yeni ürün, kategori, kimlik veya anomali içeren kayıtlar manuel incelemeye ayrılmış. Normal değişiklikler bu kuyruğun dışında kalmalıdır.';
        } elseif ($projectionPending > 0) {
            $state = 'projection_pending';
            $stateLabel = 'Projection yansıması bekliyor';
            $stateTone = 'red';
            $note = 'Fiyat ve stok güvenli biçimde işlenmiş olsa da projection katmanında yansıtma bekleyen kayıtlar var.';
        } elseif ($priceChanged === 0 && $stockChanged === 0) {
            $state = 'stable';
            $stateLabel = 'Yeni değişiklik yok';
            $stateTone = 'blue';
            $note = 'Bu koşuda fiyat veya stok farkı çıkmadı. Sistem sessiz ve stabil görünüyor.';
        }

        return [
            'summary_key' => $summaryKey,
            'has_delta_report' => true,
            'automatic_updates' => $automaticUpdates,
            'review_required' => $reviewRequired,
            'new_items' => $newItems,
            'category_waiting' => $categoryWaiting,
            'identity_issues' => $identityIssues,
            'projection_pending' => $projectionPending,
            'price_changed' => $priceChanged,
            'stock_changed' => $stockChanged,
            'anomaly_flags' => $anomalyFlags,
            'state' => $state,
            'state_label' => $stateLabel,
            'state_tone' => $stateTone,
            'note' => $note,
        ];
    }
}
