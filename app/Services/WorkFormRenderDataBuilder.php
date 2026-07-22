<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\OrderItemPrintProduction;
use App\Services\ProductDataHub\ProductHubSafeImageUrlService;
use App\Support\WorkFormActivityLabelResolver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

class WorkFormRenderDataBuilder
{
    public function __construct(
        protected WorkFormQrCodeService $qrCodeService,
        protected TenantCompanyProfileService $tenantCompanyProfileService,
        protected ProductHubSafeImageUrlService $safeImageUrlService,
        protected WorkFormActivityLabelResolver $activityLabelResolver,
        protected ProductionDataBuilder $productionDataBuilder,
    ) {
    }

    public function build(OrderItemWorkForm $workForm, bool $useLiveProductionRows = true): array
    {
        $workForm->loadMissing([
            'tenant',
            'attachments.uploader',
            'activityLogs.attachment',
            'systemWorkFolder',
        ]);

        if ($useLiveProductionRows) {
            $workForm->loadMissing([
                'printProductions.orderItemPrint.subcontractorCompany',
                'printProductions.orderItemPrint.tenantPrintSetting.standardPrintType',
                'printProductions.orderItemPrint.graphicOperation.latestAttachment',
                'printProductions.graphicOperation.latestAttachment',
                'printProductions.productionCompany',
                'printProductions.assignedUser',
                'procurement',
            ]);
        }

        $attachments = $workForm->attachments
            ->sortBy(static function (OrderItemWorkFormAttachment $attachment): string {
                return implode('|', [
                    (string) $attachment->attachment_type,
                    str_pad((string) $attachment->sort_order, 6, '0', STR_PAD_LEFT),
                    str_pad((string) $attachment->id, 10, '0', STR_PAD_LEFT),
                ]);
            })
            ->values();

        $orderSnapshot = $workForm->order_snapshot ?? [];
        $customerSnapshot = $workForm->customer_snapshot ?? [];
        $productSnapshot = $workForm->product_snapshot ?? [];
        $printSnapshot = collect($workForm->print_snapshot ?? [])->values()->all();
        $procurementSnapshot = $workForm->procurement_snapshot ?? [];
        $graphicSnapshot = $workForm->graphic_snapshot ?? [];
        $productionSnapshot = $workForm->production_snapshot ?? [];
        $deliverySnapshot = $workForm->delivery_snapshot ?? [];

        $graphicAttachments = $this->mapAttachments(
            $attachments->whereIn('attachment_type', ['graphic_visual', 'customer_approval'])
        );
        $productionPhotos = $this->mapAttachments(
            $attachments->where('attachment_type', 'production_photo')
        );
        $deliveryAttachments = $this->mapAttachments(
            $attachments->whereIn('attachment_type', ['delivery_photo', 'delivery_document'])
        );
        $exactProductionRows = $useLiveProductionRows
            ? $this->buildLiveProductionRows($workForm, $productionPhotos)
            : collect((array) data_get($productionSnapshot, 'production_rows', []))->values();

        $primaryGraphicAttachmentId = data_get($graphicSnapshot, 'primary_visual_attachment_id');
        $primaryGraphicAttachment = $primaryGraphicAttachmentId
            ? $graphicAttachments->firstWhere('id', $primaryGraphicAttachmentId)
            : $graphicAttachments->firstWhere('attachment_type', 'graphic_visual');

        if (!$primaryGraphicAttachment) {
            $primaryGraphicAttachment = $graphicAttachments->first();
        }

        return [
            'workForm' => $workForm,
            'tenantName' => $workForm->tenant
                ? $this->tenantCompanyProfileService->getProfile($workForm->tenant)['display_name']
                : 'Prodelya',
            'backUrl' => $workForm->order_id
                ? route('admin.orders.show', $workForm->order_id)
                : route('admin.orders.index'),
            'trackingUrl' => $this->qrCodeService->trackingUrl($workForm),
            'qrSvg' => $this->qrCodeService->qrSvg($workForm, 132, 1),
            'renderedAt' => now(),
            'orderSnapshot' => $orderSnapshot,
            'customerSnapshot' => $customerSnapshot,
            'productSnapshot' => $productSnapshot,
            'safeProductImageUrlForAdmin' => $this->safeImageUrlService->resolveFromSnapshot($productSnapshot, 'work_form_admin'),
            'printSnapshot' => $printSnapshot,
            'procurementSnapshot' => $procurementSnapshot,
            'graphicSnapshot' => $graphicSnapshot,
            'productionSnapshot' => $productionSnapshot,
            'deliverySnapshot' => $deliverySnapshot,
            'publicProductionStatusLabel' => $this->resolvePublicProductionStatusLabel($productionSnapshot),
            'exactProductionRows' => $exactProductionRows,
            'graphicAttachments' => $graphicAttachments,
            'primaryGraphicAttachment' => $primaryGraphicAttachment,
            'productionPhotos' => $productionPhotos,
            'deliveryAttachments' => $deliveryAttachments,
            'systemWorkFolder' => $this->mapWorkFolder($workForm->systemWorkFolder),
            'operationSummary' => [
                'graphic' => $this->normalizeStatusLabel(data_get($graphicSnapshot, 'status'), 'Bekliyor'),
                'procurement' => data_get($procurementSnapshot, 'procurement_status_label')
                    ?: $this->buildProcurementLabel($productSnapshot),
                'production' => data_get($productionSnapshot, 'production_status_label')
                    ?: $this->normalizeStatusLabel(data_get($productionSnapshot, 'status'), 'Bekliyor'),
                'quality_control' => data_get($productionSnapshot, 'qc_status_label')
                    ?: $this->normalizeStatusLabel(data_get($productionSnapshot, 'qc_status'), 'Bekliyor'),
                'delivery' => data_get($deliverySnapshot, 'delivery_status_label')
                    ?: data_get($deliverySnapshot, 'status_label')
                    ?: $this->normalizeStatusLabel(data_get($deliverySnapshot, 'status'), 'Bekliyor'),
            ],
            'workflowHistory' => $this->mapWorkflowHistory($workForm),
        ];
    }

