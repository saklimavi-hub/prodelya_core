<?php

namespace App\Services;

use App\Models\GraphicApprovalRequest;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\OrderItemWorkFormAttachment;
use App\Services\ProcessDepth\TenantProcessDepthPolicy;
use App\Services\ProcessDepth\TenantProcessDepthResolver;
use App\Support\ProcessDepth\ProcessDepth;
use App\Support\WorkFormActivityLabelResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class GraphicModuleDataBuilder
{
    public function __construct(
        protected WorkFormQrCodeService $qrCodeService,
        protected WorkFormActivityLabelResolver $activityLabelResolver,
        protected TenantProcessDepthResolver $processDepthResolver,
        protected TenantProcessDepthPolicy $processDepthPolicy,
    ) {
    }

    public function buildIndex(Collection $workForms, array $filters = []): array
    {
        $workForms->loadMissing([
            'systemWorkFolder',
            'attachments',
            'printGraphics.latestAttachment',
            'printGraphics.latestApprovalRequest',
            'printGraphics.orderItemPrint',
        ]);

        $rows = $workForms
            ->map(fn (OrderItemWorkForm $workForm) => $this->mapIndexRow($workForm))
            ->values();

        $operations = $rows
            ->flatMap(fn (array $row) => $row['print_operations'])
            ->values();

        return [
            'workForms' => $workForms,
            'filters' => [
                'q' => trim((string) ($filters['q'] ?? '')),
                'status' => trim((string) ($filters['status'] ?? '')),
                'approval_status' => trim((string) ($filters['approval_status'] ?? '')),
                'customer_visible_visual' => trim((string) ($filters['customer_visible_visual'] ?? '')),
            ],
            'rows' => $rows,
            'summary' => [
                'waiting' => $operations->where('status_key', OrderItemPrintGraphic::STATUS_WAITING_VISUAL)->count(),
                'needs_visual' => $operations->filter(fn (array $operation) => !$operation['has_latest_attachment'])->count(),
                'approval_waiting' => $operations->where('status_key', OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING)->count(),
                'revision' => $operations->where('status_key', OrderItemPrintGraphic::STATUS_REVISION_REQUESTED)->count(),
                'ready' => $operations->where('status_key', OrderItemPrintGraphic::STATUS_PRODUCTION_READY)->count(),
            ],
        ];
    }

    public function buildShow(
        OrderItemWorkForm $workForm,
        bool $customerApprovalEnabled = false,
        ?int $selectedGraphicId = null,
        ?string $selectedStep = null
    ): array
    {
        $relations = [
            'tenant',
            'attachments.uploader',
            'activityLogs.attachment',
            'systemWorkFolder',
            'printGraphics.latestAttachment',
            'printGraphics.attachments',
            'printGraphics.orderItemPrint',
        ];

        if (Schema::hasTable('graphic_approval_requests')) {
            $relations[] = 'printGraphics.latestApprovalRequest.attachment';
        }

        $workForm->loadMissing($relations);

        $orderSnapshot = $workForm->order_snapshot ?? [];
        $customerSnapshot = $workForm->customer_snapshot ?? [];
        $productSnapshot = $workForm->product_snapshot ?? [];
        $graphicSnapshot = $workForm->graphic_snapshot ?? [];
        $operations = $workForm->printGraphics
            ->sortBy(fn (OrderItemPrintGraphic $graphic) => $this->sortSequenceCode($graphic->sequence_code))
            ->values();

        $operationCards = $operations
            ->map(fn (OrderItemPrintGraphic $graphic) => $this->mapOperationCard($workForm, $graphic))
            ->values();

        $selectedOperation = $selectedGraphicId
            ? $operations->first(fn (OrderItemPrintGraphic $graphic) => $graphic->id === $selectedGraphicId)
            : null;

        $selectedOperation ??= $operations
            ->first(fn (OrderItemPrintGraphic $graphic) => !in_array($graphic->status, [
                OrderItemPrintGraphic::STATUS_APPROVED,
                OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
            ], true))
            ?? $operations->first();

        $selectedOperationCard = $selectedOperation ? $this->mapOperationCard($workForm, $selectedOperation) : null;
        $activeStepKey = $this->resolveActiveStepKey($selectedOperation, $selectedStep, $customerApprovalEnabled);
        $actionStepTabs = $this->buildActionStepTabs($activeStepKey, $customerApprovalEnabled);

        $workflowHistory = $this->mapWorkflowHistory($workForm);
        $processDepth = $this->buildProcessDepthPayload(
            $workForm,
            $selectedOperation,
            $operationCards,
            $workflowHistory,
            $customerApprovalEnabled
        );

        return [
            'workForm' => $workForm,
            'trackingUrl' => $this->qrCodeService->trackingUrl($workForm),
            'orderSnapshot' => $orderSnapshot,
            'customerSnapshot' => $customerSnapshot,
            'productSnapshot' => $productSnapshot,
            'printSnapshot' => collect($workForm->print_snapshot ?? [])->values()->all(),
            'graphicSnapshot' => $graphicSnapshot,
            'generalGraphicStatusKey' => $this->dominantStatusKey($operations),
            'generalGraphicStatusLabel' => $this->aggregateStatusLabel($operations),
            'approvalStatusLabel' => $this->aggregateApprovalLabel($operations),
            'printOperationCards' => $operationCards,
            'productPreview' => $this->mapProductPreview($productSnapshot),
            'operationTabs' => $operationCards->map(fn (array $card) => [
                'id' => $card['id'],
                'sequence_code' => $card['sequence_code'],
                'title' => $card['title'],
                'status_label' => $card['status_label'],
                'status_badge' => $card['status_badge'],
                'is_active' => $selectedOperation?->id === $card['id'],
            ])->all(),
            'selectedOperationCard' => $selectedOperationCard,
            'activeActionStep' => $activeStepKey,
            'actionStepTabs' => $actionStepTabs,
            'systemWorkFolder' => $this->mapWorkFolder($workForm->systemWorkFolder),
            'workflowHistory' => $workflowHistory,
            'operationSummaryLines' => $this->aggregateStatusParts($operations),
            'nextActionLabel' => $this->nextActionLabel($operations),
            'customerApprovalEnabled' => $customerApprovalEnabled,
            'processDepth' => $processDepth,
        ];
    }

    private function buildProcessDepthPayload(
        OrderItemWorkForm $workForm,
        ?OrderItemPrintGraphic $selectedOperation,
        Collection $operationCards,
        array $workflowHistory,
        bool $customerApprovalEnabled
    ): array {
        $resolved = $workForm->tenant
            ? $this->processDepthResolver->resolve($workForm->tenant)
            : [
                'key' => ProcessDepth::default(),
                'label' => ProcessDepth::label(ProcessDepth::default()),
                'source' => 'system_default',
                'source_label' => ProcessDepth::sourceLabel('system_default'),
                'is_overridden' => false,
            ];

        $depthKey = (string) ($resolved['key'] ?? ProcessDepth::default());
        $capabilities = $this->processDepthPolicy->forDepth($depthKey);
        $selectedOperationCard = $selectedOperation
            ? $operationCards->first(fn (array $card) => $card['id'] === $selectedOperation->id)
            : null;
        $primaryAction = $this->buildPrimaryAction($workForm, $selectedOperationCard, $customerApprovalEnabled);

        return array_merge($resolved, [
            'presentation' => [
                'density' => (string) ($capabilities['operation_card_density'] ?? 'standard'),
                'show_operation_overview' => $depthKey !== ProcessDepth::FAST,
                'show_visibility_details' => $depthKey !== ProcessDepth::FAST,
                'show_customer_approval_details' => $depthKey !== ProcessDepth::FAST,
                'show_revision_details' => $depthKey !== ProcessDepth::FAST,
                'show_readiness_details' => (bool) ($capabilities['show_extended_readiness_details'] ?? false),
                'show_attachment_list' => (bool) ($capabilities['show_evidence_sections'] ?? false),
                'show_full_activity_history' => (bool) ($capabilities['show_advanced_activity_timeline'] ?? false),
                'show_operation_status_sidebar' => $depthKey !== ProcessDepth::FAST,
                'show_recent_activity_sidebar' => $depthKey === ProcessDepth::CONTROLLED,
                'show_compact_history_only' => $depthKey === ProcessDepth::FAST,
                'history_limit' => match ($depthKey) {
                    ProcessDepth::FAST => 1,
                    ProcessDepth::STANDARD => 3,
                    default => count($workflowHistory),
                },
                'branch_class' => match ($depthKey) {
                    ProcessDepth::FAST => 'pd-graphic-depth-fast',
                    ProcessDepth::CONTROLLED => 'pd-graphic-depth-controlled',
                    default => 'pd-graphic-depth-standard',
                },
            ],
            'primary_action' => $primaryAction,
        ]);
    }

    private function buildPrimaryAction(
        OrderItemWorkForm $workForm,
        ?array $selectedOperationCard,
        bool $customerApprovalEnabled
    ): array {
        if ($selectedOperationCard === null) {
            return [
                'label' => 'Grafik Operasyonunu Aç',
                'url' => route('admin.graphics.show', $workForm),
                'step' => 'summary',
            ];
        }

        $step = 'summary';
        $label = 'Operasyon Özeti';

        if (($selectedOperationCard['status_key'] ?? null) === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED) {
            $step = 'upload';
            $label = 'Revizeyi Yükle';
        } elseif (($selectedOperationCard['status_key'] ?? null) === OrderItemPrintGraphic::STATUS_WAITING_VISUAL) {
            $step = 'upload';
            $label = empty($selectedOperationCard['attachment']) ? 'Görsel Yükle' : 'Görseli Güncelle';
        } elseif (
            $customerApprovalEnabled
            && ($selectedOperationCard['customer_approval']['send_url'] ?? null)
            && ($selectedOperationCard['customer_approval_label'] ?? null) !== 'Onaylandı'
        ) {
            $step = 'approval';
            $label = 'Müşteri Onayına Gönder';
        } elseif (($selectedOperationCard['can_mark_production_ready'] ?? false) === true) {
            $step = 'ready';
            $label = 'Üretime Hazır İşaretle';
        } elseif (!empty($selectedOperationCard['attachment'])) {
            $step = 'upload';
            $label = 'Görseli Güncelle';
        }

        return [
            'label' => $label,
            'step' => $step,
            'url' => route('admin.graphics.show', [
                'workForm' => $workForm,
                'operation' => $selectedOperationCard['id'],
                'step' => $step,
            ]),
        ];
    }

    public function statusLabel(?string $value, string $fallback = 'Görsel Bekliyor'): string
    {
        return match ($this->normalizeKey($value)) {
            OrderItemPrintGraphic::STATUS_WAITING_VISUAL => 'Görsel Bekliyor',
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => 'Görsel Eklendi',
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'Onay Bekliyor',
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'Revize İstendi',
            OrderItemPrintGraphic::STATUS_APPROVED => 'Onaylandı',
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY => 'Üretime Hazır',
            OrderItemPrintGraphic::STATUS_NOT_REQUIRED => 'Gerekli Değil',
            'bekliyor' => 'Bekliyor',
            'gorsel_eklendi' => 'Görsel Eklendi',
            'musteri_onayi_bekliyor' => 'Müşteri Onayı Bekliyor',
            'onaylandi' => 'Onaylandı',
            'uretime_hazir' => 'Üretime Hazır',
            default => $fallback,
        };
    }

    public function approvalStatusLabel(?string $value): string
    {
        return match ($this->normalizeKey($value)) {
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_NOT_REQUIRED, 'onay_gerekmiyor', 'gerekli_degil' => 'Onay Gerekmiyor',
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING, 'onay_bekliyor', 'bekliyor' => 'Onay Bekliyor',
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED, 'onaylandi' => 'Onaylandı',
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED, 'revize_istendi' => 'Revize İstendi',
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_REJECTED, 'reddedildi', 'rejected' => 'Reddedildi',
            '' => 'Gönderilmedi',
            default => 'Gönderilmedi',
        };
    }

    private function mapIndexRow(OrderItemWorkForm $workForm): array
    {
        $orderSnapshot = $workForm->order_snapshot ?? [];
        $customerSnapshot = $workForm->customer_snapshot ?? [];
        $productSnapshot = $workForm->product_snapshot ?? [];
        $operations = $workForm->printGraphics
            ->sortBy(fn (OrderItemPrintGraphic $graphic) => $this->sortSequenceCode($graphic->sequence_code))
            ->values();

        $primaryAttachment = $operations
            ->map(fn (OrderItemPrintGraphic $graphic) => $graphic->latestAttachment)
            ->filter()
            ->sortByDesc('id')
            ->first();

        $dominantStatus = $this->dominantStatusKey($operations);

        return [
            'work_form_id' => $workForm->id,
            'order_number' => data_get($orderSnapshot, 'document_number', '-'),
            'work_form_number' => $workForm->work_form_number,
            'customer_name' => data_get($customerSnapshot, 'company_name', '-'),
            'product_name' => data_get($productSnapshot, 'product_name', '-'),
            'product_code' => data_get($productSnapshot, 'product_code', '-'),
            'quantity' => $this->formatQuantity(data_get($productSnapshot, 'quantity'), data_get($productSnapshot, 'unit')),
            'image_url' => data_get($productSnapshot, 'image_url'),
            'image_thumbnail_url' => data_get($productSnapshot, 'image_url'),
            'image_original_url' => data_get($productSnapshot, 'image_url'),
            'print_lines' => $operations->map(fn (OrderItemPrintGraphic $graphic) => $this->operationLine($graphic))->all(),
            'print_operations' => $operations->map(fn (OrderItemPrintGraphic $graphic) => [
                'id' => $graphic->id,
                'sequence_code' => $graphic->sequence_code ?: '-',
                'summary_line' => $this->operationLine($graphic),
                'status_key' => $graphic->status,
                'status_label' => $graphic->safeStatusLabel(),
                'has_latest_attachment' => (bool) $graphic->latestAttachment,
            ])->all(),
            'graphic_status_key' => $dominantStatus,
            'graphic_status_label' => $this->aggregateStatusLabel($operations),
            'approval_status_key' => $this->dominantApprovalKey($operations),
            'approval_status_label' => $this->aggregateApprovalLabel($operations),
            'approval_waiting_count' => $operations->where('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING)->count(),
            'approval_revision_count' => $operations->where('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED)->count(),
            'approval_approved_count' => $operations->where('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED)->count(),
            'latest_approval_sent_at' => $operations
                ->map(fn (OrderItemPrintGraphic $graphic) => optional($graphic->latestApprovalRequest?->created_at)->format('d.m.Y H:i'))
                ->filter()
                ->first(),
            'latest_customer_response_at' => $operations
                ->map(fn (OrderItemPrintGraphic $graphic) => optional($graphic->latestApprovalRequest?->responded_at)->format('d.m.Y H:i'))
                ->filter()
                ->first(),
            'production_ready_state_label' => $this->productionReadySummary($operations),
            'last_visual_name' => $primaryAttachment?->file_name,
            'last_visual_url' => $primaryAttachment ? $this->resolvePreviewUrl($primaryAttachment) : null,
            'last_visual_thumbnail_url' => $primaryAttachment ? $this->resolvePreviewUrl($primaryAttachment) : null,
            'last_visual_original_url' => $primaryAttachment ? $this->resolvePreviewUrl($primaryAttachment) : null,
            'has_graphic_visual' => (bool) $primaryAttachment,
            'has_customer_visible_visual' => $operations->contains(
                fn (OrderItemPrintGraphic $graphic) => $graphic->latestAttachment && $graphic->latestAttachment->isCustomerVisible()
            ),
            'next_action' => $this->nextActionLabel($operations),
            'detail_url' => route('admin.graphics.show', $workForm),
            'work_form_url' => route('admin.work-forms.show', $workForm),
            'public_tracking_url' => $this->qrCodeService->trackingUrl($workForm),
            'work_folder' => $this->mapWorkFolder($workForm->systemWorkFolder),
        ];
    }

    private function mapOperationCard(OrderItemWorkForm $workForm, OrderItemPrintGraphic $graphic): array
    {
        $attachment = $graphic->latestAttachment;
        $print = $graphic->orderItemPrint;

        return [
            'id' => $graphic->id,
            'sequence_code' => $graphic->sequence_code ?: '-',
            'title' => $this->operationLine($graphic),
            'status_key' => $graphic->status,
            'status_label' => $graphic->safeStatusLabel(),
            'status_badge' => $this->statusBadgeClass($graphic->status),
            'customer_approval_key' => $graphic->customer_approval_status,
            'customer_approval_label' => $graphic->safeCustomerApprovalLabel(),
            'customer_approval_badge' => $this->approvalBadgeClass($graphic->customer_approval_status),
            'graphic_note' => $graphic->graphic_note,
            'customer_note' => $graphic->customer_note,
            'visibility_default' => $graphic->visibility_default ?: 'internal',
            'can_mark_production_ready' => $graphic->canMarkProductionReady(),
            'upload_url' => route('admin.work-forms.attachments.store', $workForm),
            'status_url' => route('admin.graphics.operations.update-status', [
                'workForm' => $workForm,
                'graphic' => $graphic,
            ]),
            'customer_approval' => $this->mapCustomerApprovalCard($graphic),
            'production_ready_guidance' => $this->productionReadyGuidance($graphic),
            'attachment' => $attachment ? [
                'file_name' => $attachment->file_name ?: basename((string) $attachment->file_path),
                'thumbnail_url' => $attachment->isImage() ? $this->resolvePreviewUrl($attachment) : null,
                'preview_url' => $attachment->isImage() ? $this->resolvePreviewUrl($attachment) : null,
                'original_url' => $this->resolvePreviewUrl($attachment),
                'open_url' => $this->resolvePreviewUrl($attachment),
                'is_image' => $attachment->isImage(),
                'kind_label' => $attachment->isImage() ? 'Görsel dosyası' : strtoupper(pathinfo((string) ($attachment->file_name ?: $attachment->file_path), PATHINFO_EXTENSION) ?: 'DOSYA') . ' dosyası',
                'visibility' => $attachment->visibility,
                'visibility_label' => $attachment->isCustomerVisible() ? 'Müşteriye Açık' : 'İç Kayıt',
                'created_at' => optional($attachment->created_at)->format('d.m.Y H:i'),
            ] : null,
            'attachments' => $graphic->attachments
                ->sortByDesc('id')
                ->values()
                ->map(fn (OrderItemWorkFormAttachment $item) => [
                    'id' => $item->id,
                    'file_name' => $item->file_name ?: basename((string) $item->file_path),
                    'kind_label' => $item->attachment_type === 'customer_approval'
                        ? 'Müşteri Onay Dosyası'
                        : ($item->isImage()
                            ? 'Grafik Görseli'
                            : strtoupper(pathinfo((string) ($item->file_name ?: $item->file_path), PATHINFO_EXTENSION) ?: 'DOSYA') . ' dosyası'),
                    'visibility' => $item->visibility,
                    'visibility_label' => $item->isCustomerVisible() ? 'Müşteriye Açık' : 'İç Kayıt',
                    'uploaded_at' => optional($item->created_at)->format('d.m.Y H:i'),
                    'open_url' => $this->resolvePreviewUrl($item),
                ])
                ->all(),
            'print_meta' => [
                'print_type' => $print?->print_type ?: '-',
                'print_option' => $print?->print_option ?: '-',
                'production_type' => $print?->production_type ?: '-',
                'print_quantity' => $this->formatQuantity($print?->print_quantity, 'Adet'),
            ],
        ];
    }

    private function mapCustomerApprovalCard(OrderItemPrintGraphic $graphic): array
    {
        if (!Schema::hasTable('graphic_approval_requests')) {
            return [
                'send_url' => route('admin.graphics.customer-approval.send', $graphic),
                'eligible_attachments' => [],
                'latest_request' => null,
            ];
        }

        $latestRequest = $graphic->latestApprovalRequest;
        $eligibleAttachments = $graphic->attachments()
            ->where('tenant_account_id', $graphic->tenant_account_id)
            ->where('order_id', $graphic->order_id)
            ->where('order_item_id', $graphic->order_item_id)
            ->where('work_form_id', $graphic->order_item_work_form_id)
            ->where('order_item_print_id', $graphic->order_item_print_id)
            ->where('order_item_print_graphic_id', $graphic->id)
            ->where('visibility', 'customer_visible')
            ->whereIn('attachment_type', ['graphic_visual', 'customer_approval'])
            ->orderByDesc('id')
            ->get();

        return [
            'send_url' => route('admin.graphics.customer-approval.send', $graphic),
            'eligible_attachments' => $eligibleAttachments
                ->map(fn (OrderItemWorkFormAttachment $attachment) => [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name ?: 'Dosya',
                    'attachment_type' => $attachment->attachment_type,
                    'attachment_type_label' => $attachment->attachment_type === 'customer_approval'
                        ? 'Müşteri Onay Dosyası'
                        : 'Grafik Görseli',
                    'uploaded_at' => optional($attachment->created_at)->format('d.m.Y H:i'),
                ])
                ->all(),
            'latest_request' => $latestRequest ? [
                'status_label' => $latestRequest->safeStatusLabel(),
                'status_key' => $latestRequest->status,
                'created_at' => optional($latestRequest->created_at)->format('d.m.Y H:i'),
                'viewed_at' => optional($latestRequest->viewed_at)->format('d.m.Y H:i'),
                'responded_at' => optional($latestRequest->responded_at)->format('d.m.Y H:i'),
                'expires_at' => optional($latestRequest->expires_at)->format('d.m.Y H:i'),
                'customer_note' => $latestRequest->customer_note,
                'attachment_file_name' => $latestRequest->attachment?->file_name ?: 'Dosya',
                'open_url' => $latestRequest->publicUrl()
                    ? route('admin.graphics.customer-approval.open', $latestRequest)
                    : null,
                'status_badge' => $this->approvalRequestBadgeClass($latestRequest->status),
            ] : null,
            'send_action_label' => $latestRequest ? 'Tekrar Gönder' : 'Müşteri Onayına Gönder',
        ];
    }

    private function mapWorkflowHistory(OrderItemWorkForm $workForm): array
    {
        $priority = [
            'graphic_visual_added' => 1,
            'customer_approval_added' => 2,
            'status_updated' => 3,
            'work_form_created' => 4,
        ];

        return $workForm->activityLogs
            ->sortByDesc('created_at')
            ->sortBy(fn (OrderItemWorkFormActivityLog $log) => $priority[$log->action_type] ?? 20)
            ->values()
            ->map(fn (OrderItemWorkFormActivityLog $log) => [
                'at' => optional($log->created_at)->format('d.m.Y H:i'),
                'label' => $this->historyLabel($log),
                'visibility' => $log->visibility === 'customer_visible' ? 'Müşteriye Açık' : 'İç Kayıt',
                'note' => $log->note,
            ])
            ->all();
    }

    private function historyLabel(OrderItemWorkFormActivityLog $log): string
    {
        return match ($log->action_type) {
            'status_updated' => $log->new_status
                ? 'Durum güncellendi: ' . $this->statusLabel($log->new_status, ucfirst(str_replace('_', ' ', (string) $log->new_status)))
                : 'Durum güncellendi',
            default => $this->activityLabelResolver->sentence((string) $log->action_type),
        };
    }

    private function aggregateStatusLabel(Collection $graphics): string
    {
        $parts = $this->aggregateStatusParts($graphics);

        return $parts === [] ? 'Grafik operasyonu yok' : implode(' · ', $parts);
    }

    private function aggregateStatusParts(Collection $graphics): array
    {
        $counts = [
            OrderItemPrintGraphic::STATUS_WAITING_VISUAL => $graphics->where('status', OrderItemPrintGraphic::STATUS_WAITING_VISUAL)->count(),
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => $graphics->where('status', OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED)->count(),
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => $graphics->where('status', OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING)->count(),
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => $graphics->where('status', OrderItemPrintGraphic::STATUS_REVISION_REQUESTED)->count(),
            OrderItemPrintGraphic::STATUS_APPROVED => $graphics->where('status', OrderItemPrintGraphic::STATUS_APPROVED)->count(),
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY => $graphics->where('status', OrderItemPrintGraphic::STATUS_PRODUCTION_READY)->count(),
        ];

        $parts = [];

        foreach ($counts as $status => $count) {
            if ($count < 1) {
                continue;
            }

            $parts[] = match ($status) {
                OrderItemPrintGraphic::STATUS_WAITING_VISUAL => "{$count} görsel bekliyor",
                OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => "{$count} görsel eklendi",
                OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => "{$count} onay bekliyor",
                OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => "{$count} revize istendi",
                OrderItemPrintGraphic::STATUS_APPROVED => "{$count} onaylandı",
                OrderItemPrintGraphic::STATUS_PRODUCTION_READY => "{$count} üretime hazır",
                default => "{$count} işlem",
            };
        }

        return $parts;
    }

    private function aggregateApprovalLabel(Collection $graphics): string
    {
        $counts = [
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING => $graphics->where('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING)->count(),
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED => $graphics->where('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED)->count(),
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED => $graphics->where('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED)->count(),
        ];

        $parts = [];

        foreach ($counts as $status => $count) {
            if ($count < 1) {
                continue;
            }

            $parts[] = match ($status) {
                OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING => "{$count} onay bekliyor",
                OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED => "{$count} revizede",
                OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED => "{$count} onaylandı",
                default => "{$count} kayıt",
            };
        }

        return $parts === [] ? 'Onay gerekmiyor' : implode(' · ', $parts);
    }

    private function dominantStatusKey(Collection $graphics): string
    {
        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_REVISION_REQUESTED)) {
            return OrderItemPrintGraphic::STATUS_REVISION_REQUESTED;
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_WAITING_VISUAL)) {
            return OrderItemPrintGraphic::STATUS_WAITING_VISUAL;
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING)) {
            return OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING;
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED)) {
            return OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED;
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_APPROVED)) {
            return OrderItemPrintGraphic::STATUS_APPROVED;
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_PRODUCTION_READY)) {
            return OrderItemPrintGraphic::STATUS_PRODUCTION_READY;
        }

        return OrderItemPrintGraphic::STATUS_NOT_REQUIRED;
    }

    private function dominantApprovalKey(Collection $graphics): string
    {
        if ($graphics->contains('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED)) {
            return OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED;
        }

        if ($graphics->contains('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING)) {
            return OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING;
        }

        if ($graphics->contains('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED)) {
            return OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED;
        }

        return OrderItemPrintGraphic::CUSTOMER_APPROVAL_NOT_REQUIRED;
    }

    private function nextActionLabel(Collection $graphics): string
    {
        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_REVISION_REQUESTED)) {
            return 'Revizeyi işle';
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_WAITING_VISUAL)) {
            return 'Görsel yükle';
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING)) {
            return 'Onay cevabını bekle';
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED)) {
            return 'Onay durumunu ilerlet';
        }

        if ($graphics->contains('status', OrderItemPrintGraphic::STATUS_APPROVED)) {
            return 'Üretime hazır işaretle';
        }

        if ($graphics->every(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_PRODUCTION_READY)) {
            return 'İş Formu PDF indir';
        }

        return 'Grafik operasyonunu incele';
    }

    private function productionReadyGuidance(OrderItemPrintGraphic $graphic): array
    {
        if ($graphic->customer_approval_status === OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED) {
            return [
                'label' => 'Revize istendi. Üretime hazır işaretlenemez.',
                'badge' => 'gg-badge-red',
            ];
        }

        if (
            $graphic->customer_approval_status === OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED
            && $graphic->status !== OrderItemPrintGraphic::STATUS_PRODUCTION_READY
        ) {
            return [
                'label' => 'Görsel müşteri tarafından onaylandı. Üretime göndermeden önce son kontrol yapılmalı.',
                'badge' => 'gg-badge-amber',
            ];
        }

        if ($graphic->status === OrderItemPrintGraphic::STATUS_PRODUCTION_READY) {
            return [
                'label' => 'Bu operasyon üretime hazır olarak işaretlendi.',
                'badge' => 'gg-badge-green',
            ];
        }

        return [
            'label' => 'Müşteri onayı ve üretime hazır kararı ayrı izlenir.',
            'badge' => 'gg-badge-gray',
        ];
    }

    private function productionReadySummary(Collection $graphics): string
    {
        if ($graphics->contains('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED)) {
            return 'Revize bekliyor';
        }

        if ($graphics->every(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_PRODUCTION_READY)) {
            return 'Tümü üretime hazır';
        }

        if ($graphics->contains('customer_approval_status', OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED)) {
            return 'Son kontrol bekliyor';
        }

        return 'Operasyon akışında';
    }

    private function operationLine(OrderItemPrintGraphic $graphic): string
    {
        $print = $graphic->orderItemPrint;

        return trim(implode(' / ', array_filter([
            $graphic->sequence_code,
            $print?->print_type,
            $print?->print_option,
            $this->formatQuantity($print?->print_quantity, 'adet'),
        ])));
    }

    private function resolvePreviewUrl(OrderItemWorkFormAttachment $attachment): ?string
    {
        if (!$attachment->file_path) {
            return null;
        }

        $disk = $attachment->disk ?: config('filesystems.default');

        try {
            if (!Storage::disk($disk)->exists($attachment->file_path)) {
                return null;
            }

            return route('admin.work-forms.attachments.preview', $attachment);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapProductPreview(array $productSnapshot): ?array
    {
        $imageUrl = data_get($productSnapshot, 'image_url');

        if (!$imageUrl) {
            return null;
        }

        return [
            'thumbnail_url' => $imageUrl,
            'preview_url' => $imageUrl,
            'original_url' => $imageUrl,
            'title' => data_get($productSnapshot, 'product_name', 'Ürün görseli'),
        ];
    }

    private function resolveActiveStepKey(?OrderItemPrintGraphic $graphic, ?string $selectedStep, bool $customerApprovalEnabled): string
    {
        $allowedSteps = ['upload', 'summary', 'approval', 'revision', 'ready'];

        if (in_array($selectedStep, $allowedSteps, true)) {
            return $selectedStep;
        }

        if (!$graphic) {
            return 'summary';
        }

        if (!$graphic->latestAttachment) {
            return 'upload';
        }

        if ($graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED
            || $graphic->customer_approval_status === OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED) {
            return 'revision';
        }

        if ($graphic->status === OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING
            || $graphic->customer_approval_status === OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING) {
            return 'approval';
        }

        if (
            $customerApprovalEnabled
            && $graphic->latestAttachment
            && $graphic->latestAttachment->isCustomerVisible()
            && $graphic->customer_approval_status !== OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED
        ) {
            return 'approval';
        }

        if ($graphic->status === OrderItemPrintGraphic::STATUS_APPROVED
            || $graphic->status === OrderItemPrintGraphic::STATUS_PRODUCTION_READY
            || $graphic->customer_approval_status === OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED) {
            return 'ready';
        }

        return 'summary';
    }

    private function buildActionStepTabs(string $activeStepKey, bool $customerApprovalEnabled): array
    {
        $definitions = [
            'upload' => ['label' => '1. Görsel Yükleme', 'short_label' => 'Görsel Yükleme', 'badge' => 'gg-badge-green'],
            'summary' => ['label' => '2. Operasyon Özeti', 'short_label' => 'Operasyon Özeti', 'badge' => 'gg-badge-blue'],
            'approval' => ['label' => $customerApprovalEnabled ? '3. Müşteri Onayı' : '3. Onay Durumu', 'short_label' => $customerApprovalEnabled ? 'Müşteri Onayı' : 'Onay Durumu', 'badge' => 'gg-badge-amber'],
            'revision' => ['label' => '4. Revize', 'short_label' => 'Revize', 'badge' => 'gg-badge-red'],
            'ready' => ['label' => '5. Üretime Hazır', 'short_label' => 'Üretime Hazır', 'badge' => 'gg-badge-gray'],
        ];

        return collect($definitions)
            ->map(fn (array $definition, string $key) => $definition + [
                'key' => $key,
                'is_active' => $key === $activeStepKey,
            ])
            ->values()
            ->all();
    }

    private function formatQuantity(mixed $quantity, ?string $unit = null): string
    {
        if ($quantity === null || $quantity === '') {
            return '-';
        }

        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    private function normalizeKey(?string $value): string
    {
        return trim(mb_strtolower((string) ($value ?? '')));
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

    private function statusBadgeClass(?string $status): string
    {
        return match ($status) {
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
            OrderItemPrintGraphic::STATUS_APPROVED => 'gg-badge-green',
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED,
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'gg-badge-blue',
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'gg-badge-red',
            OrderItemPrintGraphic::STATUS_NOT_REQUIRED => 'gg-badge-gray',
            default => 'gg-badge-amber',
        };
    }

    private function approvalBadgeClass(?string $status): string
    {
        return match ($status) {
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED => 'gg-badge-green',
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING => 'gg-badge-blue',
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED,
            OrderItemPrintGraphic::CUSTOMER_APPROVAL_REJECTED => 'gg-badge-red',
            default => 'gg-badge-gray',
        };
    }

    private function approvalRequestBadgeClass(?string $status): string
    {
        return match ($status) {
            GraphicApprovalRequest::STATUS_APPROVED => 'gg-badge-green',
            GraphicApprovalRequest::STATUS_WAITING,
            GraphicApprovalRequest::STATUS_VIEWED => 'gg-badge-blue',
            GraphicApprovalRequest::STATUS_REVISION_REQUESTED => 'gg-badge-red',
            GraphicApprovalRequest::STATUS_CANCELLED,
            GraphicApprovalRequest::STATUS_EXPIRED => 'gg-badge-gray',
            default => 'gg-badge-gray',
        };
    }

    private function sortSequenceCode(?string $sequenceCode): string
    {
        if (!$sequenceCode) {
            return '9999-z';
        }

        return (string) preg_replace_callback('/\d+/', fn (array $matches) => str_pad($matches[0], 4, '0', STR_PAD_LEFT), $sequenceCode);
    }
}






