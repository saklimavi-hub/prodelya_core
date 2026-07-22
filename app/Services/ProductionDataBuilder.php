<?php

namespace App\Services;

use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemPrintSetupRequirement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemProcurement;
use Illuminate\Support\Str;

class ProductionDataBuilder
{
    public function __construct(
        protected ProductionReadinessResolver $readinessResolver
    ) {}

    public function build(
        OrderItemPrint $print,
        ?OrderItemWorkForm $workForm = null,
        ?OrderItemPrintProduction $production = null
    ): array {
        $print->loadMissing([
            'order',
            'orderItem',
            'subcontractorCompany',
            'setupRequirements.assignedCompany',
        ]);

        $workForm ??= $production?->workForm ?? $print->orderItem?->workForm;
        if ($workForm) {
            $workForm->loadMissing(['orderItem.procurement']);
        }

        if ($production) {
            $production->loadMissing([
                'graphicOperation.latestAttachment',
                'workForm.attachments',
                'workForm.procurement',
            ]);
        }

        $productSnapshot = is_array($workForm?->product_snapshot) ? $workForm->product_snapshot : [];
        $procurementSnapshot = is_array($workForm?->procurement_snapshot) ? $workForm->procurement_snapshot : [];
        $subcontractTracking = $this->subcontractTrackingSnapshot($production);
        $readiness = $production ? $this->readinessResolver->resolve($production) : null;
        $finalGraphic = $readiness['final_graphic_attachment'] ?? null;
        $procurementStatus = (string) ($readiness['procurement_status'] ?? data_get($procurementSnapshot, 'procurement_status', ''));
        $graphicRequired = (bool) ($readiness['graphic_required'] ?? true);

        $productionType = $production?->production_type
            ?: OrderItemPrintProduction::normalizeProductionType($print->production_type);
        $productionStatus = $production?->production_status ?? OrderItemPrintProduction::STATUS_PENDING;
        $clicheStatus = $production?->cliche_status
            ?: $this->normalizeLegacyClicheStatus($print->cliche_status)
            ?: OrderItemPrintProduction::CLICHE_NOT_REQUIRED;
        $qcStatus = $production?->qc_status ?? ($print->orderItem?->has_print
            ? OrderItemPrintProduction::QC_WAITING
            : OrderItemPrintProduction::QC_WAITING);
        $plannedQuantity = round((float) ($production?->planned_quantity ?? $print->print_quantity ?? 0), 4);
        $completedQuantity = round((float) ($production?->completed_quantity ?? 0), 4);
        $remainingQuantity = round((float) ($production?->remaining_quantity ?? max($plannedQuantity - $completedQuantity, 0)), 4);
        $legacyPreparationRequired = (bool) ($production?->cliche_required ?? ($clicheStatus !== OrderItemPrintProduction::CLICHE_NOT_REQUIRED));
        $legacyPreparationReady = !$legacyPreparationRequired || in_array($clicheStatus, [
            OrderItemPrintProduction::CLICHE_READY,
            OrderItemPrintProduction::CLICHE_AVAILABLE,
        ], true);
        $printSize = $print->print_size;
        $printLocation = $print->print_location;
        $productCode = $print->orderItem?->product_code ?: data_get($productSnapshot, 'product_code');
        $receivedQuantity = (float) (
            $workForm?->procurement?->received_quantity
            ?? data_get($procurementSnapshot, 'received_quantity', 0)
        );
        $setupSummary = $print->setupStatusSummary();
        $setupRequired = (bool) data_get($setupSummary, 'required', false);
        $setupPending = (int) data_get($setupSummary, 'pending_count', 0);
        $setupReady = (int) data_get($setupSummary, 'ready_count', 0);
        $preparationRequired = $setupRequired ? false : $legacyPreparationRequired;
        $preparationReady = $setupRequired ? true : $legacyPreparationReady;
        $uiCanStart = (bool) ($readiness['can_start'] ?? false) && $preparationReady;

        return [
            'order_id' => $print->order_id,
            'order_number' => $print->order?->document_number,
            'work_form_id' => $workForm?->id,
            'work_form_number' => $workForm?->work_form_number,
            'order_item_id' => $print->order_item_id,
            'order_item_print_id' => $print->id,
            'product_name' => $print->orderItem?->product_name ?: data_get($productSnapshot, 'product_name'),
            'product_code' => $print->orderItem?->product_code ?: data_get($productSnapshot, 'product_code'),
            'product_image_url' => data_get($productSnapshot, 'image_url'),
            'quantity' => (float) ($print->orderItem?->quantity ?? data_get($productSnapshot, 'quantity', 0)),
            'unit' => $print->orderItem?->unit ?: data_get($productSnapshot, 'unit'),
            'print_sequence' => $this->resolvePrintSequence($workForm, $print),
            'print_type' => $print->print_type,
            'print_option' => $print->print_option,
            'print_location' => $printLocation,
            'print_size' => $printSize,
            'print_quantity' => (float) ($print->print_quantity ?? 0),
            'production_type' => $productionType,
            'production_type_label' => $this->productionTypeLabel($productionType),
            'production_status' => $productionStatus,
            'production_status_label' => $this->productionStatusLabel($productionStatus),
            'production_company_name' => $production?->productionCompany?->legal_name,
            'production_unit_name' => $production?->production_unit_name,
            'planned_quantity' => $plannedQuantity,
            'completed_quantity' => $completedQuantity,
            'remaining_quantity' => $remainingQuantity,
            'cliche_required' => (bool) ($production?->cliche_required ?? ($clicheStatus !== OrderItemPrintProduction::CLICHE_NOT_REQUIRED)),
            'cliche_status' => $clicheStatus,
            'cliche_status_label' => $this->clicheStatusLabel($clicheStatus),
            'preparation_required' => $preparationRequired,
            'preparation_ready' => $preparationReady,
            'preparation_label' => $this->preparationLabel($preparationRequired, $clicheStatus),
            'setup_required' => $setupRequired,
            'setup_summary' => $setupSummary,
            'setup_ready' => $setupRequired ? $setupPending === 0 : true,
            'setup_summary_label' => $this->setupSummaryLabel($setupRequired, $setupReady, $setupPending),
            'qc_status' => $qcStatus,
            'qc_status_label' => $this->qcStatusLabel($qcStatus),
            'graphic_status_label' => $this->displayGraphicStatusLabel($readiness, $workForm),
            'procurement_status_label' => data_get($procurementSnapshot, 'procurement_status_label', '-'),
            'procurement_status' => $procurementStatus,
            'graphic_status_tone' => $this->graphicStatusTone($readiness),
            'procurement_status_tone' => $this->procurementStatusTone($procurementStatus, (bool) ($readiness['procurement_ready'] ?? false)),
            'graphic_required' => $graphicRequired,
            'graphic_ready' => (bool) ($readiness['graphic_ready'] ?? false),
            'procurement_ready' => (bool) ($readiness['procurement_ready'] ?? false),
            'can_start' => (bool) ($readiness['can_start'] ?? false),
            'ui_can_start' => $uiCanStart,
            'readiness_label' => $readiness['readiness_label'] ?? $this->legacyGraphicStatusLabel($workForm),
            'blocking_reason_label' => $readiness['blocking_reason_label'],
            'final_graphic' => $finalGraphic ? [
                'id' => $finalGraphic->id,
                'file_name' => $finalGraphic->file_name ?: basename((string) $finalGraphic->file_path),
                'preview_url' => route('admin.work-forms.attachments.preview', $finalGraphic),
                'is_image' => $finalGraphic->isImage(),
                'open_url' => route('admin.work-forms.attachments.preview', $finalGraphic),
            ] : null,
            'product_control' => [
                'product_image_url' => data_get($productSnapshot, 'image_url'),
                'order_product_code' => $productCode,
                'incoming_product_code' => $productCode,
                'received_quantity' => $receivedQuantity,
            ],
            'status_banner' => $this->statusBannerLabel($productionStatus, $qcStatus, $readiness, $preparationRequired, $preparationReady),
            'status_help' => $this->statusHelpText($readiness, $preparationRequired, $preparationReady),
            'start_blockers' => $this->startBlockers($readiness, $preparationRequired, $preparationReady),
            'start_status_label' => $this->startStatusLabel($uiCanStart, $readiness, $preparationRequired, $preparationReady),
            'start_status_tone' => $this->startStatusTone($uiCanStart, $readiness, $preparationRequired, $preparationReady),
            'readiness_warnings' => $this->readinessWarnings($workForm, $readiness),
            'public_status_label' => $this->publicStatusLabel($productionStatus),
            'subcontract_tracking' => $subcontractTracking,
        ];
    }

