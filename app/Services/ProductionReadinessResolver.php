<?php

namespace App\Services;

use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Services\PromotionIntermediateElementPolicy;
use App\Models\OrderItemPrintSetupRequirement;
use App\Models\OrderItemWorkFormAttachment;

class ProductionReadinessResolver
{
    public function __construct(
        protected PromotionIntermediateElementPolicy $promotionIntermediateElementPolicy
    ) {}

    private const PROCUREMENT_READY_STATUSES = [
        OrderItemProcurement::STATUS_FULLY_RECEIVED,
        OrderItemProcurement::STATUS_NOT_REQUIRED,
        OrderItemProcurement::STATUS_CUSTOMER_RECEIVED,
        'tamami_geldi',
        'tedarik_gerekmiyor',
        'musteri_urunu_geldi',
    ];

    public function resolve(OrderItemPrintProduction $production): array
    {
        $production->loadMissing([
            'graphicOperation.latestAttachment',
            'orderItemPrint.graphicOperation.latestAttachment',
            'orderItemPrint.setupRequirements',
            'orderItemPrint.tenantPrintSetting.standardPrintType',
            'workForm.attachments',
            'workForm.procurement',
        ]);

        $graphic = $production->graphicOperation ?: $production->orderItemPrint?->graphicOperation;
        $finalAttachment = $graphic?->latestAttachment;
        $graphicRequired = (bool) ($production->orderItemPrint?->effectiveRequiresGraphic() ?? true);
        $graphicStatus = (string) ($graphic?->status ?? '');
        $hasFinalGraphic = $finalAttachment instanceof OrderItemWorkFormAttachment;
        $graphicReady = !$graphicRequired
            || ($graphicStatus === OrderItemPrintGraphic::STATUS_PRODUCTION_READY && $hasFinalGraphic);

        if ($graphicRequired && !$graphic && $this->canUseLegacyFallback($production)) {
            $legacyAttachment = $this->resolveLegacyAttachment($production);

            $finalAttachment = $legacyAttachment ?: $finalAttachment;
            $hasFinalGraphic = $finalAttachment instanceof OrderItemWorkFormAttachment;
            $graphicReady = data_get($production->workForm?->graphic_snapshot, 'status') === 'uretime_hazir' && $hasFinalGraphic;
        }

        $procurementStatus = (string) (
            $production->workForm?->procurement?->procurement_status
            ?: data_get($production->workForm?->procurement_snapshot, 'procurement_status', '')
        );
        $procurementReady = in_array($procurementStatus, self::PROCUREMENT_READY_STATUSES, true);
        [$setupReady, $setupRequired, $setupBlockingLabels] = $this->resolveSetupReadiness($production);

        $blockingReason = $this->blockingReason(
            $graphic,
            $graphicRequired,
            $graphicReady,
            $hasFinalGraphic,
            $procurementReady,
            $setupReady,
            $setupBlockingLabels
        );
        $readinessLabel = $this->readinessLabel(
            $graphic,
            $graphicRequired,
            $graphicReady,
            $hasFinalGraphic,
            $procurementReady,
            $setupReady
        );

        return [
            'graphic_required' => $graphicRequired,
            'graphic_ready' => $graphicReady,
            'procurement_ready' => $procurementReady,
            'setup_ready' => $setupReady,
            'setup_required' => $setupRequired,
            'setup_blocking_labels' => $setupBlockingLabels,
            'setup_blocking_reason_label' => $setupBlockingLabels !== []
                ? 'Hazırlık bekleniyor: ' . implode(', ', $setupBlockingLabels)
                : null,
            'can_start' => $graphicReady && $procurementReady && $setupReady,
            'blocking_reason_label' => $blockingReason,
            'readiness_label' => $readinessLabel,
            'final_graphic_attachment' => $finalAttachment,
            'graphic_operation' => $graphic,
            'graphic_status' => $graphic?->status,
            'graphic_status_label' => $this->graphicStatusLabel($graphic, $graphicRequired, $graphicReady, $hasFinalGraphic, $procurementReady),
            'procurement_status' => $procurementStatus,
        ];
    }

    private function canUseLegacyFallback(OrderItemPrintProduction $production): bool
    {
        return $production->graphicOperation === null
            && $production->orderItemPrint?->graphicOperation === null
            && $production->workForm !== null;
    }

    private function resolveLegacyAttachment(OrderItemPrintProduction $production): ?OrderItemWorkFormAttachment
    {
        $attachmentId = (int) data_get($production->workForm?->graphic_snapshot, 'primary_visual_attachment_id', 0);

        if ($attachmentId < 1 || !$production->workForm) {
            return null;
        }

        return $production->workForm->attachments->firstWhere('id', $attachmentId);
    }

