<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Services\GraphicModuleDataBuilder;
use App\Services\GraphicWorkflowService;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\TenantAccessService;
use App\Services\TenantResolver;
use App\Services\WorkFormAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class GraphicController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantAccessService $tenantAccessService,
        protected GraphicModuleDataBuilder $dataBuilder,
        protected GraphicWorkflowService $workflowService,
        protected OrderItemPrintGraphicWorkflowService $printGraphicWorkflowService,
        protected WorkFormAttachmentService $attachmentService
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        abort_unless($tenant, 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'approval_status' => ['nullable', 'string', 'max:50'],
            'customer_visible_visual' => ['nullable', Rule::in(['yes', 'no'])],
            'per_page' => ['nullable', Rule::in(['10', '20', '50', 10, 20, 50])],
            'queue' => ['nullable', Rule::in([
                'action_waiting',
                'waiting_visual',
                'control_waiting',
                'customer_approval_waiting',
                'revision_requested',
                'production_ready',
                'completed',
                'all',
            ])],
        ]);

        $workForms = OrderItemWorkForm::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('status', 'active')
            ->with([
                'attachments',
                'systemWorkFolder',
                'printGraphics.latestAttachment',
                'printGraphics.latestApprovalRequest',
                'printGraphics.orderItemPrint',
            ])
            ->latest('id')
            ->get();

        return view('admin.graphics.index', $this->dataBuilder->buildIndex($workForms, $validated));
    }

    public function show(Request $request, OrderItemWorkForm $workForm): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $workForm->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'operation' => ['nullable', 'integer'],
            'step' => ['nullable', 'string', Rule::in(['upload', 'summary', 'approval', 'revision', 'ready'])],
        ]);

        $relations = [
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

        return view('admin.graphics.show', $this->dataBuilder->buildShow(
            $workForm,
            Schema::hasTable('graphic_approval_requests')
                && $this->tenantAccessService->canAccessModule($tenant, 'graphic_customer_approval')
                && $this->tenantAccessService->canAccessFeature($tenant, 'public_graphic_approval', 'graphic_customer_approval'),
            isset($validated['operation']) ? (int) $validated['operation'] : null,
            $validated['step'] ?? null
        ));
    }

    public function updateOperationStatus(Request $request, OrderItemWorkForm $workForm, OrderItemPrintGraphic $graphic): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $workForm->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $graphic = $this->resolveGraphicOperation($workForm, $graphic, $tenant->id);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approved', 'revision_requested', 'production_ready'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['action'] === 'revision_requested' && !filled($validated['note'] ?? null)) {
            throw ValidationException::withMessages([
                'note' => 'Revize notu zorunludur.',
            ]);
        }

        try {
            match ($validated['action']) {
                'approved' => $this->printGraphicWorkflowService->markApproved($graphic, $request->user()),
                'revision_requested' => $this->printGraphicWorkflowService->requestRevision(
                    $graphic,
                    trim((string) ($validated['note'] ?? '')),
                    $request->user()
                ),
                'production_ready' => $this->printGraphicWorkflowService->markProductionReady($graphic, $request->user()),
            };
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'note' => $exception->getMessage(),
            ]);
        }

        $this->syncLegacyGraphicSnapshot($workForm, $request->user()?->id);

        return redirect()
            ->route('admin.graphics.show', $workForm)
            ->with('success', 'Grafik operasyon durumu güncellendi.');
    }

    public function updateStatus(Request $request, OrderItemWorkForm $workForm): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $workForm->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                'hazirlaniyor',
                'musteri_onayi_bekliyor',
                'revize_istendi',
                'onaylandi',
                'uretime_hazir',
            ])],
        ]);

        $this->workflowService->updateGraphicStatus($workForm, $validated['status'], $request->user());
        $this->syncPrintGraphicsFromLegacyStatus($workForm, $validated['status'], $request->user()?->id);

        return redirect()
            ->route('admin.graphics.show', $workForm)
            ->with('success', 'Grafik durumu güncellendi.');
    }

    private function applyFilters(Collection $workForms, array $filters): Collection
    {
        $query = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $status = mb_strtolower(trim((string) ($filters['status'] ?? '')));
        $approvalStatus = mb_strtolower(trim((string) ($filters['approval_status'] ?? '')));
        $customerVisibleVisual = (string) ($filters['customer_visible_visual'] ?? '');

        return $workForms
            ->filter(function (OrderItemWorkForm $workForm) use ($query, $status, $approvalStatus, $customerVisibleVisual): bool {
                $orderSnapshot = $workForm->order_snapshot ?? [];
                $customerSnapshot = $workForm->customer_snapshot ?? [];
                $productSnapshot = $workForm->product_snapshot ?? [];
                $printGraphics = $workForm->printGraphics
                    ->filter(fn (OrderItemPrintGraphic $graphic) => $graphic->status !== OrderItemPrintGraphic::STATUS_NOT_REQUIRED)
                    ->values();

                if ($printGraphics->isEmpty()) {
                    return false;
                }

                if ($query !== '') {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $workForm->work_form_number,
                        data_get($orderSnapshot, 'document_number'),
                        data_get($customerSnapshot, 'company_name'),
                        data_get($productSnapshot, 'product_name'),
                    ])));

                    if (!str_contains($haystack, $query)) {
                        return false;
                    }
                }

                if ($status !== '' && !$printGraphics->contains(fn (OrderItemPrintGraphic $graphic) => mb_strtolower((string) $graphic->status) === $status)) {
                    return false;
                }

                if ($approvalStatus !== '' && !$printGraphics->contains(fn (OrderItemPrintGraphic $graphic) => mb_strtolower((string) $graphic->customer_approval_status) === $approvalStatus)) {
                    return false;
                }

                if ($customerVisibleVisual !== '') {
                    $hasCustomerVisibleVisual = $workForm->attachments->contains(
                        fn ($attachment) => $attachment->attachment_type === 'graphic_visual' && $attachment->visibility === 'customer_visible'
                    );

                    if ($customerVisibleVisual === 'yes' && !$hasCustomerVisibleVisual) {
                        return false;
                    }

                    if ($customerVisibleVisual === 'no' && $hasCustomerVisibleVisual) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    private function resolveGraphicOperation(OrderItemWorkForm $workForm, OrderItemPrintGraphic $graphic, int $tenantId): OrderItemPrintGraphic
    {
        if (
            $graphic->tenant_account_id !== $tenantId
            || $graphic->order_item_work_form_id !== $workForm->id
            || $graphic->order_id !== $workForm->order_id
            || $graphic->order_item_id !== $workForm->order_item_id
        ) {
            abort(403);
        }

        return $graphic;
    }

    private function syncLegacyGraphicSnapshot(OrderItemWorkForm $workForm, ?int $userId = null): void
    {
        $workForm->loadMissing('printGraphics.latestAttachment');

        $graphics = $workForm->printGraphics
            ->filter(fn (OrderItemPrintGraphic $graphic) => $graphic->status !== OrderItemPrintGraphic::STATUS_NOT_REQUIRED)
            ->values();

        $snapshot = is_array($workForm->graphic_snapshot) ? $workForm->graphic_snapshot : [];

        if ($graphics->isEmpty()) {
            $snapshot['status'] = 'gerekli_degil';
            $snapshot['approval_status'] = 'gerekli_degil';
            $snapshot['updated_at'] = now()->toAtomString();
            $workForm->forceFill([
                'graphic_snapshot' => $snapshot,
                'updated_by' => $userId,
            ])->save();

            return;
        }

        $status = 'bekliyor';
        $approvalStatus = 'onay_gerekmiyor';

        if ($graphics->contains(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_REVISION_REQUESTED)) {
            $status = 'revize_istendi';
            $approvalStatus = 'revize_istendi';
        } elseif ($graphics->contains(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING)) {
            $status = 'musteri_onayi_bekliyor';
            $approvalStatus = 'onay_bekliyor';
        } elseif ($graphics->contains(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_WAITING_VISUAL)) {
            $status = 'bekliyor';
            $approvalStatus = 'onay_gerekmiyor';
        } elseif ($graphics->every(fn (OrderItemPrintGraphic $graphic) => $graphic->status === OrderItemPrintGraphic::STATUS_PRODUCTION_READY)) {
            $status = 'uretime_hazir';
            $approvalStatus = 'onaylandi';
        } elseif ($graphics->every(fn (OrderItemPrintGraphic $graphic) => in_array($graphic->status, [
            OrderItemPrintGraphic::STATUS_APPROVED,
            OrderItemPrintGraphic::STATUS_PRODUCTION_READY,
        ], true))) {
            $status = 'onaylandi';
            $approvalStatus = 'onaylandi';
        } else {
            $status = 'gorsel_eklendi';
            $approvalStatus = 'onay_gerekmiyor';
        }

        $primaryAttachmentId = $graphics
            ->map(fn (OrderItemPrintGraphic $graphic) => $graphic->latestAttachment)
            ->filter()
            ->sortByDesc('id')
            ->first()?->id;

        $snapshot['status'] = $status;
        $snapshot['approval_status'] = $approvalStatus;
        $snapshot['primary_visual_attachment_id'] = $primaryAttachmentId;
        $snapshot['updated_at'] = now()->toAtomString();

        $workForm->forceFill([
            'graphic_snapshot' => $snapshot,
            'updated_by' => $userId,
        ])->save();
    }

    private function syncPrintGraphicsFromLegacyStatus(OrderItemWorkForm $workForm, string $legacyStatus, ?int $userId = null): void
    {
        $workForm->loadMissing('printGraphics');

        foreach ($workForm->printGraphics as $graphic) {
            match ($legacyStatus) {
                'musteri_onayi_bekliyor' => $graphic->forceFill([
                    'status' => OrderItemPrintGraphic::STATUS_CUSTOMER_APPROVAL_WAITING,
                    'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_WAITING,
                    'updated_by' => $userId,
                ])->save(),
                'revize_istendi' => $graphic->forceFill([
                    'status' => OrderItemPrintGraphic::STATUS_REVISION_REQUESTED,
                    'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_REVISION_REQUESTED,
                    'revision_requested_at' => now(),
                    'updated_by' => $userId,
                ])->save(),
                'onaylandi' => $graphic->forceFill([
                    'status' => OrderItemPrintGraphic::STATUS_APPROVED,
                    'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED,
                    'approved_at' => $graphic->approved_at ?: now(),
                    'updated_by' => $userId,
                ])->save(),
                'uretime_hazir' => $graphic->forceFill([
                    'status' => $graphic->latest_attachment_id
                        ? OrderItemPrintGraphic::STATUS_PRODUCTION_READY
                        : ($graphic->status === OrderItemPrintGraphic::STATUS_WAITING_VISUAL
                            ? OrderItemPrintGraphic::STATUS_WAITING_VISUAL
                            : OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED),
                    'customer_approval_status' => OrderItemPrintGraphic::CUSTOMER_APPROVAL_APPROVED,
                    'approved_at' => $graphic->approved_at ?: now(),
                    'production_ready_at' => $graphic->latest_attachment_id
                        ? ($graphic->production_ready_at ?: now())
                        : $graphic->production_ready_at,
                    'updated_by' => $userId,
                ])->save(),
                default => $graphic->forceFill([
                    'status' => $graphic->latest_attachment_id
                        ? OrderItemPrintGraphic::STATUS_VISUAL_UPLOADED
                        : OrderItemPrintGraphic::STATUS_WAITING_VISUAL,
                    'updated_by' => $userId,
                ])->save(),
            };
        }
    }
}