    public function buildWorkFormSnapshot(OrderItemPrintProduction $production): array
    {
        $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
        $existing = is_array($production->workForm?->production_snapshot) ? $production->workForm->production_snapshot : [];
        $note = $this->sanitizeNote($production->production_note);
        $issueNote = $this->sanitizeNote($production->issue_note);
        $qcNote = $this->sanitizeNote(data_get($existing, 'qc_note'));

        return [
            'production_id' => $production->id,
            'status' => $production->production_status,
            'status_label' => $production->safeStatusLabel(),
            'production_status' => $production->production_status,
            'production_status_label' => $production->safeStatusLabel(),
            'production_type' => $production->production_type,
            'production_type_label' => $production->safeProductionTypeLabel(),
            'production_company_name' => data_get($snapshot, 'production_company_name'),
            'production_unit_name' => $production->production_unit_name,
            'planned_quantity' => (float) $production->planned_quantity,
            'completed_quantity' => (float) $production->completed_quantity,
            'remaining_quantity' => (float) $production->remaining_quantity,
            'cliche_required' => (bool) $production->cliche_required,
            'cliche_status' => $production->cliche_status,
            'cliche_status_label' => $production->safeClicheStatusLabel(),
            'qc_status' => $production->qc_status,
            'qc_status_label' => $production->safeQcStatusLabel(),
            'graphic_status_label' => data_get($snapshot, 'graphic_status_label', '-'),
            'procurement_status_label' => data_get($snapshot, 'procurement_status_label', '-'),
            'readiness_warnings' => array_values((array) data_get($snapshot, 'readiness_warnings', [])),
            'public_status_label' => $this->publicStatusLabel($production->production_status),
            'setup_required' => (bool) data_get($snapshot, 'setup_required', false),
            'setup_summary' => data_get($snapshot, 'setup_summary', ['required' => false, 'items' => []]),
            'setup_summary_label' => data_get($snapshot, 'setup_summary_label'),
            'note' => $note,
            'issue_note' => $issueNote,
            'qc_note' => $qcNote,
            'photo_count' => (int) data_get($existing, 'photo_count', 0),
            'updated_at' => optional($production->updated_at)->toAtomString(),
        ];
    }

