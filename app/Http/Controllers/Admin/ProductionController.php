<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountTransaction;
use App\Models\OrderItemPrintProduction;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\ModuleFeatureCatalogService;
use App\Services\ProductionDataBuilder;
use App\Services\ProductionReadinessResolver;
use App\Services\ProductionWorkflowService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductionController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected ProductionWorkflowService $workflowService,
        protected ProductionReadinessResolver $readinessResolver,
        protected ProductionDataBuilder $dataBuilder,
        protected ModuleFeatureCatalogService $moduleFeatureCatalog
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        abort_unless($tenant, 403);

        $filters = $request->validate([
            'pool' => ['nullable', Rule::in(['ready', 'internal', 'outsourced', 'preparation', 'partial', 'qc', 'completed'])],
            'q' => ['nullable', 'string', 'max:255'],
            'print_type' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'string', 'max:40'],
            'cliche_status' => ['nullable', 'string', 'max:40'],
            'qc_status' => ['nullable', 'string', 'max:40'],
            'graphic_ready' => ['nullable', Rule::in(['evet', 'hayir'])],
            'procurement_ready' => ['nullable', Rule::in(['evet', 'hayir'])],
            'graphic_status' => ['nullable', 'string', 'max:60'],
            'procurement_status' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', Rule::in(['25', '50', '100', '250'])],
        ]);

        $records = OrderItemPrintProduction::query()
            ->where('tenant_account_id', $tenant->id)
            ->with([
                'order.customer',
                'orderItem',
                'orderItemPrint.subcontractorCompany',
                'orderItemPrint.graphicOperation.latestAttachment',
                'graphicOperation.latestAttachment',
                'workForm.procurement',
                'productionCompany',
            ])
            ->latest('id')
            ->get();
        $records->each(fn (OrderItemPrintProduction $production) => $this->hydrateRuntimeSnapshot($production));

        $filtered = $this->applyFilters($records, $filters);
        $limit = (int) ($filters['limit'] ?? 50);
        $rows = $filtered->take($limit)->values();
        $qcUiEnabled = $this->productionQcUiEnabled($tenant);

        return view('admin.productions.index', [
            'tenant' => $tenant,
            'filters' => $filters,
            'rows' => $rows,
            'summaryCards' => $this->buildSummaryCards($filtered, $qcUiEnabled),
            'statusLabels' => OrderItemPrintProduction::statusLabels(),
            'typeLabels' => OrderItemPrintProduction::productionTypeLabels(),
            'clicheLabels' => OrderItemPrintProduction::clicheStatusLabels(),
            'qcLabels' => OrderItemPrintProduction::qcStatusLabels(),
            'qcUiEnabled' => $qcUiEnabled,
        ]);
    }

    public function show(Request $request, OrderItemPrintProduction $production): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $production->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $allowedTabs = ['genel', 'ic-uretim', 'dis-uretim', 'islemler', 'fotograflar', 'gecmis'];
        if ($request->has('tab')) {
            $activeTab = in_array($request->get('tab'), $allowedTabs, true) ? $request->get('tab') : 'genel';
        } else {
            $activeTab = $this->defaultShowTab($production);
        }

        $production->loadMissing([
            'order.customer',
            'orderItem',
            'orderItemPrint.subcontractorCompany',
            'orderItemPrint.setupRequirements.assignedCompany',
            'orderItemPrint.graphicOperation.latestAttachment',
            'graphicOperation.latestAttachment',
            'workForm.attachments.uploader',
            'workForm.orderItem.prints.production',
            'workForm.procurement',
            'workForm.activityLogs',
            'workForm.activityLogs.creator',
            'workForm.systemWorkFolder',
            'productionCompany.companyRoles',
            'assignedUser',
        ]);
        $this->hydrateRuntimeSnapshot($production);

        $history = $production->workForm
            ? $production->workForm->activityLogs
                ->filter(fn ($log) => in_array($log->action_type, $this->productionActionTypes(), true))
                ->values()
            : collect();

        $companies = $this->eligibleProductionCompanies($tenant->id);

        $users = User::query()
            ->whereHas('userRoles', function ($query) use ($tenant): void {
                $query->where('tenant_account_id', $tenant->id);
            })
            ->orderBy('name')
            ->get();
        $qcUiEnabled = $this->productionQcUiEnabled($tenant);
        $canViewFinancialData = $request->user()?->canViewFinancialData($tenant->id) ?? false;
        $resolvedProductionType = $production->production_type
            ?: OrderItemPrintProduction::normalizeProductionType($production->orderItemPrint?->production_type);
        $matchedCurrentAccount = null;
        if ($production->production_company_id) {
            $matchedCurrentAccount = CurrentAccountLink::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('link_type', CurrentAccountLink::LINK_COMPANY)
                ->where('link_id', $production->production_company_id)
                ->with('currentAccount')
                ->first()?->currentAccount;
        }
        $subcontractorTransaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $production->tenant_account_id)
            ->where('source_type', SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $production->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT)
            ->latest('id')
            ->first();

        // Tab metadata for navigation
        $tabMetadata = [
            'genel' => ['label' => 'Genel Özet', 'icon' => 'dashboard'],
            'ic-uretim' => ['label' => 'İç Üretim', 'icon' => 'settings'],
            'dis-uretim' => ['label' => 'Dış Üretim / Fason', 'icon' => 'truck'],
            'islemler' => ['label' => 'İşlemler', 'icon' => 'tools'],
            'fotograflar' => ['label' => 'Fotoğraflar', 'icon' => 'camera'],
            'gecmis' => ['label' => 'Geçmiş', 'icon' => 'history'],
        ];

        // Helper function for tab URLs
        $tabUrl = function (string $tab) use ($production): string {
            return route('admin.productions.show', $production) . '?tab=' . $tab;
        };

        return view('admin.productions.show', [
            'production' => $production,
            'history' => $history,
            'companies' => $companies,
            'users' => $users,
            'statusLabels' => OrderItemPrintProduction::statusLabels(),
            'typeLabels' => OrderItemPrintProduction::productionTypeLabels(),
            'clicheLabels' => OrderItemPrintProduction::clicheStatusLabels(),
            'qcLabels' => OrderItemPrintProduction::qcStatusLabels(),
            'nextActionLabel' => $this->nextActionLabel($production),
            'qcUiEnabled' => $qcUiEnabled,
            'canViewFinancialData' => $canViewFinancialData,
            'matchedCurrentAccount' => $matchedCurrentAccount,
            'subcontractorTransaction' => $subcontractorTransaction,
            'activeTab' => $activeTab,
            'allowedTabs' => $allowedTabs,
            'tabMetadata' => $tabMetadata,
            'tabUrl' => $tabUrl,
            'isInternalProduction' => $resolvedProductionType === OrderItemPrintProduction::TYPE_INTERNAL,
            'isExternalProduction' => $resolvedProductionType === OrderItemPrintProduction::TYPE_EXTERNAL,
            'isSubcontractedProduction' => $resolvedProductionType === OrderItemPrintProduction::TYPE_OUTSOURCED,
        ]);
    }

    public function updateAssignment(Request $request, OrderItemPrintProduction $production): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $production->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'production_type' => ['required', Rule::in(array_keys(OrderItemPrintProduction::productionTypeLabels()))],
            'production_company_id' => ['nullable', 'integer'],
            'production_unit_name' => ['nullable', 'string', 'max:120'],
            'assigned_to' => ['nullable', 'integer'],
            'cliche_required' => ['nullable', 'boolean'],
            'cliche_status' => ['nullable', Rule::in(array_keys(OrderItemPrintProduction::clicheStatusLabels()))],
            'production_note' => ['nullable', 'string', 'max:1000'],
            'subcontractor_cost' => ['nullable', 'numeric', 'min:0'],
            'subcontractor_cost_currency' => ['nullable', Rule::in(['TRY', 'USD', 'EUR'])],
            'subcontractor_cost_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $canViewFinancialData = $request->user()?->canViewFinancialData($tenant->id) ?? false;

        if (!$canViewFinancialData && (
            $request->filled('subcontractor_cost')
            || $request->filled('subcontractor_cost_currency')
            || $request->filled('subcontractor_cost_note')
        )) {
            abort(403);
        }

        if (in_array($validated['production_type'], [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true)) {
            $request->validate([
                'production_company_id' => ['required', 'integer'],
            ]);
        }

        $companyId = (int) ($validated['production_company_id'] ?? 0);
        if ($companyId > 0) {
            $allowedCompany = $this->eligibleProductionCompaniesQuery($tenant->id)
                ->whereKey($companyId)
                ->exists();

            abort_unless($allowedCompany, 403);
        }

        $assignedTo = (int) ($validated['assigned_to'] ?? 0);
        if ($assignedTo > 0) {
            $allowedUser = User::query()
                ->whereKey($assignedTo)
                ->whereHas('userRoles', fn ($query) => $query->where('tenant_account_id', $tenant->id))
                ->exists();

            abort_unless($allowedUser, 403);
        }

        $this->workflowService->updateAssignment(
            $production,
            [
                'production_type' => $validated['production_type'],
                'production_company_id' => $companyId > 0 ? $companyId : null,
                'production_unit_name' => $validated['production_unit_name'] ?? null,
                'assigned_to' => $assignedTo > 0 ? $assignedTo : null,
                'cliche_required' => (bool) ($validated['cliche_required'] ?? false),
                'cliche_status' => $validated['cliche_status'] ?? null,
                'production_note' => filled($validated['production_note'] ?? null)
                    ? trim((string) $validated['production_note'])
                    : null,
                'subcontractor_cost' => $canViewFinancialData && array_key_exists('subcontractor_cost', $validated)
                    ? (($validated['subcontractor_cost'] ?? null) !== null ? round((float) $validated['subcontractor_cost'], 2) : null)
                    : $production->subcontractor_cost,
                'subcontractor_cost_currency' => $canViewFinancialData
                    ? ($validated['subcontractor_cost_currency'] ?? $production->subcontractor_cost_currency ?? 'TRY')
                    : $production->subcontractor_cost_currency,
                'subcontractor_cost_note' => $canViewFinancialData && filled($validated['subcontractor_cost_note'] ?? null)
                    ? trim((string) $validated['subcontractor_cost_note'])
                    : ($canViewFinancialData && array_key_exists('subcontractor_cost_note', $validated) ? null : $production->subcontractor_cost_note),
            ],
            $request->user(),
            'Üretim atama bilgileri güncellendi.'
        );

        return redirect()
            ->route('admin.productions.show', $production)
            ->with('success', 'Üretim ataması güncellendi.');
    }

    public function updateStatus(Request $request, OrderItemPrintProduction $production): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $production->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in([
                'assign_internal',
                'assign_external',
                'sent_to_subcontractor',
                'returned_from_subcontractor',
                'qc_started',
                'qc_passed',
                'qc_failed',
                'partial',
                'completed',
                'issue',
                'cancel',
            ])],
            'production_company_id' => ['nullable', 'integer'],
            'production_unit_name' => ['nullable', 'string', 'max:120'],
            'partial_quantity' => ['nullable', 'numeric', 'gt:0'],
            'completed_quantity' => ['nullable', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $note = filled($validated['note'] ?? null) ? trim((string) $validated['note']) : null;

            match ($validated['action']) {
                'assign_internal' => $this->workflowService->assignInternal(
                    $production,
                    $request->user(),
                    $validated['production_unit_name'] ?? null,
                    $note
                ),
                'assign_external' => $this->workflowService->assignExternal(
                    $production,
                    $this->eligibleProductionCompaniesQuery($tenant->id)
                        ->findOrFail((int) ($validated['production_company_id'] ?? 0)),
                    $request->user(),
                    $note
                ),
                'sent_to_subcontractor' => $this->workflowService->markSentToSubcontractor($production, $request->user(), $note),
                'returned_from_subcontractor' => $this->workflowService->markReturnedFromSubcontractor($production, $request->user(), $note),
                'qc_started' => $this->workflowService->markQcStarted($production, $request->user(), $note),
                'qc_passed' => $this->workflowService->markQcPassed($production, $request->user(), $note),
                'qc_failed' => $this->workflowService->markQcFailed($production, $request->user(), $note),
                'partial' => $this->workflowService->markPartiallyCompleted(
                    $production,
                    (float) ($validated['partial_quantity'] ?? 0),
                    $request->user(),
                    $note
                ),
                'completed' => $this->workflowService->markCompleted(
                    $production,
                    $request->user(),
                    $note,
                    isset($validated['completed_quantity']) ? (float) $validated['completed_quantity'] : null
                ),
                'issue' => $this->workflowService->markIssue($production, $request->user(), $note),
                'cancel' => $this->workflowService->cancel($production, $request->user(), $note),
            };
        } catch (\InvalidArgumentException $exception) {
            $field = match ($validated['action']) {
                'partial' => 'partial_quantity',
                'completed' => isset($validated['completed_quantity']) ? 'completed_quantity' : 'action',
                default => 'action',
            };

            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.productions.show', $production)
            ->with('success', 'Üretim durumu güncellendi.');
    }

    private function applyFilters(Collection $records, array $filters): Collection
    {
        $query = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $pool = trim((string) ($filters['pool'] ?? ''));
        $printType = trim((string) ($filters['print_type'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));
        $clicheStatus = trim((string) ($filters['cliche_status'] ?? ''));
        $qcStatus = trim((string) ($filters['qc_status'] ?? ''));
        $graphicReady = (string) ($filters['graphic_ready'] ?? '');
        $procurementReady = (string) ($filters['procurement_ready'] ?? '');
        $graphicStatus = trim((string) ($filters['graphic_status'] ?? ''));
        $procurementStatus = trim((string) ($filters['procurement_status'] ?? ''));

        return $records->filter(function (OrderItemPrintProduction $production) use (
            $query,
            $pool,
            $printType,
            $status,
            $type,
            $clicheStatus,
            $qcStatus,
            $graphicReady,
            $procurementReady,
            $graphicStatus,
            $procurementStatus
        ): bool {
            $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
            $customerName = (string) ($production->order?->customer?->legal_name ?? '');

            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $snapshot['order_number'] ?? null,
                    $snapshot['work_form_number'] ?? null,
                    $customerName,
                    $snapshot['product_name'] ?? null,
                    $snapshot['product_code'] ?? null,
                    $snapshot['print_type'] ?? null,
                    $snapshot['print_option'] ?? null,
                    $snapshot['print_sequence'] ?? null,
                ])));

                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }

            if ($printType !== '' && (string) ($snapshot['print_type'] ?? '') !== $printType) {
                return false;
            }

            if ($status !== '' && $production->production_status !== $status) {
                return false;
            }

            if ($type !== '' && $production->production_type !== $type) {
                return false;
            }

            if ($clicheStatus !== '' && $production->cliche_status !== $clicheStatus) {
                return false;
            }

            if ($qcStatus !== '' && $production->qc_status !== $qcStatus) {
                return false;
            }

            $readiness = $this->readinessResolver->resolve($production);

            if ($graphicReady !== '' && (bool) $readiness['graphic_ready'] !== ($graphicReady === 'evet')) {
                return false;
            }

            if ($procurementReady !== '' && (bool) $readiness['procurement_ready'] !== ($procurementReady === 'evet')) {
                return false;
            }

            if ($graphicStatus !== '' && (string) ($readiness['graphic_status'] ?? '') !== $graphicStatus) {
                return false;
            }

            if ($procurementStatus !== '' && (string) ($readiness['procurement_status'] ?? '') !== $procurementStatus) {
                return false;
            }

            if ($pool !== '' && !$this->matchesPoolFilter($production, $snapshot, $readiness, $pool)) {
                return false;
            }

            return true;
        })->values();
    }

    private function buildSummaryCards(Collection $records, bool $qcUiEnabled): array
    {
        $cards = [
            ['label' => 'Üretim Bekleyen', 'value' => $records->where('production_status', OrderItemPrintProduction::STATUS_PENDING)->count()],
            ['label' => 'İç Üretimde', 'value' => $records->where('production_status', OrderItemPrintProduction::STATUS_INTERNAL)->count()],
            ['label' => 'Fasona Gönderilen', 'value' => $records->where('production_status', OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR)->count()],
            ['label' => 'Tamamlanan', 'value' => $records->where('production_status', OrderItemPrintProduction::STATUS_COMPLETED)->count()],
            ['label' => 'Sorunlu', 'value' => $records->where('production_status', OrderItemPrintProduction::STATUS_PROBLEMATIC)->count()],
        ];

        if ($qcUiEnabled) {
            array_splice($cards, 3, 0, [[
                'label' => 'Kalite Kontrol',
                'value' => $records->where('production_status', OrderItemPrintProduction::STATUS_QUALITY_CONTROL)->count(),
            ]]);
        }

        return $cards;
    }

    private function nextActionLabel(OrderItemPrintProduction $production): string
    {
        return match ($production->production_status) {
            OrderItemPrintProduction::STATUS_PENDING => 'Üretim atamasını netleştir',
            OrderItemPrintProduction::STATUS_INTERNAL => 'Üretim ilerlemesini takip et',
            OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR => 'Fason dönüşünü bekle',
            OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR => 'Kalite kontrole al',
            OrderItemPrintProduction::STATUS_QUALITY_CONTROL => 'QC sonucunu işle',
            OrderItemPrintProduction::STATUS_COMPLETED => 'Teslimat sürecine hazır',
            OrderItemPrintProduction::STATUS_PROBLEMATIC => 'Sorunu değerlendir',
            OrderItemPrintProduction::STATUS_CANCELLED => 'İptal edildi',
            default => 'Üretim kaydını incele',
        };
    }

    private function productionActionTypes(): array
    {
        return [
            'production_operation_created',
            'production_assigned_internal',
            'production_assigned_external',
            'production_started',
            'production_sent_to_subcontractor',
            'production_returned_from_subcontractor',
            'production_qc_started',
            'production_qc_passed',
            'production_qc_failed',
            'production_partially_completed',
            'production_completed',
            'production_issue_reported',
            'production_cancelled',
            'production_photo_added',
        ];
    }

    private function defaultShowTab(OrderItemPrintProduction $production): string
    {
        $resolvedType = $production->production_type
            ?: OrderItemPrintProduction::normalizeProductionType($production->orderItemPrint()->value('production_type'));

        return match ($resolvedType) {
            OrderItemPrintProduction::TYPE_INTERNAL => 'ic-uretim',
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED => 'dis-uretim',
            default => 'genel',
        };
    }

    private function hydrateRuntimeSnapshot(OrderItemPrintProduction $production): void
    {
        $production->setRelation('orderItemPrint', $production->orderItemPrint?->fresh([
            'graphicOperation.latestAttachment',
            'subcontractorCompany',
            'setupRequirements.assignedCompany',
        ]) ?? $production->orderItemPrint);

        $production->forceFill([
            'production_snapshot' => $this->dataBuilder->build(
                $production->orderItemPrint,
                $production->workForm,
                $production
            ),
        ]);
    }

    private function matchesPoolFilter(
        OrderItemPrintProduction $production,
        array $snapshot,
        array $readiness,
        string $pool
    ): bool {
        return match ($pool) {
            'ready' => (bool) ($snapshot['ui_can_start'] ?? false),
            'internal' => $production->production_type === OrderItemPrintProduction::TYPE_INTERNAL,
            'outsourced' => in_array($production->production_type, [
                OrderItemPrintProduction::TYPE_EXTERNAL,
                OrderItemPrintProduction::TYPE_OUTSOURCED,
            ], true),
            'preparation' => !(bool) ($snapshot['ui_can_start'] ?? false),
            'partial' => (float) $production->completed_quantity > 0.0
                && (float) $production->remaining_quantity > 0.0,
            'qc' => $production->production_status === OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
            'completed' => $production->production_status === OrderItemPrintProduction::STATUS_COMPLETED,
            default => true,
        };
    }

    private function productionQcUiEnabled(TenantAccount $tenant): bool
    {
        $tenant->loadMissing('modules');
        $canonicalModuleKey = $this->moduleFeatureCatalog->normalizeModuleKey('quality_control');
        $canonicalFeatureKey = $this->moduleFeatureCatalog->normalizeFeatureKey('quality_control');

        return $tenant->modules
            ->contains(fn ($module) => (bool) $module->is_enabled
                && (
                    in_array((string) $module->module_key, ['production_qc', 'quality_control', $canonicalModuleKey], true)
                    || in_array((string) $module->feature_key, ['production_qc', 'quality_control', $canonicalFeatureKey], true)
                ));
    }

    private function eligibleProductionCompanies(int $tenantId): Collection
    {
        return $this->eligibleProductionCompaniesQuery($tenantId)
            ->with('companyRoles:id,company_id,role_key')
            ->orderBy('legal_name')
            ->get();
    }

    private function eligibleProductionCompaniesQuery(int $tenantId)
    {
        return Company::query()
            ->where('tenant_account_id', $tenantId)
            ->active()
            ->whereHas('companyRoles', function ($query): void {
                $query->whereIn('role_key', ['print_fason', 'production_partner']);
            });
    }
}
