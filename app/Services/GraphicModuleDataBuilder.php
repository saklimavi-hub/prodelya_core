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
use Illuminate\Pagination\LengthAwarePaginator;
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

        $normalizedFilters = $this->normalizeIndexFilters($filters);

        $allRows = $workForms
            ->flatMap(fn (OrderItemWorkForm $workForm) => $this->mapIndexRows($workForm))
            ->values();

        $baseRows = $this->applyIndexRowFilters($allRows, $normalizedFilters)
            ->values();

        $selectedQueue = $normalizedFilters['queue'] !== '' ? $normalizedFilters['queue'] : 'action_waiting';
        $groups = $this->buildIndexOrderGroups($baseRows);
        $queueGroups = $this->applyIndexGroupQueueFilter($groups, $selectedQueue)->values();
        $tabs = $this->buildIndexTabs($groups, $selectedQueue);
        $paginator = $this->paginateIndexGroups($queueGroups, $normalizedFilters['per_page']);
        $listedOperations = collect($paginator->items())->sum('total_operations');
        $listedGroups = count($paginator->items());
        $groupRangeStart = $paginator->total() > 0 ? (($paginator->currentPage() - 1) * $paginator->perPage()) + 1 : 0;
        $groupRangeEnd = $paginator->total() > 0 ? min($paginator->currentPage() * $paginator->perPage(), $paginator->total()) : 0;

        return [
            'filters' => array_merge($normalizedFilters, ['queue' => $selectedQueue]),
            'groupPaginator' => $paginator,
            'groups' => $paginator->items(),
            'tabs' => $tabs,
            'summary' => $this->buildIndexSummary($baseRows, $groups),
            'sideSummary' => [
                'listed_operations' => $listedOperations,
                'listed_groups' => $listedGroups,
                'waiting_visual' => $queueGroups->sum(fn (array $group) => $this->countGroupQueueRows($group, 'waiting_visual')),
                'revision_requested' => $queueGroups->sum(fn (array $group) => $this->countGroupQueueRows($group, 'revision_requested')),
                'production_ready' => $queueGroups->sum(fn (array $group) => $this->countGroupQueueRows($group, 'production_ready')),
                'completed_groups' => $groups->where('is_completed_group', true)->count(),
                'selected_queue_label' => collect($tabs)->firstWhere('active', true)['label'] ?? 'Aksiyon Bekleyenler',
                'range_start' => $groupRangeStart,
                'range_end' => $groupRangeEnd,
                'total_groups' => $paginator->total(),
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

    private function normalizeIndexFilters(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 10);

        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 10;
        }

        return [
            'q' => trim((string) ($filters['q'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'approval_status' => trim((string) ($filters['approval_status'] ?? '')),
            'customer_visible_visual' => trim((string) ($filters['customer_visible_visual'] ?? '')),
            'queue' => trim((string) ($filters['queue'] ?? '')),
            'per_page' => $perPage,
        ];
    }

    private function mapIndexRows(OrderItemWorkForm $workForm): array
    {
        $orderSnapshot = $workForm->order_snapshot ?? [];
        $customerSnapshot = $workForm->customer_snapshot ?? [];
        $productSnapshot = $workForm->product_snapshot ?? [];

        return $workForm->printGraphics
            ->filter(fn (OrderItemPrintGraphic $graphic) => $graphic->status !== OrderItemPrintGraphic::STATUS_NOT_REQUIRED)
            ->sortBy(fn (OrderItemPrintGraphic $graphic) => $this->sortSequenceCode($graphic->sequence_code))
            ->values()
            ->map(fn (OrderItemPrintGraphic $graphic) => $this->mapIndexRow($workForm, $graphic, $orderSnapshot, $customerSnapshot, $productSnapshot))
            ->all();
    }

    private function mapIndexRow(
        OrderItemWorkForm $workForm,
        OrderItemPrintGraphic $graphic,
        array $orderSnapshot,
        array $customerSnapshot,
        array $productSnapshot
    ): array {
        $print = $graphic->orderItemPrint;
        $attachment = $graphic->latestAttachment;
        $latestRequest = $graphic->latestApprovalRequest;
        $primaryAction = $this->buildIndexPrimaryAction($workForm, $graphic);
        $lastEvent = $this->buildIndexLastEvent($graphic, $attachment, $latestRequest);
        $statusKey = (string) $graphic->status;

        return [
            'graphic_id' => $graphic->id,
            'order_id' => $graphic->order_id,
            'order_item_id' => $graphic->order_item_id,
            'order_item_print_id' => $graphic->order_item_print_id,
            'work_form_id' => $workForm->id,
            'order_number' => data_get($orderSnapshot, 'document_number', '-'),
            'work_form_number' => $workForm->work_form_number,
            'customer_name' => data_get($customerSnapshot, 'company_name', '-'),
            'product_name' => data_get($productSnapshot, 'product_name', '-'),
            'product_code' => data_get($productSnapshot, 'product_code', '-'),
            'image_url' => data_get($productSnapshot, 'image_url'),
            'delivery_date_label' => $this->formatSnapshotDate(
                data_get($orderSnapshot, 'delivery_date')
                    ?: data_get($orderSnapshot, 'delivery_at')
                    ?: data_get($orderSnapshot, 'delivery_deadline')
            ),
            'sequence_code' => $graphic->sequence_code ?: '-',
            'print_type' => $print?->print_type ?: '-',
            'print_option' => $print?->print_option ?: '-',
            'print_quantity' => $this->formatQuantity($print?->print_quantity, 'adet'),
            'print_line' => $this->operationLine($graphic),
            'status_key' => $statusKey,
            'status_label' => $this->indexStatusLabel($graphic),
            'status_badge' => $this->indexStatusBadgeClass($statusKey),
            'status_hint' => $this->indexStatusHint($graphic),
            'approval_status_key' => (string) $graphic->customer_approval_status,
            'approval_status_label' => $this->approvalStatusLabel($graphic->customer_approval_status),
            'queue_key' => $this->indexQueueKey($graphic),
            'has_customer_visible_visual' => (bool) ($attachment && $attachment->isCustomerVisible()),
            'visual_summary_label' => $attachment ? '1 görsel' : 'Görsel yok',
            'visibility_label' => !$attachment
                ? 'Henüz görsel yüklenmedi'
                : ($attachment->isCustomerVisible() ? 'Müşteriye Açık' : 'Yalnız İç Kullanım'),
            'last_visual_name' => $attachment?->file_name,
            'last_visual_thumbnail_url' => $attachment ? $this->resolveAttachmentPreviewUrl($attachment) : null,
            'last_visual_original_url' => $attachment ? $this->resolveAttachmentOriginalUrl($attachment) : null,
            'last_event_label' => $lastEvent['label'],
            'last_event_at' => $lastEvent['at'],
            'next_action_label' => $primaryAction['label'],
            'next_action_note' => $this->indexNextActionNote($graphic),
            'primary_action' => $primaryAction,
            'is_terminal_status' => $this->isTerminalGraphicStatus($statusKey),
            'terminal_at' => $graphic->production_ready_at?->toAtomString(),
            'terminal_at_label' => optional($graphic->production_ready_at)->format('d.m.Y H:i'),
            'sort_sequence_code' => $this->sortSequenceCode($graphic->sequence_code),
        ];
    }

    private function applyIndexRowFilters(Collection $rows, array $filters): Collection
    {
        $query = mb_strtolower((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $approvalStatus = (string) ($filters['approval_status'] ?? '');
        $customerVisibleVisual = (string) ($filters['customer_visible_visual'] ?? '');

        $filtered = $rows->filter(function (array $row) use ($query, $status, $approvalStatus, $customerVisibleVisual): bool {
            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['order_number'] ?? null,
                    $row['work_form_number'] ?? null,
                    $row['customer_name'] ?? null,
                    $row['product_name'] ?? null,
                    $row['product_code'] ?? null,
                    $row['sequence_code'] ?? null,
                    $row['print_type'] ?? null,
                    $row['print_option'] ?? null,
                ])));

                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }

            if ($status !== '' && ($row['status_key'] ?? '') !== $status) {
                return false;
            }

            if ($approvalStatus !== '' && ($row['approval_status_key'] ?? '') !== $approvalStatus) {
                return false;
            }

            if ($customerVisibleVisual === 'yes' && !($row['has_customer_visible_visual'] ?? false)) {
                return false;
            }

            if ($customerVisibleVisual === 'no' && ($row['has_customer_visible_visual'] ?? false)) {
                return false;
            }

            return true;
        })->values();

        return $filtered;
    }

    private function applyIndexQueueFilter(Collection $rows, string $queue): Collection
    {
        return match ($queue) {
            '', 'all' => $rows,
            'action_waiting' => $rows->filter(fn (array $row) => ($row['queue_key'] ?? '') !== 'production_ready')->values(),
            default => $rows->filter(fn (array $row) => ($row['queue_key'] ?? '') === $queue)->values(),
        };
    }

    private function buildIndexTabs(Collection $groups, string $selectedQueue): array
    {
        $tabs = [
            'action_waiting' => 'Aksiyon Bekleyenler',
            'waiting_visual' => 'Görsel Bekleyenler',
            'control_waiting' => 'Kontrol Bekleyenler',
            'customer_approval_waiting' => 'Müşteri Onayı Bekleyenler',
            'revision_requested' => 'Revize İstenenler',
            'production_ready' => 'Üretime Hazır İşler',
            'completed' => 'Tamamlananlar',
            'all' => 'Tümü',
        ];

        return collect($tabs)->map(function (string $label, string $key) use ($groups, $selectedQueue): array {
            $count = $key === 'all'
                ? $groups->count()
                : $this->applyIndexGroupQueueFilter($groups, $key)->count();

            return [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'active' => $selectedQueue === $key,
            ];
        })->values()->all();
    }

    private function buildIndexSummary(Collection $rows, Collection $groups): array
    {
        return [
            'waiting_visual' => $rows->where('queue_key', 'waiting_visual')->count(),
            'control_waiting' => $rows->where('queue_key', 'control_waiting')->count(),
            'revision_requested' => $rows->where('queue_key', 'revision_requested')->count(),
            'production_ready' => $rows->where('queue_key', 'production_ready')->count(),
            'order_groups' => $groups->count(),
            'completed_groups' => $groups->where('is_completed_group', true)->count(),
        ];
    }

    private function buildIndexOrderGroups(Collection $rows): Collection
    {
        return $rows
            ->groupBy('order_id')
            ->map(function (Collection $groupRows, int|string $orderId): array {
                $orderedRows = $groupRows
                    ->sortBy([
                        ['order_item_id', 'asc'],
                        ['sort_sequence_code', 'asc'],
                        ['order_item_print_id', 'asc'],
                        ['graphic_id', 'asc'],
                    ])
                    ->values();

                $completedOperations = $orderedRows->where('is_terminal_status', true)->count();
                $isCompletedGroup = $orderedRows->isNotEmpty() && $completedOperations === $orderedRows->count();
                $terminalRow = $orderedRows
                    ->filter(fn (array $row) => !empty($row['terminal_at']))
                    ->sortByDesc('terminal_at')
                    ->first();
                $firstRow = $orderedRows->first();

                return [
                    'order_id' => (int) $orderId,
                    'order_number' => $firstRow['order_number'] ?? '-',
                    'customer_name' => $firstRow['customer_name'] ?? '-',
                    'delivery_date_label' => $firstRow['delivery_date_label'] ?? null,
                    'total_operations' => $orderedRows->count(),
                    'completed_operations' => $completedOperations,
                    'is_completed_group' => $isCompletedGroup,
                    'completion_label' => $isCompletedGroup
                        ? (($terminalRow['terminal_at_label'] ?? null) ? 'Tamamlanma: ' . $terminalRow['terminal_at_label'] : 'Tümü tamamlandı')
                        : null,
                    'progress_label' => $isCompletedGroup
                        ? ($orderedRows->count() . ' grafik işlemi · Tümü tamamlandı')
                        : ($orderedRows->count() . ' grafik işlemi · ' . $completedOperations . ' tamamlandı'),
                    'color_class' => $this->indexOrderGroupColorClass((int) $orderId),
                    'rows' => $orderedRows->all(),
                    'group_action' => [
                        'label' => 'Kaydı Aç',
                        'url' => data_get($firstRow, 'primary_action.url', route('admin.graphics.index')),
                    ],
                    'sort_priority' => $orderedRows->map(fn (array $row) => $this->queueSortPriority((string) ($row['queue_key'] ?? '')))->min() ?? 99,
                    'sort_completion_at' => $terminalRow['terminal_at'] ?? null,
                ];
            })
            ->sort(function (array $left, array $right): int {
                if (($left['is_completed_group'] ?? false) && ($right['is_completed_group'] ?? false)) {
                    return strcmp((string) ($right['sort_completion_at'] ?? ''), (string) ($left['sort_completion_at'] ?? ''))
                        ?: (($right['order_id'] ?? 0) <=> ($left['order_id'] ?? 0));
                }

                return (($left['sort_priority'] ?? 99) <=> ($right['sort_priority'] ?? 99))
                    ?: (($right['order_id'] ?? 0) <=> ($left['order_id'] ?? 0));
            })
            ->values();
    }

    private function applyIndexGroupQueueFilter(Collection $groups, string $queue): Collection
    {
        return match ($queue) {
            '', 'action_waiting' => $groups->filter(fn (array $group) => !($group['is_completed_group'] ?? false))->values(),
            'completed' => $groups->filter(fn (array $group) => (bool) ($group['is_completed_group'] ?? false))->values(),
            'all' => $groups->values(),
            default => $groups->filter(function (array $group) use ($queue): bool {
                if (($group['is_completed_group'] ?? false) === true) {
                    return false;
                }

                return collect($group['rows'] ?? [])->contains(fn (array $row) => ($row['queue_key'] ?? '') === $queue);
            })->values(),
        };
    }

    private function paginateIndexGroups(Collection $groups, int $perPage): LengthAwarePaginator
    {
        $currentPage = LengthAwarePaginator::resolveCurrentPage('page');
        $total = $groups->count();
        $items = $groups->forPage($currentPage, $perPage)->values()->all();

        return (new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        ))->withQueryString();
    }

    private function countGroupQueueRows(array $group, string $queue): int
    {
        return collect($group['rows'] ?? [])
            ->where('queue_key', $queue)
            ->count();
    }

    private function indexOrderGroupColorClass(int $orderId): string
    {
        return match ($orderId % 4) {
            0 => 'pd-graphic-order-group--blue',
            1 => 'pd-graphic-order-group--green',
            2 => 'pd-graphic-order-group--sand',
            default => 'pd-graphic-order-group--lavender',
        };
    }

    private function queueSortPriority(string $queueKey): int
    {
        return match ($queueKey) {
            'waiting_visual' => 1,
            'revision_requested' => 2,
            'customer_approval_waiting' => 3,
            'control_waiting' => 4,
            'production_ready' => 5,
            default => 9,
        };
    }

    private function isTerminalGraphicStatus(?string $status): bool
    {
        return $status === OrderItemPrintGraphic::STATUS_PRODUCTION_READY;
    }

    private function formatSnapshotDate(mixed $value): ?string
    {
        if (!filled($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->format('d.m.Y');
        } catch (\Throwable) {
            return is_string($value) ? trim($value) : null;
        }
    }

    private function indexQueueKey(OrderItemPrintGraphic $graphic): string
    {
        return match ((string) $graphic->status) {
            OrderItemPrintGraphic::STATUS_WAITING_VISUAL => 'waiting_visual',
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'customer_approval_waiting',
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'revision_requested',
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY => 'production_ready',
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED,
            OrderItemPrintGraphic::STATUS_APPROVED => 'control_waiting',
            default => 'action_waiting',
        };
    }

    private function indexStatusLabel(OrderItemPrintGraphic $graphic): string
    {
        return match ((string) $graphic->status) {
            OrderItemPrintGraphic::STATUS_WAITING_VISUAL => 'Görsel Bekliyor',
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => 'Kontrol Bekliyor',
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'Müşteri Onayı Bekliyor',
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'Revize İstendi',
            OrderItemPrintGraphic::STATUS_APPROVED => 'Onaylandı',
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY => 'Üretime Hazır',
            default => $graphic->safeStatusLabel(),
        };
    }

    private function indexStatusBadgeClass(?string $status): string
    {
        return match ($status) {
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'pd-ui-v1-graphics__badge--red',
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
            OrderItemPrintGraphic::STATUS_APPROVED => 'pd-ui-v1-graphics__badge--green',
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING,
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => 'pd-ui-v1-graphics__badge--blue',
            default => 'pd-ui-v1-graphics__badge--amber',
        };
    }

    private function indexStatusHint(OrderItemPrintGraphic $graphic): string
    {
        return match ((string) $graphic->status) {
            OrderItemPrintGraphic::STATUS_WAITING_VISUAL => 'Henüz son görsel yüklenmedi.',
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => 'Son görsel kontrol veya onay adımına hazır.',
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'Gönderilen görsel için müşteri yanıtı bekleniyor.',
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'Revize gelmeden üretime geçilemez.',
            OrderItemPrintGraphic::STATUS_APPROVED => 'Onay var, üretime hazırlık kararı ayrı verilir.',
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY => 'Grafik doğrusu üretim kabulüne hazır.',
            default => 'Grafik operasyonunu açıp mevcut durumu inceleyin.',
        };
    }

    private function indexNextActionNote(OrderItemPrintGraphic $graphic): string
    {
        return match ((string) $graphic->status) {
            OrderItemPrintGraphic::STATUS_WAITING_VISUAL => 'Exact baskı satırına görsel ekleyin.',
            OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED => 'Görseli kontrol edip gerekiyorsa onaya yönlendirin.',
            OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING => 'Müşteri kararını veya revize isteğini açın.',
            OrderItemPrintGraphic::STATUS_REVISION_REQUESTED => 'Revize dosyasını bu baskı anahtarına yükleyin.',
            OrderItemPrintGraphic::STATUS_APPROVED => 'Onay tek başına readiness değildir; son kararı verin.',
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY => 'Kayıt doğrulandı, detaydan ilerletin.',
            default => 'Grafik akışını kayıttan yönetin.',
        };
    }

    private function buildIndexPrimaryAction(OrderItemWorkForm $workForm, OrderItemPrintGraphic $graphic): array
    {
        $step = 'summary';
        $label = 'Kaydı Aç';

        if ($graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED) {
            $step = 'revision';
            $label = 'Revize Yükle';
        } elseif ($graphic->status === OrderItemPrintGraphic::STATUS_WAITING_VISUAL) {
            $step = 'upload';
            $label = 'Görsel Yükle';
        } elseif ($graphic->status === OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING) {
            $step = 'approval';
            $label = 'Onay Durumunu Aç';
        } elseif ($graphic->status === OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED) {
            $step = 'summary';
            $label = 'Grafiği Kontrol Et';
        } elseif ($graphic->status === OrderItemPrintGraphic::STATUS_APPROVED && $graphic->canMarkProductionReady()) {
            $step = 'ready';
            $label = 'Üretime Hazırla';
        }

        return [
            'label' => $label,
            'url' => route('admin.graphics.show', [
                'workForm' => $workForm,
                'operation' => $graphic->id,
                'step' => $step,
            ]),
        ];
    }

    private function buildIndexLastEvent(
        OrderItemPrintGraphic $graphic,
        ?OrderItemWorkFormAttachment $attachment,
        ?GraphicApprovalRequest $latestRequest
    ): array {
        if ($graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED && $graphic->revision_requested_at) {
            return [
                'label' => 'Son revize talebi',
                'at' => optional($graphic->revision_requested_at)->format('d.m.Y H:i') ?: '-',
            ];
        }

        if ($graphic->status === OrderItemPrintGraphic::STATUS_PRODUCTION_READY && $graphic->production_ready_at) {
            return [
                'label' => 'Üretime hazır işaretlendi',
                'at' => optional($graphic->production_ready_at)->format('d.m.Y H:i') ?: '-',
            ];
        }

        if ($latestRequest?->responded_at) {
            return [
                'label' => 'Son müşteri yanıtı',
                'at' => optional($latestRequest->responded_at)->format('d.m.Y H:i') ?: '-',
            ];
        }

        if ($latestRequest?->created_at) {
            return [
                'label' => 'Onay gönderildi',
                'at' => optional($latestRequest->created_at)->format('d.m.Y H:i') ?: '-',
            ];
        }

        if ($attachment?->created_at) {
            return [
                'label' => 'Son görsel yüklendi',
                'at' => optional($attachment->created_at)->format('d.m.Y H:i') ?: '-',
            ];
        }

        return [
            'label' => 'Son hareket yok',
            'at' => '-',
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
                'thumbnail_url' => $attachment->isImage() ? $this->resolveAttachmentPreviewUrl($attachment) : null,
                'preview_url' => $attachment->isImage() ? $this->resolveAttachmentPreviewUrl($attachment) : null,
                'original_url' => $this->resolveAttachmentPreviewUrl($attachment),
                'open_url' => $this->resolveAttachmentPreviewUrl($attachment),
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
                    'open_url' => $this->resolveAttachmentPreviewUrl($item),
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
            'procurement_request_created' => 'Tedarik ihtiyacı oluşturuldu',
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

    private function resolveAttachmentPreviewUrl(OrderItemWorkFormAttachment $attachment): ?string
    {
        return $this->resolveAttachmentAdminUrl($attachment);
    }

    private function resolveAttachmentOriginalUrl(OrderItemWorkFormAttachment $attachment): ?string
    {
        return $this->resolveAttachmentAdminUrl($attachment);
    }

    private function resolveAttachmentAdminUrl(OrderItemWorkFormAttachment $attachment): ?string
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
