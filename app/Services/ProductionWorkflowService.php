<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Support\Facades\DB;

class ProductionWorkflowService
{
    public function __construct(
        protected ProductionDataBuilder $dataBuilder,
        protected ProductionReadinessResolver $readinessResolver,
        protected SubcontractorProductionCurrentAccountSyncService $subcontractorSyncService,
        protected NotificationEventService $notificationEventService,
    ) {}

    public function initialize(OrderItemPrintProduction $production, ?User $user = null): OrderItemPrintProduction
    {
        return DB::transaction(function () use ($production, $user): OrderItemPrintProduction {
            return $this->persistWithSnapshot(
                $production,
                [],
                'production_operation_created',
                null,
                $production->production_status,
                'Üretim operasyon kaydı oluşturuldu.',
                $user
            );
        });
    }

    public function updateAssignment(
        OrderItemPrintProduction $production,
        array $attributes,
        ?User $user = null,
        ?string $note = null
    ): OrderItemPrintProduction {
        $oldType = $this->resolvedProductionType($production);
        $productionType = OrderItemPrintProduction::normalizeProductionType($attributes['production_type'] ?? null)
            ?? $oldType
            ?? $production->production_type;
        $routeChanged = $oldType !== $productionType;
        $assignmentNote = filled($note) ? trim((string) $note) : null;
        $oldCompanyId = (int) ($production->production_company_id ?? 0);
        $newCompanyId = (int) ($attributes['production_company_id'] ?? 0);

        $isExternal = in_array($productionType, [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true);
        $companyWillBeAssigned = $isExternal && $oldCompanyId <= 0 && $newCompanyId > 0;
        $companyWillChange = $isExternal && $oldCompanyId > 0 && $newCompanyId > 0 && $oldCompanyId !== $newCompanyId;
        $assignmentWillChange = $companyWillBeAssigned || $companyWillChange;

        if (($routeChanged || $assignmentWillChange) && in_array($production->production_status, [
            OrderItemPrintProduction::STATUS_COMPLETED,
            OrderItemPrintProduction::STATUS_CANCELLED,
        ], true)) {
            throw new \InvalidArgumentException('Tamamlanan veya iptal edilen üretimin ataması değiştirilemez.');
        }

        if ($routeChanged && ($production->started_at || (float) $production->completed_quantity > 0.0) && blank($assignmentNote)) {
            throw new \InvalidArgumentException('Başlamış veya kısmi üretimde rota değişimi için gerekçe zorunludur.');
        }

        if ($companyWillChange && blank($assignmentNote)) {
            throw new \InvalidArgumentException('Fason firma değişikliği için gerekçe zorunludur.');
        }

        $oldTypeLabel = OrderItemPrintProduction::productionTypeLabels()[$oldType]
            ?? $production->safeProductionTypeLabel();
        $newTypeLabel = OrderItemPrintProduction::productionTypeLabels()[$productionType] ?? 'Belirlenmedi';
        $oldCompanyName = $oldCompanyId > 0
            ? (Company::query()->whereKey($oldCompanyId)->value('legal_name') ?: ('#'.$oldCompanyId))
            : 'Atanmamış';
        $newCompanyName = $newCompanyId > 0
            ? (Company::query()->whereKey($newCompanyId)->value('legal_name') ?: ('#'.$newCompanyId))
            : 'Atanmamış';

        $changes = [
            'production_type' => $productionType,
            'production_company_id' => $isExternal ? ($attributes['production_company_id'] ?? null) : null,
            'production_unit_name' => $isExternal ? null : ($attributes['production_unit_name'] ?? null),
            'assigned_to' => $isExternal ? null : ($attributes['assigned_to'] ?? null),
            'cliche_required' => (bool) ($attributes['cliche_required'] ?? false),
            'cliche_status' => $attributes['cliche_status'] ?? $production->cliche_status,
            'production_note' => $attributes['production_note'] ?? $production->production_note,
            'subcontractor_cost' => array_key_exists('subcontractor_cost', $attributes)
                ? $attributes['subcontractor_cost']
                : $production->subcontractor_cost,
            'subcontractor_cost_currency' => $attributes['subcontractor_cost_currency'] ?? $production->subcontractor_cost_currency,
            'subcontractor_cost_note' => $attributes['subcontractor_cost_note'] ?? $production->subcontractor_cost_note,
        ];

        if ($routeChanged && $production->started_at && (float) $production->completed_quantity <= 0.0) {
            $changes['production_status'] = OrderItemPrintProduction::STATUS_PENDING;
            $changes['started_at'] = null;
            $changes['sent_to_subcontractor_at'] = null;
        }

        $actionType = match (true) {
            $routeChanged => 'production_route_changed',
            $companyWillChange => 'production_subcontractor_reassigned',
            default => ($isExternal ? 'production_assigned_external' : 'production_assigned_internal'),
        };
        $defaultNote = match (true) {
            $routeChanged => sprintf('Üretim yolu değiştirildi: %s → %s.%s', $oldTypeLabel, $newTypeLabel, $assignmentNote ? ' Gerekçe: '.$assignmentNote : ''),
            $companyWillChange => sprintf('Fason firma değiştirildi: %s → %s. Gerekçe: %s', $oldCompanyName, $newCompanyName, $assignmentNote),
            default => ($isExternal ? 'İş dış baskı / fasona atandı.' : 'İş iç üretime atandı.'),
        };

        return $this->transition(
            $production,
            $changes,
            $actionType,
            $user,
            $defaultNote
        );
    }
    public function assignInternal(
        OrderItemPrintProduction $production,
        ?User $user = null,
        ?string $unitName = null,
        ?string $note = null
    ): OrderItemPrintProduction {
        if (filled($unitName)) {
            $production->forceFill(['production_unit_name' => trim((string) $unitName)]);
        }

        return $this->markStarted($production, $user, $note ?: 'Üretim başlatıldı.');
    }

    public function assignExternal(
        OrderItemPrintProduction $production,
        Company $company,
        ?User $user = null,
        ?string $note = null
    ): OrderItemPrintProduction {
        $this->assertReadiness($production);

        if ($production->started_at && $production->production_status !== OrderItemPrintProduction::STATUS_PENDING) {
            throw new \InvalidArgumentException('Bu baskı zaten başlatılmış.');
        }

        return $this->transition(
            $production,
            [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $company->id,
                'production_note' => $note ?: $production->production_note,
                'started_at' => $production->started_at ?: now(),
            ],
            'production_assigned_external',
            $user,
            $note ?: 'İş dış üretim / fason firmaya atandı.',
            'production_started'
        );
    }

    public function markStarted(OrderItemPrintProduction $production, ?User $user = null, ?string $note = null): OrderItemPrintProduction
    {
        $this->assertReadiness($production);

        if ($production->started_at) {
            throw new \InvalidArgumentException('Bu baskı zaten başlatılmış.');
        }

        $productionType = $this->resolvedProductionType($production);

        if ($productionType === OrderItemPrintProduction::TYPE_INTERNAL && !$production->assigned_to) {
            throw new \InvalidArgumentException('Üretime başlamadan önce operatör seçin.');
        }

        if (in_array($productionType, [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true) && !$production->production_company_id) {
            throw new \InvalidArgumentException('Fasona göndermeden önce fason firma seçin.');
        }

        $status = in_array($productionType, [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true)
            ? OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR
            : OrderItemPrintProduction::STATUS_INTERNAL;

        return $this->transition(
            $production,
            [
                'production_type' => $productionType ?: $production->production_type,
                'production_status' => $status,
                'started_at' => now(),
            ],
            'production_started',
            $user,
            $note ?: 'Üretim başlatıldı.',
            'production_started'
        );
    }
    public function markPartiallyCompleted(
        OrderItemPrintProduction $production,
        float $completedQty,
        ?User $user = null,
        ?string $note = null
    ): OrderItemPrintProduction {
        $this->assertReadiness($production);

        if ($completedQty <= 0) {
            throw new \InvalidArgumentException("Basılan adet 0'dan büyük olmalı.");
        }

        $currentCompleted = (float) $production->completed_quantity;
        $planned = (float) $production->planned_quantity;
        $newCompleted = round($currentCompleted + $completedQty, 4);

        if ($newCompleted - $planned > 0.0001) {
            throw new \InvalidArgumentException('Basılan adet kalan adetten fazla olamaz.');
        }

        $remaining = max(round($planned - $newCompleted, 4), 0.0);
        $isFull = $remaining <= 0.0001;

        return $this->transition(
            $production,
            [
                'completed_quantity' => $newCompleted,
                'remaining_quantity' => $remaining,
                'production_status' => $isFull
                    ? OrderItemPrintProduction::STATUS_COMPLETED
                    : OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
                'started_at' => $production->started_at ?: now(),
                'completed_at' => $isFull ? now() : $production->completed_at,
            ],
            $isFull ? 'production_completed' : 'production_partially_completed',
            $user,
            $note ?: ($isFull ? 'Üretim tamamlandı.' : 'Üretim kısmi basıldı.'),
            $isFull ? 'production_completed' : 'production_partially_completed'
        );
    }

    public function markSentToSubcontractor(OrderItemPrintProduction $production, ?User $user = null, ?string $note = null): OrderItemPrintProduction
    {
        $this->assertReadiness($production);

        if (!$production->isOutsourced() && !$production->isExternal()) {
            throw new \InvalidArgumentException('Production must be external or outsourced before sending to subcontractor.');
        }

        if (!$production->production_company_id) {
            throw new \InvalidArgumentException('Fasona göndermeden önce fason firma seçin.');
        }

        return $this->transition(
            $production,
            [
                'production_status' => OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
                'sent_to_subcontractor_at' => now(),
            ],
            'production_sent_to_subcontractor',
            $user,
            $note ?: 'İş fason firmaya gönderildi.'
        );
    }

    public function markReturnedFromSubcontractor(OrderItemPrintProduction $production, ?User $user = null, ?string $note = null): OrderItemPrintProduction
    {
        $this->assertReadiness($production);

        return $this->transition(
            $production,
            [
                'production_status' => OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
                'returned_from_subcontractor_at' => now(),
            ],
            'production_returned_from_subcontractor',
            $user,
            $note ?: 'İş fason firmadan geri geldi.'
        );
    }

    public function markQcStarted(OrderItemPrintProduction $production, ?User $user = null, ?string $note = null): OrderItemPrintProduction
    {
        $this->assertReadiness($production);

        return $this->transition(
            $production,
            [
                'production_status' => OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
                'qc_status' => OrderItemPrintProduction::QC_WAITING,
                'qc_started_at' => now(),
            ],
            'production_qc_started',
            $user,
            $note ?: 'Kalite kontrol başlatıldı.'
        );
    }

    public function markQcPassed(OrderItemPrintProduction $production, ?User $user = null, ?string $note = null): OrderItemPrintProduction
    {
        return $this->transition(
            $production,
            [
                'qc_status' => OrderItemPrintProduction::QC_OK,
            ],
            'production_qc_passed',
            $user,
            $note ?: 'Kalite kontrol uygun bulundu.'
        );
    }

    public function markQcFailed(OrderItemPrintProduction $production, ?User $user = null, ?string $note = null): OrderItemPrintProduction
    {
        return $this->transition(
            $production,
            [
                'production_status' => OrderItemPrintProduction::STATUS_PROBLEMATIC,
                'qc_status' => OrderItemPrintProduction::QC_PROBLEMATIC,
                'issue_note' => $note ?: $production->issue_note,
            ],
            'production_qc_failed',
            $user,
            $note ?: 'Kalite kontrolde sorun tespit edildi.',
            'production_problem_reported'
        );
    }

    public function markCompleted(
        OrderItemPrintProduction $production,
        ?User $user = null,
        ?string $note = null,
        ?float $completedQuantity = null
    ): OrderItemPrintProduction
    {
        $this->assertReadiness($production);

        $planned = round((float) $production->planned_quantity, 4);
        $finalCompleted = round((float) ($completedQuantity ?? $planned), 4);

        if ($finalCompleted <= 0) {
            throw new \InvalidArgumentException("Basılan adet 0'dan büyük olmalı.");
        }

        return $this->transition(
            $production,
            [
                'completed_quantity' => $finalCompleted,
                'remaining_quantity' => 0,
                'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
                'started_at' => $production->started_at ?: now(),
                'completed_at' => now(),
            ],
            'production_completed',
            $user,
            $note ?: 'Üretim tamamlandı.',
            'production_completed'
        );
    }

    public function markIssue(OrderItemPrintProduction $production, ?User $user = null, ?string $note = null): OrderItemPrintProduction
    {
        return $this->transition(
            $production,
            [
                'production_status' => OrderItemPrintProduction::STATUS_PROBLEMATIC,
                'issue_note' => $note ?: $production->issue_note,
            ],
            'production_issue_reported',
            $user,
            $note ?: 'Üretim sorunu bildirildi.',
            'production_problem_reported'
        );
    }

    public function cancel(OrderItemPrintProduction $production, ?User $user = null, ?string $note = null): OrderItemPrintProduction
    {
        return $this->transition(
            $production,
            [
                'production_status' => OrderItemPrintProduction::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ],
            'production_cancelled',
            $user,
            $note ?: 'Üretim operasyonu iptal edildi.'
        );
    }

    private function transition(
        OrderItemPrintProduction $production,
        array $changes,
        string $actionType,
        ?User $user,
        string $note,
        ?string $notificationEventKey = null
    ): OrderItemPrintProduction {
        return DB::transaction(function () use ($production, $changes, $actionType, $user, $note, $notificationEventKey): OrderItemPrintProduction {
            $oldStatus = $production->production_status;

            return $this->persistWithSnapshot(
                $production,
                $this->normalizeQuantities($production, $changes),
                $actionType,
                $oldStatus,
                $changes['production_status'] ?? $production->production_status,
                $note,
                $user,
                $notificationEventKey
            );
        });
    }

    private function normalizeQuantities(OrderItemPrintProduction $production, array $changes): array
    {
        $planned = round((float) ($changes['planned_quantity'] ?? $production->planned_quantity), 4);
        $completed = round((float) ($changes['completed_quantity'] ?? $production->completed_quantity), 4);

        if ($completed - $planned > 0.0001) {
            throw new \InvalidArgumentException('Completed quantity cannot exceed planned quantity.');
        }

        $changes['planned_quantity'] = $planned;
        $changes['completed_quantity'] = $completed;
        $changes['remaining_quantity'] = max(round($planned - $completed, 4), 0.0);

        return $changes;
    }

    private function resolvedProductionType(OrderItemPrintProduction $production): ?string
    {
        $production->loadMissing('orderItemPrint');

        return OrderItemPrintProduction::normalizeProductionType($production->production_type)
            ?? OrderItemPrintProduction::normalizeProductionType($production->orderItemPrint?->production_type);
    }
    private function persistWithSnapshot(
        OrderItemPrintProduction $production,
        array $changes,
        string $actionType,
        ?string $oldStatus,
        ?string $newStatus,
        string $note,
        ?User $user,
        ?string $notificationEventKey = null
    ): OrderItemPrintProduction {
        $production->fill($changes);
        $production->loadMissing(['workForm', 'orderItemPrint.subcontractorCompany', 'productionCompany']);

        $fullSnapshot = $this->dataBuilder->build(
            $production->orderItemPrint,
            $production->workForm,
            $production
        );

        $production->forceFill([
            ...$changes,
            'production_snapshot' => $fullSnapshot,
            'updated_by' => $user?->id,
        ])->save();

        $production = $production->fresh(['workForm', 'orderItemPrint.subcontractorCompany', 'productionCompany']);

        if ($production->workForm) {
            $this->syncWorkFormSnapshot($production->workForm, $production, $user);
            $this->createWorkflowLog($production, $actionType, $oldStatus, $newStatus, $note, $user);
        }

        if ($production->production_status === OrderItemPrintProduction::STATUS_CANCELLED) {
            $this->subcontractorSyncService->cancelForProduction(
                $production,
                $note ?: 'Üretim iptal edildiği için fason cari hareket iptal edildi.',
                $user
            );
        } else {
            $this->subcontractorSyncService->syncProduction($production);
        }

        $production = $production->fresh([
            'workForm',
            'order',
            'orderItem',
            'orderItemPrint',
            'productionCompany',
            'assignedUser',
            'creator',
            'updater',
        ]);

        if ($notificationEventKey) {
            $this->dispatchSafely($production, $notificationEventKey, $user, $note);
        }

        return $production;
    }

    private function syncWorkFormSnapshot(
        OrderItemWorkForm $workForm,
        OrderItemPrintProduction $production,
        ?User $user
    ): void {
        $workForm->forceFill([
            'production_snapshot' => $this->dataBuilder->buildWorkFormSnapshot($production),
            'version' => (int) $workForm->version + 1,
            'updated_by' => $user?->id,
        ])->save();
    }

    private function createWorkflowLog(
        OrderItemPrintProduction $production,
        string $actionType,
        ?string $oldStatus,
        ?string $newStatus,
        string $note,
        ?User $user
    ): void {
        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $production->tenant_account_id,
            'work_form_id' => $production->work_form_id,
            'order_id' => $production->order_id,
            'order_item_id' => $production->order_item_id,
            'action_type' => $actionType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'note' => $note,
            'visibility' => 'internal',
            'created_by' => $user?->id,
        ]);
    }

    private function assertReadiness(OrderItemPrintProduction $production): void
    {
        $readiness = $this->readinessResolver->resolve($production);

        if (!$readiness['can_start']) {
            throw new \InvalidArgumentException((string) ($readiness['blocking_reason_label'] ?: 'Üretim aksiyonu için readiness koşulları sağlanmadı.'));
        }
    }

    private function dispatchSafely(
        OrderItemPrintProduction $production,
        string $eventKey,
        ?User $user,
        ?string $note = null
    ): void {
        try {
            $this->notificationEventService->dispatchEvent(
                $production->tenant,
                $eventKey,
                $production,
                [
                    'audience_type' => 'production_team',
                    'channels' => ['internal', 'email'],
                    'created_by' => $user,
                    'related_type' => $production->getMorphClass(),
                    'related_id' => $production->id,
                    'context' => [
                        'status_label' => $production->safeStatusLabel(),
                        'production_status' => $production->safeStatusLabel(),
                        'production_type_label' => $production->safeProductionTypeLabel(),
                        'planned_quantity' => round((float) $production->planned_quantity, 4),
                        'completed_quantity' => round((float) $production->completed_quantity, 4),
                        'remaining_quantity' => round((float) $production->remaining_quantity, 4),
                        'internal_note' => filled($note) ? trim((string) $note) : null,
                    ],
                ]
            );
        } catch (\Throwable) {
            // Notification failures should never break the production workflow.
        }
    }
}