    private function buildWorkFormProductionRowSnapshot(OrderItemPrintProduction $production, array $snapshot): array
    {
        $tracking = (array) data_get($snapshot, 'subcontract_tracking', []);
        $baseline = (array) data_get($tracking, 'send_baseline', []);
        $sentQuantity = data_get($baseline, 'remaining_quantity_at_send');
        $receivedQuantity = in_array($production->production_status, [
            OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
            OrderItemPrintProduction::STATUS_COMPLETED,
        ], true) ? (float) $production->completed_quantity : null;

        return [
            'production_id' => $production->id,
            'order_item_print_id' => $production->order_item_print_id,
            'sequence' => data_get($snapshot, 'print_sequence', '-'),
            'print_type' => data_get($snapshot, 'print_type', '-'),
            'print_option' => data_get($snapshot, 'print_option', '-'),
            'production_type' => $production->production_type,
            'production_type_label' => $production->safeProductionTypeLabel(),
            'operator_name' => $production->assignedUser?->name,
            'production_unit_name' => $production->production_unit_name,
            'production_company_name' => data_get($snapshot, 'production_company_name'),
            'planned_quantity' => (float) $production->planned_quantity,
            'completed_quantity' => (float) $production->completed_quantity,
            'remaining_quantity' => (float) $production->remaining_quantity,
            'sent_quantity' => $sentQuantity !== null ? (float) $sentQuantity : null,
            'received_from_subcontractor_quantity' => $receivedQuantity,
            'remaining_from_subcontractor_quantity' => $sentQuantity !== null ? max(round((float) $sentQuantity - (float) ($receivedQuantity ?? 0), 4), 0.0) : null,
            'prior_internal_completed_quantity' => data_get($baseline, 'completed_quantity_before_send'),
            'legacy_subcontract_baseline_missing' => in_array($production->production_type, [OrderItemPrintProduction::TYPE_EXTERNAL, OrderItemPrintProduction::TYPE_OUTSOURCED], true) && $sentQuantity === null,
            'production_status' => $production->production_status,
            'production_status_label' => $production->safeStatusLabel(),
            'graphic_status_label' => data_get($snapshot, 'graphic_status_label', '-'),
            'procurement_status_label' => data_get($snapshot, 'procurement_status_label', '-'),
            'qc_status_label' => ($production->qc_status === 'gerekli_degil' || ($production->qc_status === OrderItemPrintProduction::QC_WAITING && $production->production_status !== OrderItemPrintProduction::STATUS_QUALITY_CONTROL)) ? 'Kalite Kontrol Gerekli Değil' : $production->safeQcStatusLabel(),
            'qc_required' => $production->qc_status !== 'gerekli_degil',
            'public_status_label' => $this->publicStatusLabel($production->production_status),
            'final_graphic' => data_get($snapshot, 'final_graphic'),
            'photo_count' => 0,
            'photos' => [],
            'is_outsourced' => in_array($production->production_type, [OrderItemPrintProduction::TYPE_EXTERNAL, OrderItemPrintProduction::TYPE_OUTSOURCED], true),
            'process_steps' => [],
        ];
    }
    public function publicStatusLabel(string $productionStatus): string
    {
        return match ($productionStatus) {
            OrderItemPrintProduction::STATUS_PENDING => 'Üretim bekliyor',
            OrderItemPrintProduction::STATUS_INTERNAL,
            OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR => 'Üretimde',
            OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR => 'Kalite kontrol bekliyor',
            OrderItemPrintProduction::STATUS_QUALITY_CONTROL => 'Kalite kontrolde',
            OrderItemPrintProduction::STATUS_COMPLETED => 'Üretim tamamlandı',
            OrderItemPrintProduction::STATUS_PROBLEMATIC => 'Üretim süreci kontrol ediliyor',
            OrderItemPrintProduction::STATUS_CANCELLED => 'Üretim süreci durduruldu',
            default => 'Üretim bekliyor',
        };
    }

