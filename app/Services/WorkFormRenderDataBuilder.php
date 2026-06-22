<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

class WorkFormRenderDataBuilder
{
    public function __construct(
        protected WorkFormQrCodeService $qrCodeService,
        protected TenantCompanyProfileService $tenantCompanyProfileService
    ) {
    }

    public function build(OrderItemWorkForm $workForm): array
    {
        $workForm->loadMissing(['tenant', 'attachments', 'activityLogs.attachment', 'systemWorkFolder']);

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
            'printSnapshot' => $printSnapshot,
            'procurementSnapshot' => $procurementSnapshot,
            'graphicSnapshot' => $graphicSnapshot,
            'productionSnapshot' => $productionSnapshot,
            'deliverySnapshot' => $deliverySnapshot,
            'publicProductionStatusLabel' => $this->resolvePublicProductionStatusLabel($productionSnapshot),
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
            ])
            ->values();
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
        return match ($actionType) {
            'work_form_created' => 'İş Formu oluşturuldu',
            'graphic_visual_added' => 'Grafik görseli eklendi',
            'customer_approval_added' => 'Müşteri onay dosyası eklendi',
            'procurement_needed' => 'Tedarik kaydı oluşturuldu',
            'procurement_request_created' => 'Tedarik talebi açıldı',
            'supplier_ordered' => 'Tedarikçiye sipariş verildi',
            'procurement_partially_received' => 'Tedarik kalemi kısmi geldi',
            'procurement_fully_received' => 'Tedarik kalemi tamamen geldi',
            'procurement_cancelled' => 'Tedarik kaydı iptal edildi',
            'customer_supplied_product_waiting' => 'Müşteri ürünü bekleniyor',
            'customer_supplied_product_received' => 'Müşteri ürünü geldi',
            'procurement_not_required' => 'Tedarik gerekmiyor',
            'production_operation_created' => 'Üretim operasyonu oluşturuldu',
            'production_assigned_internal' => 'İş iç üretime atandı',
            'production_assigned_external' => 'İş dış üretime atandı',
            'production_started' => 'Üretim başlatıldı',
            'production_sent_to_subcontractor' => 'İş fason firmaya gönderildi',
            'production_returned_from_subcontractor' => 'İş fason firmadan döndü',
            'production_qc_started' => 'Kalite kontrol başlatıldı',
            'production_qc_passed' => 'Kalite kontrol uygun bulundu',
            'production_qc_failed' => 'Kalite kontrolde sorun tespit edildi',
            'production_completed' => 'Üretim tamamlandı',
            'production_issue_reported' => 'Üretim sorunu bildirildi',
            'production_cancelled' => 'Üretim operasyonu iptal edildi',
            'production_photo_added' => 'Üretim fotoğrafı eklendi',
            'delivery_record_created' => 'Teslimat kaydı oluşturuldu',
            'delivery_preparing' => 'Teslimat hazırlığı başlatıldı',
            'delivery_ready' => 'Teslimata hazır olarak işaretlendi',
            'delivery_shipped' => 'Kalem kargoya verildi',
            'courier_out_for_delivery' => 'Kurye teslimata çıktı',
            'delivery_partially_completed' => 'Kalem kısmi teslim edildi',
            'delivery_completed' => 'Kalem teslim edildi',
            'delivery_issue_reported' => 'Teslimat sorunu bildirildi',
            'delivery_cancelled' => 'Teslimat kaydı iptal edildi',
            'delivery_details_updated' => 'Teslimat bilgileri güncellendi',
            'delivery_photo_added' => 'Teslimat fotoğrafı eklendi',
            'delivery_document_added' => 'Teslimat belgesi eklendi',
            'attachment_added' => 'Dosya eklendi',
            default => ucfirst(str_replace('_', ' ', $actionType)),
        };
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
