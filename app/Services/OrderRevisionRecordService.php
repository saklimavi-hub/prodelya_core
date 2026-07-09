<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Models\OrderRevisionChange;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderRevisionRecordService
{
    private const FORBIDDEN_KEYS = [
        'supplier_cost',
        'purchase_price',
        'profit',
        'margin',
        'raw',
        'projection',
        'group_code',
        'file_path',
        'tenant_id',
        'current_account_id',
        'transaction_id',
        'meta_json',
        'payload',
        'fason maliyet',
        'tedarikçi maliyeti',
        'iç maliyet',
    ];

    public function createOrUpdateFromComparison(
        Order $order,
        Order $revisionQuote,
        array $comparison,
        ?User $actor = null
    ): OrderRevision {
        $actor ??= Auth::user();

        $this->guardOrderAndQuote($order, $revisionQuote, $actor);

        return DB::transaction(function () use ($order, $revisionQuote, $comparison, $actor): OrderRevision {
            $revision = OrderRevision::query()->firstOrNew([
                'tenant_account_id' => $order->tenant_account_id,
                'revision_quote_id' => $revisionQuote->id,
            ]);

            $isNew = ! $revision->exists;

            $revision->fill([
                'order_id' => $order->id,
                'revision_number' => max(1, (int) ($revisionQuote->revision_number ?: 1)),
                'status' => $this->resolveRevisionStatus($comparison),
                'requested_by_user_id' => $revision->requested_by_user_id ?: $actor?->id,
                'summary' => $this->buildRevisionSummary($order, $revisionQuote, $comparison),
            ]);
            $revision->save();

            $revision->changes()->delete();

            foreach ($this->buildChangeRows($revision, $order, $comparison) as $payload) {
                $revision->changes()->create($payload);
            }

            AuditLog::log([
                'tenant_account_id' => $order->tenant_account_id,
                'user_id' => $actor?->id,
                'action' => $isNew ? 'order_revision_record_created' : 'order_revision_record_refreshed',
                'entity_type' => OrderRevision::class,
                'entity_id' => $revision->id,
                'new_values' => $this->sanitizePayload($revision->summary),
                'notes' => 'Revizyon uygulama foundation kaydı oluşturuldu.',
            ]);

            return $revision->fresh(['changes', 'order', 'revisionQuote']);
        });
    }

    private function guardOrderAndQuote(Order $order, Order $revisionQuote, ?User $actor): void
    {
        if (! $revisionQuote->isRevisionDraft()) {
            throw new InvalidArgumentException('Revizyon kaydı yalnız revizyon teklifleri için oluşturulabilir.');
        }

        if ((int) $order->tenant_account_id !== (int) $revisionQuote->tenant_account_id) {
            throw new InvalidArgumentException('Kaynak sipariş ve revizyon teklifi aynı tenant altında olmalıdır.');
        }

        if ((int) ($revisionQuote->source_order_id ?: 0) !== (int) $order->id) {
            throw new InvalidArgumentException('Revizyon teklifi belirtilen kaynak siparişe bağlı değildir.');
        }

        if (! $actor || ! $actor->hasAnyPermissionInTenant(['create_quotes', 'edit_quotes', 'approve_quotes'], $order->tenant_account_id)) {
            AuditLog::log([
                'tenant_account_id' => $order->tenant_account_id,
                'user_id' => $actor?->id,
                'action' => 'permission_violation',
                'entity_type' => OrderRevision::class,
                'entity_id' => null,
                'notes' => 'Attempted to create revision foundation record without required permission.',
            ]);

            throw new InvalidArgumentException('Revizyon kayıt altyapısını oluşturma yetkiniz yok.');
        }
    }

    private function resolveRevisionStatus(array $comparison): string
    {
        $decisions = collect($comparison['decisionMatrix'] ?? [])->pluck('decision');

        if ($decisions->contains('Kilitli')) {
            return OrderRevision::STATUS_PARTIALLY_APPLICABLE;
        }

        if ($decisions->contains('Manuel Kontrol Gerekli')) {
            return OrderRevision::STATUS_REVIEW_PENDING;
        }

        if ($decisions->contains('Uygulanabilir') || $decisions->contains('Kontrollü Uygulanabilir')) {
            return OrderRevision::STATUS_READY_TO_APPLY;
        }

        return OrderRevision::STATUS_DRAFT;
    }

    private function buildRevisionSummary(Order $order, Order $revisionQuote, array $comparison): array
    {
        $decisionMatrix = collect($comparison['decisionMatrix'] ?? []);
        $lineComparisons = collect($comparison['lineComparisons'] ?? []);

        return $this->sanitizePayload([
            'source_order_id' => $order->id,
            'source_order_number' => $order->document_number,
            'revision_quote_id' => $revisionQuote->id,
            'revision_quote_number' => $revisionQuote->document_number,
            'revision_number' => max(1, (int) ($revisionQuote->revision_number ?: 1)),
            'decision_summary' => [
                'total' => $decisionMatrix->count(),
                'applicable' => $decisionMatrix->where('decision', 'Uygulanabilir')->count(),
                'controlled' => $decisionMatrix->where('decision', 'Kontrollü Uygulanabilir')->count(),
                'locked' => $decisionMatrix->where('decision', 'Kilitli')->count(),
                'manual' => $decisionMatrix->where('decision', 'Manuel Kontrol Gerekli')->count(),
                'no_change' => $decisionMatrix->where('decision', 'Değişiklik Yok')->count(),
            ],
            'line_summary' => [
                'total' => $lineComparisons->count(),
                'changed' => $lineComparisons->where('status', 'Değişti')->count(),
                'new' => $lineComparisons->where('status', 'Yeni Eklendi')->count(),
                'removed' => $lineComparisons->where('status', 'Kaldırıldı')->count(),
                'manual' => $lineComparisons->where('status', 'Kontrol Gerekli')->count(),
            ],
        ]);
    }

    private function buildChangeRows(OrderRevision $revision, Order $order, array $comparison): array
    {
        $rows = [];

        foreach ($comparison['decisionMatrix'] ?? [] as $matrixRow) {
            $rows[] = [
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'change_group' => 'decision_matrix',
                'field_key' => $this->normalizeFieldKey((string) ($matrixRow['label'] ?? 'unknown')),
                'old_value' => null,
                'new_value' => $this->sanitizePayload([
                    'decision' => $matrixRow['decision'] ?? null,
                    'helper' => $matrixRow['helper'] ?? null,
                ]),
                'decision' => $this->mapDecisionToCode((string) ($matrixRow['decision'] ?? 'Değişiklik Yok')),
                'apply_status' => $this->defaultApplyStatusForDecision((string) ($matrixRow['decision'] ?? 'Değişiklik Yok')),
                'reason' => $this->sanitizeText($matrixRow['helper'] ?? null),
            ];
        }

        foreach ($comparison['lineComparisons'] ?? [] as $index => $lineRow) {
            $rows[] = [
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'order_item_id' => data_get($lineRow, 'source_item_id'),
                'change_group' => 'item_line',
                'field_key' => 'item_line_' . ($index + 1),
                'old_value' => $this->sanitizePayload($lineRow['source'] ?? null),
                'new_value' => $this->sanitizePayload($lineRow['revision'] ?? null),
                'decision' => $this->mapStatusToDecision($lineRow['status'] ?? 'Değişiklik Yok'),
                'apply_status' => $this->defaultApplyStatusForStatus($lineRow['status'] ?? 'Değişiklik Yok'),
                'reason' => $this->sanitizeText($lineRow['match_reason'] ?? null),
            ];

            foreach (($lineRow['prints'] ?? []) as $printIndex => $printRow) {
                $rows[] = [
                    'tenant_account_id' => $order->tenant_account_id,
                    'order_id' => $order->id,
                    'order_item_id' => data_get($lineRow, 'source_item_id'),
                    'order_item_print_id' => data_get($printRow, 'source_print_id'),
                    'change_group' => 'print_line',
                    'field_key' => 'print_line_' . ($index + 1) . '_' . ($printIndex + 1),
                    'old_value' => $this->sanitizePayload($printRow['source'] ?? null),
                    'new_value' => $this->sanitizePayload($printRow['revision'] ?? null),
                    'decision' => $this->mapStatusToDecision($printRow['status'] ?? 'Değişiklik Yok'),
                    'apply_status' => $this->defaultApplyStatusForStatus($printRow['status'] ?? 'Değişiklik Yok'),
                    'reason' => $this->sanitizeText($printRow['match_reason'] ?? null),
                ];
            }
        }

        return $rows;
    }

    private function mapDecisionToCode(string $decision): string
    {
        return match ($decision) {
            'Uygulanabilir' => OrderRevisionChange::DECISION_APPLICABLE,
            'Kontrollü Uygulanabilir' => OrderRevisionChange::DECISION_CONTROLLED_APPLICABLE,
            'Kilitli' => OrderRevisionChange::DECISION_LOCKED,
            'Manuel Kontrol Gerekli' => OrderRevisionChange::DECISION_MANUAL_REVIEW,
            default => OrderRevisionChange::DECISION_NO_CHANGE,
        };
    }

    private function mapStatusToDecision(string $status): string
    {
        return match ($status) {
            'Değişti', 'Yeni Eklendi' => OrderRevisionChange::DECISION_CONTROLLED_APPLICABLE,
            'Kaldırıldı' => OrderRevisionChange::DECISION_MANUAL_REVIEW,
            'Kontrol Gerekli' => OrderRevisionChange::DECISION_MANUAL_REVIEW,
            default => OrderRevisionChange::DECISION_NO_CHANGE,
        };
    }

    private function defaultApplyStatusForDecision(string $decision): string
    {
        return match ($decision) {
            'Kilitli' => OrderRevisionChange::APPLY_STATUS_BLOCKED,
            'Manuel Kontrol Gerekli' => OrderRevisionChange::APPLY_STATUS_MANUAL_REQUIRED,
            default => OrderRevisionChange::APPLY_STATUS_PENDING,
        };
    }

    private function defaultApplyStatusForStatus(string $status): string
    {
        return match ($status) {
            'Kaldırıldı' => OrderRevisionChange::APPLY_STATUS_MANUAL_REQUIRED,
            'Kontrol Gerekli' => OrderRevisionChange::APPLY_STATUS_MANUAL_REQUIRED,
            default => OrderRevisionChange::APPLY_STATUS_PENDING,
        };
    }

    private function normalizeFieldKey(string $label): string
    {
        $map = ['Ü' => 'u', 'ü' => 'u', 'İ' => 'i', 'ı' => 'i', 'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's', 'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g'];
        $label = strtr($label, $map);
        $label = strtolower($label);
        $label = preg_replace('/[^a-z0-9]+/', '_', $label) ?: 'field';

        return trim($label, '_');
    }

    private function sanitizePayload(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key) ? mb_strtolower($key, 'UTF-8') : $key;

            if (is_string($normalizedKey) && in_array($normalizedKey, self::FORBIDDEN_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitizePayload($value)
                : (is_string($value) ? $this->sanitizeText($value) : $value);
        }

        return $sanitized;
    }

    private function sanitizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(strip_tags($value));

        foreach (self::FORBIDDEN_KEYS as $forbiddenKey) {
            if (mb_stripos($clean, $forbiddenKey, 0, 'UTF-8') !== false) {
                return null;
            }
        }

        return $clean === '' ? null : $clean;
    }
}