    private function mapAttachments(Collection $attachments): Collection
    {
        return $attachments
            ->map(fn (OrderItemWorkFormAttachment $attachment) => [
                'id' => $attachment->id,
                'order_item_print_id' => $attachment->order_item_print_id,
                'order_item_print_graphic_id' => $attachment->order_item_print_graphic_id,
                'attachment_type' => $attachment->attachment_type,
                'file_path' => $attachment->file_path,
                'file_name' => $attachment->file_name ?: basename((string) $attachment->file_path),
                'mime_type' => $attachment->mime_type,
                'disk' => $attachment->disk,
                'note' => $attachment->note,
                'sort_order' => $attachment->sort_order,
                'visibility' => $attachment->visibility,
                'visibility_label' => $attachment->isCustomerVisible() ? 'Müşteriye Açık' : 'İç Kayıt',
                'is_image' => $attachment->isImage(),
                'is_document' => $attachment->isDocument(),
                'preview_url' => $this->resolvePreviewUrl($attachment),
                'created_at' => optional($attachment->created_at)->format('d.m.Y H:i'),
                'uploader_name' => $attachment->uploader?->name,
            ])
            ->values();
    }

    private function buildLiveProductionRows(OrderItemWorkForm $workForm, Collection $productionPhotos): Collection
    {
        $productions = $workForm->printProductions
            ->sortBy(fn (OrderItemPrintProduction $production): string => (string) data_get(
                $this->productionDataBuilder->build($production->orderItemPrint, $workForm, $production),
                'print_sequence',
                str_pad((string) $production->id, 10, '0', STR_PAD_LEFT)
            ))
            ->values();

        return $productions
            ->map(function (OrderItemPrintProduction $production) use ($workForm, $productionPhotos, $productions): array {
                $snapshot = $this->productionDataBuilder->build($production->orderItemPrint, $workForm, $production);
                $photos = $this->photosForProduction($productionPhotos, $production, $productions->count());
                $baseline = (array) data_get($snapshot, 'subcontract_tracking.send_baseline', []);
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
                    'production_type' => data_get($snapshot, 'production_type'),
                    'production_type_label' => data_get($snapshot, 'production_type_label', '-'),
                    'operator_name' => $production->assignedUser?->name,
                    'production_unit_name' => data_get($snapshot, 'production_unit_name'),
                    'production_company_name' => data_get($snapshot, 'production_company_name'),
                    'planned_quantity' => (float) data_get($snapshot, 'planned_quantity', 0),
                    'completed_quantity' => (float) data_get($snapshot, 'completed_quantity', 0),
                    'remaining_quantity' => (float) data_get($snapshot, 'remaining_quantity', 0),
                    'sent_quantity' => $sentQuantity !== null ? (float) $sentQuantity : null,
                    'received_from_subcontractor_quantity' => $receivedQuantity,
                    'remaining_from_subcontractor_quantity' => $sentQuantity !== null ? max(round((float) $sentQuantity - (float) ($receivedQuantity ?? 0), 4), 0.0) : null,
                    'prior_internal_completed_quantity' => data_get($baseline, 'completed_quantity_before_send'),
                    'legacy_subcontract_baseline_missing' => $this->isOutsourced($production) && $sentQuantity === null,
                    'production_status' => data_get($snapshot, 'production_status'),
                    'production_status_label' => data_get($snapshot, 'production_status_label', '-'),
                    'graphic_status_label' => data_get($snapshot, 'graphic_status_label', '-'),
                    'procurement_status_label' => data_get($snapshot, 'procurement_status_label', '-'),
                    'qc_status_label' => $this->qualityControlLabel($snapshot, $production),
                    'qc_required' => data_get($snapshot, 'qc_status') !== 'gerekli_degil',
                    'public_status_label' => data_get($snapshot, 'public_status_label', '-'),
                    'final_graphic' => data_get($snapshot, 'final_graphic'),
                    'photo_count' => $photos->count(),
                    'photos' => $photos->take(3)->values()->all(),
                    'is_outsourced' => $this->isOutsourced($production),
                    'process_steps' => $this->processSteps($production, $snapshot),
                ];
            })
            ->values();
    }

    private function photosForProduction(Collection $productionPhotos, OrderItemPrintProduction $production, int $productionCount): Collection
    {
        return $productionPhotos
            ->filter(function (array $photo) use ($production, $productionCount): bool {
                $printId = (int) ($photo['order_item_print_id'] ?? 0);

                return $printId === (int) $production->order_item_print_id
                    || ($printId === 0 && $productionCount === 1);
            })
            ->values();
    }

    private function qualityControlLabel(array $snapshot, ?OrderItemPrintProduction $production = null): string
    {
        $status = (string) data_get($snapshot, 'qc_status', 'gerekli_degil');
        $productionStatus = (string) data_get($snapshot, 'production_status', $production?->production_status);

        if ($status === 'gerekli_degil' || ($status === OrderItemPrintProduction::QC_WAITING && $productionStatus !== OrderItemPrintProduction::STATUS_QUALITY_CONTROL)) {
            return 'Kalite Kontrol Gerekli Değil';
        }

        return (string) data_get($snapshot, 'qc_status_label', 'Bekliyor');
    }

    private function processSteps(OrderItemPrintProduction $production, array $snapshot): array
    {
        $steps = [
            'Grafik: ' . data_get($snapshot, 'graphic_status_label', '-'),
            'Tedarik: ' . data_get($snapshot, 'procurement_status_label', '-'),
        ];

        if ($this->isOutsourced($production)) {
            $steps[] = filled($production->production_company_id) ? 'Fason Atama: Tamamlandı' : 'Fason Atama: Bekliyor';
            $steps[] = 'Fason: ' . data_get($snapshot, 'production_status_label', '-');
        } else {
            $steps[] = 'İç Baskı: ' . data_get($snapshot, 'production_status_label', '-');
        }

        if (data_get($snapshot, 'qc_status') !== 'gerekli_degil') {
            $steps[] = 'QC: ' . $this->qualityControlLabel($snapshot);
        }

        $steps[] = ((string) data_get($snapshot, 'production_status') === OrderItemPrintProduction::STATUS_COMPLETED)
            ? 'Teslimata Hazır'
            : 'Teslimata Hazır Değil';

        return $steps;
    }

    private function isOutsourced(OrderItemPrintProduction $production): bool
    {
        return in_array($production->production_type, [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true);
    }
    private function mapWorkflowHistory(OrderItemWorkForm $workForm): array
    {
        if ($workForm->activityLogs->isEmpty()) {
            return [[
                'at' => optional($workForm->created_at)->format('d.m.Y H:i'),
                'label' => 'İş Formu oluşturuldu',
                'visibility' => 'İç Kayıt',
            ]];
        }

        return $workForm->activityLogs
            ->sortByDesc('created_at')
            ->values()
            ->map(fn ($log) => [
                'at' => optional($log->created_at)->format('d.m.Y H:i'),
                'label' => $this->humanizeActionType((string) $log->action_type),
                'visibility' => $log->visibility === 'customer_visible' ? 'Müşteriye Açık' : 'İç Kayıt',
                'note' => $log->note,
            ])
            ->all();
    }

    private function humanizeActionType(string $actionType): string
    {
        return $this->activityLabelResolver->sentence($actionType);
    }

    private function resolvePreviewUrl(OrderItemWorkFormAttachment $attachment): ?string
    {
        if (!$attachment->file_path) {
            return null;
        }

        $disk = $attachment->disk ?: config('filesystems.default');

        try {
            return Storage::disk($disk)->url($attachment->file_path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildProcurementLabel(array $productSnapshot): string
    {
        $labels = array_values(array_filter((array) data_get($productSnapshot, 'warning_labels', [])));

        if ($labels !== []) {
            return implode(' / ', array_slice($labels, 0, 2));
        }

        return filled(data_get($productSnapshot, 'supplier_name')) ? 'Planlandı' : 'Hazır';
    }

    private function normalizeStatusLabel(mixed $value, string $fallback): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return $fallback;
        }

        if ($text === 'gerekli_degil') {
            return 'Gerekli Değil';
        }

        return ucfirst(str_replace('_', ' ', $text));
    }

    private function resolvePublicProductionStatusLabel(array $productionSnapshot): string
    {
        $label = trim((string) data_get($productionSnapshot, 'public_status_label', ''));

        if ($label !== '') {
            return $label;
        }

        return data_get($productionSnapshot, 'status') === 'gerekli_degil'
            ? 'Üretim gerekli değil'
            : 'Üretim bekliyor';
    }

    private function mapWorkFolder($folder): ?array
    {
        if (!$folder) {
            return null;
        }

        return [
            'display_path' => $folder->display_path,
            'status' => $folder->status,
            'status_label' => $folder->safeStatusLabel(),
            'has_error' => $folder->isFailed(),
        ];
    }
}