    private function subcontractTrackingSnapshot(?OrderItemPrintProduction $production): array
    {
        if (!$production) {
            return [];
        }

        $existing = is_array($production->production_snapshot) ? $production->production_snapshot : [];
        $tracking = (array) data_get($existing, 'subcontract_tracking', []);

        if (
            $production->production_status === OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR
            && !is_array(data_get($tracking, 'send_baseline'))
        ) {
            $tracking['send_baseline'] = [
                'captured_at' => optional($production->sent_to_subcontractor_at ?: now())->toAtomString(),
                'production_id' => $production->id,
                'order_item_print_id' => $production->order_item_print_id,
                'planned_quantity_at_send' => round((float) $production->planned_quantity, 4),
                'completed_quantity_before_send' => round((float) $production->completed_quantity, 4),
                'remaining_quantity_at_send' => round((float) $production->remaining_quantity, 4),
                'source' => 'production_snapshot.send_baseline',
            ];
        }

        return $tracking;
    }
    private function resolvePrintSequence(?OrderItemWorkForm $workForm, OrderItemPrint $print): ?string
    {
        $rows = collect($workForm?->print_snapshot ?? []);
        $match = $rows->first(function (array $row) use ($print): bool {
            return (string) data_get($row, 'print_type') === (string) $print->print_type
                && (string) data_get($row, 'print_option') === (string) $print->print_option
                && (float) data_get($row, 'print_quantity', 0) === (float) $print->print_quantity;
        });

        return data_get($match, 'sequence');
    }