    private function blockingReason(
        ?OrderItemPrintGraphic $graphic,
        bool $graphicRequired,
        bool $graphicReady,
        bool $hasFinalGraphic,
        bool $procurementReady,
        bool $setupReady,
        array $setupBlockingLabels
    ): ?string {
        $status = $graphic?->status;

        if (!$graphicRequired) {
            if (!$procurementReady) {
                return 'Ürün tedariki tamamlanmadan üretime başlanamaz.';
            }

            if (!$setupReady && $setupBlockingLabels !== []) {
                return 'Hazırlık bekleniyor: ' . implode(', ', $setupBlockingLabels);
            }

            return null;
        }

        if ($graphic?->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED) {
            return 'Bu baskı revize bekliyor, üretime başlanamaz.';
        }

        if (!$hasFinalGraphic && in_array($status, [
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED,
            OrderItemPrintGraphic::STATUS_APPROVED,
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
        ], true)) {
            return 'Final grafik görseli olmadan üretime başlanamaz.';
        }

        if (!$graphicReady) {
            return 'Bu baskı için grafik üretime hazır değil.';
        }

        if (!$procurementReady) {
            return 'Ürün tedariki tamamlanmadan üretime başlanamaz.';
        }

        if (!$setupReady && $setupBlockingLabels !== []) {
            return 'Hazırlık bekleniyor: ' . implode(', ', $setupBlockingLabels);
        }

        return null;
    }

    private function readinessLabel(
        ?OrderItemPrintGraphic $graphic,
        bool $graphicRequired,
        bool $graphicReady,
        bool $hasFinalGraphic,
        bool $procurementReady,
        bool $setupReady
    ): string {
        if (!$graphicRequired) {
            if ($procurementReady && $setupReady) {
                return 'Üretime Hazır';
            }

            if ($procurementReady && !$setupReady) {
                return 'Hazırlık Bekliyor';
            }

            return 'Tedarik Bekliyor';
        }

        if ($graphic?->status === OrderItemPrintGraphic::STATUS_WAITING_VISUAL) {
            return 'Grafik Bekliyor';
        }

        if ($graphic?->status === OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING) {
            return 'Grafik Onayı Bekliyor';
        }

        if ($graphic?->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED) {
            return 'Revize Bekliyor';
        }

        if ($graphicReady && $procurementReady && !$setupReady) {
            return 'Hazırlık Bekliyor';
        }

        if ($graphicReady && $procurementReady) {
            return 'Üretime Hazır';
        }

        if ($graphicReady && !$procurementReady) {
            return 'Grafik Hazır, Tedarik Bekliyor';
        }

        if (!$hasFinalGraphic && in_array($graphic?->status, [
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED,
            OrderItemPrintGraphic::STATUS_APPROVED,
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
        ], true)) {
            return 'Final Görsel Yok';
        }

        return $this->graphicStatusLabel($graphic, $graphicRequired, $graphicReady, $hasFinalGraphic, $procurementReady);
    }

    private function graphicStatusLabel(
        ?OrderItemPrintGraphic $graphic,
        bool $graphicRequired,
        bool $graphicReady,
        bool $hasFinalGraphic,
        bool $procurementReady
    ): string {
        if (!$graphicRequired) {
            return 'Grafik Gerekli Değil';
        }

        if ($graphicReady) {
            return $procurementReady ? 'Üretime Hazır' : 'Grafik Hazır, Tedarik Bekliyor';
        }

        return match ($graphic?->status) {
            OrderItemPrintGraphic::STATUS_WAITING_VISUAL, null => 'Grafik Bekliyor',
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => 'Görsel Yüklendi, Üretime Hazır Değil',
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'Grafik Onayı Bekliyor',
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'Revize Bekliyor',
            OrderItemPrintGraphic::STATUS_APPROVED => $hasFinalGraphic ? 'Grafik Onaylandı, Üretime Hazır Değil' : 'Final Görsel Yok',
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY => 'Final Görsel Yok',
            default => 'Grafik Bekliyor',
        };
    }

    private function resolveSetupReadiness(OrderItemPrintProduction $production): array
    {
        if (! $this->promotionIntermediateElementPolicy->blocksProductionReadiness()) {
            return [true, false, []];
        }

        $requirements = $production->orderItemPrint?->setupRequirements;

        if ($requirements && $requirements->isNotEmpty()) {
            $blockingRequirements = $requirements
                ->filter(fn (OrderItemPrintSetupRequirement $requirement) => in_array($requirement->status, [
                    OrderItemPrintSetupRequirement::STATUS_PENDING,
                    OrderItemPrintSetupRequirement::STATUS_REQUESTED,
                ], true))
                ->values();

            return [
                $blockingRequirements->isEmpty(),
                true,
                $blockingRequirements
                    ->map(fn (OrderItemPrintSetupRequirement $requirement) => $requirement->safeSetupTypeLabel())
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        $clicheRequired = (bool) $production->cliche_required;
        $clicheStatus = (string) $production->cliche_status;
        $legacyReady = !$clicheRequired || in_array($clicheStatus, [
            OrderItemPrintProduction::CLICHE_READY,
            OrderItemPrintProduction::CLICHE_AVAILABLE,
            OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
        ], true);

        if (!$legacyReady) {
            return [false, $clicheRequired, ['Klişe']];
        }

        return [true, $clicheRequired, []];
    }
}