    private function readinessWarnings(?OrderItemWorkForm $workForm, ?array $readiness = null): array
    {
        if (!$workForm) {
            return [];
        }

        $warnings = [];
        $procurementStatus = (string) data_get($workForm->procurement_snapshot, 'procurement_status', '');
        $graphicReady = (bool) ($readiness['graphic_ready'] ?? false);
        $graphicRequired = (bool) ($readiness['graphic_required'] ?? true);

        if ($graphicRequired && !$graphicReady) {
            $warnings[] = 'Grafik henüz üretime hazır değil.';
        }

        if ($procurementStatus === 'musteri_urunu_bekleniyor') {
            $warnings[] = 'Müşteri ürünü bekleniyor.';
        }

        if ($procurementStatus === 'kismi_geldi') {
            $warnings[] = 'Kısmi gelen ürün var; üretime kısmi başlanacaksa kontrol edin.';
        } elseif ($procurementStatus !== '' && !in_array($procurementStatus, [
            'tamami_geldi',
            'tedarik_gerekmiyor',
            'musteri_urunu_geldi',
        ], true)) {
            $warnings[] = 'Tedarik süreci tamamlanmadı.';
        }

        return array_values(array_unique($warnings));
    }

    private function graphicStatusTone(?array $readiness): string
    {
        $graphicStatus = (string) ($readiness['graphic_status'] ?? '');

        return match (true) {
            $graphicStatus === 'revision_requested' => 'red',
            ($readiness['readiness_label'] ?? null) === 'Final Görsel Yok' => 'red',
            (bool) ($readiness['graphic_ready'] ?? false) => 'green',
            in_array($graphicStatus, ['approved', 'visual_uploaded', 'customer_approval_waiting'], true) => 'amber',
            default => 'amber',
        };
    }

    private function displayGraphicStatusLabel(?array $readiness, ?OrderItemWorkForm $workForm): string
    {
        if (!$readiness) {
            return $this->legacyGraphicStatusLabel($workForm);
        }

        if ((bool) ($readiness['graphic_required'] ?? true) === false) {
            return 'Grafik Gerekli Değil';
        }

        $graphicStatus = (string) ($readiness['graphic_status'] ?? '');

        if (($readiness['readiness_label'] ?? null) === 'Final Görsel Yok') {
            return 'Final Görsel Yok';
        }

        if ((bool) ($readiness['graphic_ready'] ?? false)) {
            return 'Üretime Hazır';
        }

        return match ($graphicStatus) {
            'waiting_visual', '' => 'Grafik Bekliyor',
            'visual_uploaded' => 'Görsel Yüklendi',
            'customer_approval_waiting' => 'Grafik Onayı Bekliyor',
            'revision_requested' => 'Revize Bekliyor',
            'approved' => 'Onaylandı',
            'production_ready' => 'Final Görsel Yok',
            default => $this->legacyGraphicStatusLabel($workForm),
        };
    }

    private function procurementStatusTone(string $procurementStatus, bool $procurementReady): string
    {
        if ($procurementReady) {
            return 'green';
        }

        return match ($procurementStatus) {
            'kismi_geldi' => 'amber',
            default => 'red',
        };
    }

    private function legacyGraphicStatusLabel(?OrderItemWorkForm $workForm): string
    {
        $status = data_get($workForm?->graphic_snapshot, 'status');

        return match ($status) {
            'uretime_hazir' => 'Üretime Hazır',
            'bekliyor' => 'Bekliyor',
            'gorsel_eklendi' => 'Görsel Eklendi',
            default => $status ? ucfirst(str_replace('_', ' ', $status)) : '-',
        };
    }

    private function productionTypeLabel(?string $type): ?string
    {
        if (!$type) {
            return null;
        }

        return OrderItemPrintProduction::productionTypeLabels()[$type]
            ?? ucfirst(str_replace('_', ' ', $type));
    }

    private function productionStatusLabel(string $status): string
    {
        return OrderItemPrintProduction::statusLabels()[$status]
            ?? ucfirst(str_replace('_', ' ', $status));
    }

    private function clicheStatusLabel(?string $status): ?string
    {
        if (!$status) {
            return null;
        }

        return OrderItemPrintProduction::clicheStatusLabels()[$status]
            ?? ucfirst(str_replace('_', ' ', $status));
    }

    private function qcStatusLabel(?string $status): ?string
    {
        if (!$status) {
            return null;
        }

        return OrderItemPrintProduction::qcStatusLabels()[$status]
            ?? ucfirst(str_replace('_', ' ', $status));
    }

    private function preparationLabel(bool $required, ?string $clicheStatus): ?string
    {
        if (!$required) {
            return null;
        }

        return match ($clicheStatus) {
            OrderItemPrintProduction::CLICHE_WAITING => 'Klişe Bekliyor',
            OrderItemPrintProduction::CLICHE_READY => 'Klişe Hazır',
            OrderItemPrintProduction::CLICHE_AVAILABLE => 'Klişe Mevcut',
            OrderItemPrintProduction::CLICHE_NEW => 'Klişe Yeni Yapılacak',
            default => 'Hazırlık Bekliyor',
        };
    }

    private function setupSummaryLabel(bool $required, int $readyCount, int $pendingCount): ?string
    {
        if (!$required) {
            return null;
        }

        if ($pendingCount > 0) {
            return 'Hazırlık bekliyor';
        }

        if ($readyCount > 0) {
            return 'Hazırlık hazır';
        }

        return 'Hazırlık planlandı';
    }

    private function statusBannerLabel(
        string $productionStatus,
        ?string $qcStatus,
        ?array $readiness,
        bool $preparationRequired,
        bool $preparationReady
    ): string {
        if ($productionStatus === OrderItemPrintProduction::STATUS_COMPLETED) {
            return 'Tamamlandı';
        }

        if ($productionStatus === OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED) {
            return 'Kısmi Basıldı';
        }

        if ($productionStatus === OrderItemPrintProduction::STATUS_QUALITY_CONTROL || $qcStatus === OrderItemPrintProduction::QC_WAITING) {
            if ($productionStatus === OrderItemPrintProduction::STATUS_QUALITY_CONTROL) {
                return 'QC Bekliyor';
            }
        }

        $graphicStatus = (string) ($readiness['graphic_status'] ?? '');
        if ($graphicStatus === 'revision_requested') {
            return 'Revize Bekliyor';
        }

        if (!(bool) ($readiness['graphic_ready'] ?? false)) {
            return match ($readiness['readiness_label'] ?? null) {
                'Final Görsel Yok' => 'Final Görsel Yok',
                'Grafik Onayı Bekliyor' => 'Grafik Bekliyor',
                default => 'Grafik Bekliyor',
            };
        }

        if (!(bool) ($readiness['procurement_ready'] ?? false)) {
            return 'Tedarik Bekliyor';
        }

        if (!(bool) ($readiness['setup_ready'] ?? true)) {
            return 'Hazırlık Bekliyor';
        }

        if ($preparationRequired && !$preparationReady) {
            return 'Klişe Bekliyor';
        }

        return match ($productionStatus) {
            OrderItemPrintProduction::STATUS_INTERNAL,
            OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
            OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR => 'Baskı Başladı',
            OrderItemPrintProduction::STATUS_PROBLEMATIC => 'Sorunlu',
            default => 'Baskıya Başlanabilir',
        };
    }

    private function statusHelpText(?array $readiness, bool $preparationRequired, bool $preparationReady): string
    {
        if (!(bool) ($readiness['graphic_ready'] ?? false)) {
            return (string) ($readiness['blocking_reason_label'] ?: 'Bu baskı için final grafik hazır olmadan üretim başlatılmamalı.');
        }

        if (!(bool) ($readiness['procurement_ready'] ?? false)) {
            return 'Grafik hazır olsa bile ürün tedariki tamamlanmadan üretim başlatılmaz.';
        }

        if (!(bool) ($readiness['setup_ready'] ?? true)) {
            return (string) (($readiness['setup_blocking_reason_label'] ?? null) ?: 'Bu baskı için gerekli hazırlık tamamlanmadan baskıya başlanmaz.');
        }

        if ($preparationRequired && !$preparationReady) {
            return 'Bu baskı için gerekli ara eleman hazır olmadan baskıya başlanmaz.';
        }

        return 'Grafik, ürün ve hazırlık kontrolleri uygun. Operasyon üretime alınabilir.';
    }

    private function startBlockers(?array $readiness, bool $preparationRequired, bool $preparationReady): array
    {
        $items = [];

        if (!(bool) ($readiness['graphic_ready'] ?? false)) {
            $items[] = 'Grafik bekleniyor';
        }

        if (!(bool) ($readiness['procurement_ready'] ?? false)) {
            $items[] = 'Tedarik bekleniyor';
        }

        if (!(bool) ($readiness['setup_ready'] ?? true)) {
            foreach ((array) ($readiness['setup_blocking_labels'] ?? []) as $label) {
                $items[] = 'Hazırlık bekleniyor: ' . $label;
            }
        }

        if ($preparationRequired && !$preparationReady) {
            $items[] = 'Klişe bekleniyor';
        }

        if (($readiness['readiness_label'] ?? null) === 'Final Görsel Yok') {
            $items[] = 'Final görsel yok';
        }

        if (($readiness['graphic_status'] ?? null) === 'revision_requested') {
            $items[] = 'Revize bekliyor';
        }

        return array_values(array_unique($items));
    }

    private function startStatusLabel(bool $uiCanStart, ?array $readiness, bool $preparationRequired, bool $preparationReady): string
    {
        if ($uiCanStart) {
            return 'Başlanabilir';
        }

        if (($readiness['graphic_status'] ?? null) === 'revision_requested') {
            return 'Revize bekliyor';
        }

        if (($readiness['readiness_label'] ?? null) === 'Final Görsel Yok') {
            return 'Final görsel yok';
        }

        if (!(bool) ($readiness['graphic_ready'] ?? false)) {
            return 'Grafik bekliyor';
        }

        if (!(bool) ($readiness['procurement_ready'] ?? false)) {
            return 'Tedarik bekliyor';
        }

        if (!(bool) ($readiness['setup_ready'] ?? true)) {
            return 'Hazırlık bekliyor';
        }

        if ($preparationRequired && !$preparationReady) {
            return 'Klişe bekliyor';
        }

        return 'Kontrol gerekli';
    }

    private function startStatusTone(bool $uiCanStart, ?array $readiness, bool $preparationRequired, bool $preparationReady): string
    {
        if ($uiCanStart) {
            return 'green';
        }

        if (($readiness['graphic_status'] ?? null) === 'revision_requested' || ($readiness['readiness_label'] ?? null) === 'Final Görsel Yok') {
            return 'red';
        }

        if (
            !(bool) ($readiness['graphic_ready'] ?? false)
            || !(bool) ($readiness['procurement_ready'] ?? false)
            || !(bool) ($readiness['setup_ready'] ?? true)
            || ($preparationRequired && !$preparationReady)
        ) {
            return 'amber';
        }

        return 'gray';
    }

    private function normalizeLegacyClicheStatus(?string $status): ?string
    {
        $normalized = trim(Str::ascii(mb_strtolower((string) $status)));

        return match ($normalized) {
            '', 'null' => null,
            'gerekli degil', 'gerekli_degil' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
            'mevcut' => OrderItemPrintProduction::CLICHE_AVAILABLE,
            'yeni yapilacak', 'yeni_yapilacak' => OrderItemPrintProduction::CLICHE_NEW,
            'bekleniyor' => OrderItemPrintProduction::CLICHE_WAITING,
            'hazir' => OrderItemPrintProduction::CLICHE_READY,
            default => null,
        };
    }

    private function sanitizeNote(mixed $note): ?string
    {
        if (!is_scalar($note)) {
            return null;
        }

        $value = trim((string) $note);

        if ($value === '') {
            return null;
        }

        foreach ($this->forbiddenTextFragments() as $fragment) {
            if (str_contains(mb_strtolower($value), $fragment)) {
                return null;
            }
        }

        return $value;
    }

    private function forbiddenTextFragments(): array
    {
        return [
            'fiyat',
            'price',
            'cost',
            'maliyet',
            'kdv',
            'kar',
            'kâr',
            'margin',
            'toplam',
            'group_code',
            'raw_mapping',
        ];
    }
}
