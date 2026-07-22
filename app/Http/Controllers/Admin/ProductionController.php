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
use App\Support\WorkFormActivityLabelResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
            'route' => ['nullable', Rule::in(['internal', 'outsourced', 'supplier_printed', 'completed', 'all'])],
            'pool' => ['nullable', Rule::in(['ready', 'internal', 'outsourced', 'preparation', 'partial', 'qc', 'completed'])],
            'q' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'max:160'],
            'print_type' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'string', 'max:40'],
            'operator' => ['nullable', 'integer'],
            'subcontractor' => ['nullable', 'integer'],
            'cliche_status' => ['nullable', 'string', 'max:40'],
            'qc_status' => ['nullable', 'string', 'max:40'],
            'graphic_ready' => ['nullable', Rule::in(['evet', 'hayir'])],
            'procurement_ready' => ['nullable', Rule::in(['evet', 'hayir'])],
            'graphic_status' => ['nullable', 'string', 'max:60'],
            'procurement_status' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', Rule::in(['25', '50', '100', '250'])],
            'per_page' => ['nullable', Rule::in(['10', '20', '50'])],
        ]);

        $activeRoute = $this->normalizeProductionRouteFilter($filters);
        $perPage = $this->normalizeProductionPerPage($filters);
        $filters['route'] = $activeRoute;
        $filters['per_page'] = (string) $perPage;

        $query = OrderItemPrintProduction::query()
            ->where('tenant_account_id', $tenant->id)
            ->with([
                'order.customer',
                'orderItem',
                'orderItemPrint.subcontractorCompany',
                'orderItemPrint.standardPrintType',
                'orderItemPrint.tenantPrintSetting.standardPrintType',
                'orderItemPrint.graphicOperation.latestAttachment',
                'graphicOperation.latestAttachment',
                'workForm.procurement',
                'productionCompany',
                'assignedUser',
            ]);

        $this->applyProductionRouteFilter($query, $activeRoute);
        $this->applyProductionPoolDbFilters($query, $filters);
        $this->applyProductionPoolSort($query, $activeRoute);

        /** @var LengthAwarePaginator $rows */
        $rows = $query->paginate($perPage)->withQueryString();
        $rows->setCollection($this->buildProductionPoolRows($rows->getCollection()));

        $qcUiEnabled = $this->productionQcUiEnabled($tenant);

        return view('admin.productions.index', [
            'tenant' => $tenant,
            'filters' => $filters,
            'rows' => $rows,
            'methodGroups' => $this->buildProductionMethodGroups($rows->getCollection()),
            'routeTabs' => $this->buildProductionRouteTabs($tenant, $filters),
            'methodOptions' => $this->buildProductionMethodOptions($tenant, $activeRoute),
            'summaryCards' => $this->buildProductionPoolSummaryCards($tenant, $qcUiEnabled),
            'statusLabels' => OrderItemPrintProduction::statusLabels(),
            'typeLabels' => OrderItemPrintProduction::productionTypeLabels(),
            'clicheLabels' => OrderItemPrintProduction::clicheStatusLabels(),
            'qcLabels' => OrderItemPrintProduction::qcStatusLabels(),
            'qcUiEnabled' => $qcUiEnabled,
        ]);
    }
    public function show(Request $request, OrderItemPrintProduction $production): View|RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $production->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $legacyOperationTabs = ['ic-uretim', 'dis-uretim', 'islemler'];
        $requestedTab = (string) $request->get('tab', '');
        if ($request->has('tab') && in_array($requestedTab, $legacyOperationTabs, true)) {
            return redirect()->route($this->canonicalProductionRouteName($production), $production);
        }

        $allowedTabs = ['genel', 'fotograflar', 'gecmis'];
        $activeTab = $request->has('tab') && in_array($requestedTab, $allowedTabs, true)
            ? $requestedTab
            : 'genel';

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
                ->filter(fn ($log) => $this->isProductionActionType((string) $log->action_type))
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

        $canonicalRouteName = $this->canonicalProductionRouteName($production);
        $canonicalActionLabel = $this->canonicalProductionActionLabel($production, $canonicalRouteName);
        $readiness = $this->readinessResolver->resolve($production);
        $detailPresentation = $this->buildProductionDetailPresentation(
            $production,
            $readiness,
            $qcUiEnabled,
            $canonicalRouteName,
            $canonicalActionLabel
        );

        // Detail is now a read-only decision surface; write actions live on canonical operation routes.
        $tabMetadata = [
            'genel' => ['label' => 'Genel Özet', 'icon' => 'dashboard'],
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
            'readiness' => $readiness,
            'detailPresentation' => $detailPresentation,
            'qcUiEnabled' => $qcUiEnabled,
            'canViewFinancialData' => $canViewFinancialData,
            'matchedCurrentAccount' => $matchedCurrentAccount,
            'subcontractorTransaction' => $subcontractorTransaction,
            'activeTab' => $activeTab,
            'allowedTabs' => $allowedTabs,
            'tabMetadata' => $tabMetadata,
            'tabUrl' => $tabUrl,
            'canonicalRouteName' => $canonicalRouteName,
            'canonicalActionLabel' => $canonicalActionLabel,
            'isInternalProduction' => $resolvedProductionType === OrderItemPrintProduction::TYPE_INTERNAL,
            'isExternalProduction' => $resolvedProductionType === OrderItemPrintProduction::TYPE_EXTERNAL,
            'isSubcontractedProduction' => $resolvedProductionType === OrderItemPrintProduction::TYPE_OUTSOURCED,
        ]);
    }

    public function operator(Request $request, OrderItemPrintProduction $production): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $production->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $production->loadMissing([
            'order.customer',
            'orderItem',
            'orderItemPrint.graphicOperation.latestAttachment',
            'graphicOperation.latestAttachment',
            'workForm.attachments.uploader',
            'workForm.procurement',
            'workForm.activityLogs.creator',
            'assignedUser',
        ]);

        $resolvedType = $production->production_type
            ?: OrderItemPrintProduction::normalizeProductionType($production->orderItemPrint?->production_type);

        abort_unless($resolvedType === OrderItemPrintProduction::TYPE_INTERNAL, 404);
        abort_unless($this->canUseInternalOperator($request, $tenant, $production), 403);

        $this->hydrateRuntimeSnapshot($production);

        $readiness = $this->readinessResolver->resolve($production);
        $snapshot = $production->production_snapshot ?? [];
        $history = $production->workForm?->activityLogs
            ?->filter(fn ($log) => $this->isProductionActionType((string) $log->action_type))
            ->sortByDesc('created_at')
            ->take(8)
            ->values() ?? collect();
        $productionPhotos = $production->workForm?->attachments
            ?->where('attachment_type', 'production_photo')
            ->sortByDesc('created_at')
            ->take(6)
            ->values() ?? collect();

        $users = User::query()
            ->whereHas('userRoles', function ($query) use ($tenant): void {
                $query->where('tenant_account_id', $tenant->id);
            })
            ->orderBy('name')
            ->get();

        return view('admin.productions.operator', [
            'production' => $production,
            'snapshot' => $snapshot,
            'readiness' => $readiness,
            'operatorAction' => $this->internalOperatorNextAction($production, $snapshot, $readiness),
            'history' => $history,
            'productionPhotos' => $productionPhotos,
            'users' => $users,
            'companies' => $this->eligibleProductionCompanies($tenant->id),
            'activityLabelResolver' => app(WorkFormActivityLabelResolver::class),
        ]);
    }
    public function subcontractAssignment(Request $request, OrderItemPrintProduction $production): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $production->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $production->loadMissing([
            'order.customer',
            'orderItem',
            'orderItemPrint.standardPrintType',
            'orderItemPrint.tenantPrintSetting.standardPrintType',
            'orderItemPrint.graphicOperation.latestAttachment',
            'graphicOperation.latestAttachment',
            'workForm.procurement',
            'workForm.activityLogs.creator',
            'productionCompany.companyRoles',
            'assignedUser',
        ]);

        $resolvedType = $this->resolvedProductionTypeFor($production);
        abort_unless(in_array($resolvedType, [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true), 404);
        abort_if($production->production_status === OrderItemPrintProduction::STATUS_CANCELLED, 404);

        $this->hydrateRuntimeSnapshot($production);

        $readiness = $this->readinessResolver->resolve($production);
        $snapshot = $production->production_snapshot ?? [];
        $history = $production->workForm?->activityLogs
            ?->filter(fn ($log) => $this->isProductionActionType((string) $log->action_type))
            ->sortByDesc('created_at')
            ->take(8)
            ->values() ?? collect();

        return view('admin.productions.subcontract-assignment', [
            'production' => $production,
            'snapshot' => $snapshot,
            'readiness' => $readiness,
            'history' => $history,
            'companies' => $this->eligibleProductionCompanies($tenant->id),
            'activityLabelResolver' => app(WorkFormActivityLabelResolver::class),
        ]);
    }
    public function subcontractTracking(Request $request, OrderItemPrintProduction $production): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $production->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $production->loadMissing([
            'order.customer',
            'orderItem',
            'orderItemPrint.standardPrintType',
            'orderItemPrint.tenantPrintSetting.standardPrintType',
            'orderItemPrint.graphicOperation.latestAttachment',
            'graphicOperation.latestAttachment',
            'workForm.attachments.uploader',
            'workForm.procurement',
            'workForm.activityLogs.creator',
            'productionCompany.companyRoles',
            'assignedUser',
        ]);

        $resolvedType = $this->resolvedProductionTypeFor($production);
        abort_unless(in_array($resolvedType, [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true), 404);
        abort_unless((bool) $production->production_company_id, 404);
        abort_unless(in_array($production->production_status, [
            OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
            OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
            OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
            OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
            OrderItemPrintProduction::STATUS_PROBLEMATIC,
            OrderItemPrintProduction::STATUS_COMPLETED,
        ], true), 404);

        $this->hydrateRuntimeSnapshot($production);

        $snapshot = $production->production_snapshot ?? [];
        $readiness = $this->readinessResolver->resolve($production);
        $history = $production->workForm?->activityLogs
            ?->filter(fn ($log) => $this->isProductionActionType((string) $log->action_type))
            ->sortByDesc('created_at')
            ->take(10)
            ->values() ?? collect();
        $productionPhotos = $production->workForm?->attachments
            ?->where('attachment_type', 'production_photo')
            ->sortByDesc('created_at')
            ->take(8)
            ->values() ?? collect();

        return view('admin.productions.subcontract-tracking', [
            'production' => $production,
            'snapshot' => $snapshot,
            'readiness' => $readiness,
            'history' => $history,
            'productionPhotos' => $productionPhotos,
            'receiptSummary' => $this->buildSubcontractReceiptSummary($production),
            'trackingAction' => $this->subcontractTrackingNextAction($production),
            'activityLabelResolver' => app(WorkFormActivityLabelResolver::class),
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
            'route_change_reason' => ['nullable', 'string', 'max:1000'],
            'subcontractor_cost' => ['nullable', 'numeric', 'min:0'],
            'subcontractor_cost_currency' => ['nullable', Rule::in(['TRY', 'USD', 'EUR'])],
            'subcontractor_cost_note' => ['nullable', 'string', 'max:1000'],
            'return_to' => ['nullable', Rule::in(['show', 'index', 'operator', 'subcontract_assignment', 'subcontract_tracking'])],
        ]);

        $canViewFinancialData = $request->user()?->canViewFinancialData($tenant->id) ?? false;

        if (!$canViewFinancialData && (
            $request->filled('subcontractor_cost')
            || $request->filled('subcontractor_cost_currency')
            || $request->filled('subcontractor_cost_note')
        )) {
            abort(403);
        }

        if (($validated['return_to'] ?? null) === 'subcontract_assignment' && (
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

        $assignmentNote = filled($validated['route_change_reason'] ?? null)
            ? trim((string) $validated['route_change_reason'])
            : (filled($validated['production_note'] ?? null) ? trim((string) $validated['production_note']) : null);

        $isSubcontractAssignmentReturn = ($validated['return_to'] ?? null) === 'subcontract_assignment';
        $resolvedType = $this->resolvedProductionTypeFor($production);
        $currentCompanyId = (int) ($production->production_company_id ?? 0);
        $companyWillChange = $currentCompanyId > 0 && $companyId > 0 && $currentCompanyId !== $companyId;

        if ($isSubcontractAssignmentReturn) {
            abort_if(in_array($production->production_status, [
                OrderItemPrintProduction::STATUS_COMPLETED,
                OrderItemPrintProduction::STATUS_CANCELLED,
            ], true), 404);
        }

        if ($companyWillChange && blank($assignmentNote)) {
            throw ValidationException::withMessages(['production_note' => 'Fason firma değişikliği için gerekçe zorunludur.']);
        }

        if ($companyWillChange && $production->production_status === OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR) {
            abort_unless($this->canManageProductionAssignment($request, $tenant), 403);
        }

        try {
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
                $assignmentNote
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['production_note' => $exception->getMessage()]);
        }

        $returnTo = $validated['return_to'] ?? 'show';
        $freshProduction = $production->fresh() ?? $production;
        $returnRoute = $this->productionReturnRouteName($freshProduction, $returnTo);
        $message = match (true) {
            $returnRoute === 'admin.productions.operator' && (int) ($freshProduction->assigned_to ?? 0) > 0 => 'Operatör atandı.',
            $returnRoute === 'admin.productions.subcontract-assignment' => 'Fason firma atandı.',
            default => 'Üretim ataması güncellendi.',
        };

        return redirect()
            ->route($returnRoute, $returnRoute === 'admin.productions.index' ? [] : $freshProduction)
            ->with('success', $message);
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
                'subcontract_completed',
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
            'return_to' => ['nullable', Rule::in(['show', 'index', 'operator', 'subcontract_assignment', 'subcontract_tracking'])],
        ]);

        try {
            $note = filled($validated['note'] ?? null) ? trim((string) $validated['note']) : null;

            match ($validated['action']) {
                'assign_internal' => $this->workflowService->markStarted($production, $request->user(), $note),
                'assign_external' => $this->workflowService->markStarted($production, $request->user(), $note),
                'sent_to_subcontractor' => $this->workflowService->markSentToSubcontractor($production, $request->user(), $note),
                'returned_from_subcontractor' => $this->workflowService->markReturnedFromSubcontractor($production, $request->user(), $note),
                'subcontract_completed' => $this->completeSubcontractReceipt($production, $request->user(), $note),
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
                'subcontract_completed' => 'action',
                default => 'action',
            };

            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }

        $returnTo = $validated['return_to'] ?? 'show';
        $freshProduction = $production->fresh() ?? $production;
        $returnRoute = $this->productionReturnRouteName($freshProduction, $returnTo);
        $message = match (true) {
            $validated['action'] === 'sent_to_subcontractor' => 'İş fason firmaya gönderildi.',
            $returnRoute === 'admin.productions.subcontract-tracking' => 'Fason takip kaydı güncellendi.',
            default => 'Üretim durumu güncellendi.',
        };

        return redirect()
            ->route($returnRoute, $returnRoute === 'admin.productions.index' ? [] : $freshProduction)
            ->with('success', $message);
    }

    private function completeSubcontractReceipt(OrderItemPrintProduction $production, ?User $user, ?string $note): OrderItemPrintProduction
    {
        if ($production->production_status !== OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR) {
            $production = $this->workflowService->markReturnedFromSubcontractor($production, $user, $note ?: 'Fason işin tamamı geldi.')->fresh();
        }

        return $this->workflowService->markCompleted($production, $user, $note ?: 'Fason işin tamamı teslim alındı.');
    }

    private function buildSubcontractReceiptSummary(OrderItemPrintProduction $production): array
    {
        $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
        $baseline = data_get($snapshot, 'subcontract_tracking.send_baseline');
        $hasBaseline = is_array($baseline)
            && array_key_exists('completed_quantity_before_send', $baseline)
            && array_key_exists('remaining_quantity_at_send', $baseline);

        $planned = round((float) $production->planned_quantity, 4);
        $completed = round((float) $production->completed_quantity, 4);
        $remaining = max(round((float) $production->remaining_quantity, 4), 0.0);

        if (!$hasBaseline) {
            return [
                'has_baseline' => false,
                'planned_quantity' => $planned,
                'completed_quantity' => $completed,
                'remaining_quantity' => $remaining,
                'prior_internal_completed_quantity' => null,
                'sent_quantity' => null,
                'received_from_subcontractor_quantity' => null,
                'remaining_from_subcontractor_quantity' => null,
                'warning' => 'Fason gönderim başlangıç miktarı bu geçmiş kayıt için ayrıştırılamadı. Gelen miktar tahmini gösterilmez.',
            ];
        }

        $priorInternal = max(round((float) $baseline['completed_quantity_before_send'], 4), 0.0);
        $sentQuantity = max(round((float) $baseline['remaining_quantity_at_send'], 4), 0.0);
        $received = max(round($completed - $priorInternal, 4), 0.0);
        $remainingFromSubcontractor = max(round($sentQuantity - $received, 4), 0.0);

        return [
            'has_baseline' => true,
            'planned_quantity' => $planned,
            'completed_quantity' => $completed,
            'remaining_quantity' => $remaining,
            'prior_internal_completed_quantity' => $priorInternal,
            'sent_quantity' => $sentQuantity,
            'received_from_subcontractor_quantity' => $received,
            'remaining_from_subcontractor_quantity' => $remainingFromSubcontractor,
            'captured_at' => $baseline['captured_at'] ?? null,
            'warning' => null,
        ];
    }

    private function subcontractTrackingNextAction(OrderItemPrintProduction $production): array
    {
        return match ($production->production_status) {
            OrderItemPrintProduction::STATUS_COMPLETED => ['type' => 'readonly', 'title' => 'Fason işi tamamlandı', 'label' => 'Tamamlanan Kaydı İncele', 'hint' => 'Gelen miktar akışı kapalı.'],
            OrderItemPrintProduction::STATUS_CANCELLED => ['type' => 'readonly', 'title' => 'Fason işi iptal edildi', 'label' => 'İptal Edildi', 'hint' => 'Bu kayıt için işlem yapılamaz.'],
            OrderItemPrintProduction::STATUS_PROBLEMATIC => ['type' => 'readonly', 'title' => 'Sorun kaydı var', 'label' => 'Sorun İncele', 'hint' => 'Yeni işlemden önce sorun notunu değerlendirin.'],
            default => ['type' => 'receipt', 'title' => 'Fason dönüşünü kaydet', 'label' => 'Gelen Bilgisi Gir', 'hint' => 'Tamamı geldi, kısmi geldi veya eksik/sorun bilgisini bu exact iş için kaydedin.'],
        };
    }
    private function normalizeProductionRouteFilter(array $filters): string
    {
        $route = trim((string) ($filters['route'] ?? ''));

        if ($route !== '') {
            return $route;
        }

        return match (trim((string) ($filters['pool'] ?? ''))) {
            'internal' => 'internal',
            'outsourced' => 'outsourced',
            'completed' => 'completed',
            default => 'all',
        };
    }

    private function normalizeProductionPerPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? 0);

        if (in_array($perPage, [10, 20, 50], true)) {
            return $perPage;
        }

        return 10;
    }

    private function applyProductionRouteFilter(Builder $query, string $route): void
    {
        match ($route) {
            'internal' => $query
                ->where(function (Builder $query): void {
                    $query->where('production_type', OrderItemPrintProduction::TYPE_INTERNAL)
                        ->orWhere(function (Builder $legacyQuery): void {
                            $legacyQuery->whereNull('production_type')
                                ->where(function (Builder $legacyPrintQuery): void {
                                    $legacyPrintQuery
                                        ->whereDoesntHave('orderItemPrint')
                                        ->orWhereHas('orderItemPrint', function (Builder $printQuery): void {
                                            $printQuery->where('production_type', OrderItemPrintProduction::TYPE_INTERNAL)
                                                ->orWhere('production_type', 'like', '%İç%')
                                                ->orWhere('production_type', 'like', '%ic%')
                                                ->orWhereNull('production_type')
                                                ->orWhere('production_type', '');
                                        });
                                });
                        });
                })
                ->where('production_status', '!=', OrderItemPrintProduction::STATUS_CANCELLED)
                ->where(function (Builder $query): void {
                    $query->where('production_status', '!=', OrderItemPrintProduction::STATUS_COMPLETED)
                        ->where('remaining_quantity', '>', 0);
                }),
            'outsourced' => $query
                ->where(function (Builder $query): void {
                    $query->whereIn('production_type', [
                            OrderItemPrintProduction::TYPE_EXTERNAL,
                            OrderItemPrintProduction::TYPE_OUTSOURCED,
                        ])
                        ->orWhere(function (Builder $legacyQuery): void {
                            $legacyQuery->whereNull('production_type')
                                ->whereHas('orderItemPrint', function (Builder $printQuery): void {
                                    $printQuery->whereIn('production_type', [
                                            OrderItemPrintProduction::TYPE_EXTERNAL,
                                            OrderItemPrintProduction::TYPE_OUTSOURCED,
                                        ])
                                        ->orWhere('production_type', 'like', '%Dış%')
                                        ->orWhere('production_type', 'like', '%dis%')
                                        ->orWhere('production_type', 'like', '%fason%');
                                });
                        });
                })
                ->where('production_status', '!=', OrderItemPrintProduction::STATUS_CANCELLED)
                ->where(function (Builder $query): void {
                    $query->where('production_status', '!=', OrderItemPrintProduction::STATUS_COMPLETED)
                        ->where('remaining_quantity', '>', 0);
                }),
            'supplier_printed' => $query->whereRaw('1 = 0'),
            'completed' => $query
                ->where('production_status', '!=', OrderItemPrintProduction::STATUS_CANCELLED)
                ->where(function (Builder $query): void {
                    $query->where('production_status', OrderItemPrintProduction::STATUS_COMPLETED)
                        ->orWhere('remaining_quantity', '<=', 0);
                }),
            'all' => null,
            default => null,
        };
    }

    private function applyProductionPoolDbFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->whereHas('order', fn (Builder $orderQuery) => $orderQuery->where('document_number', 'like', '%' . $search . '%'))
                    ->orWhereHas('workForm', fn (Builder $workFormQuery) => $workFormQuery->where('work_form_number', 'like', '%' . $search . '%'))
                    ->orWhereHas('order.customer', fn (Builder $customerQuery) => $customerQuery->where('legal_name', 'like', '%' . $search . '%')->orWhere('short_name', 'like', '%' . $search . '%'))
                    ->orWhereHas('orderItem', fn (Builder $itemQuery) => $itemQuery->where('product_name', 'like', '%' . $search . '%')->orWhere('product_code', 'like', '%' . $search . '%'))
                    ->orWhereHas('orderItemPrint', fn (Builder $printQuery) => $printQuery->where('print_type', 'like', '%' . $search . '%')->orWhere('print_option', 'like', '%' . $search . '%'));
            });
        }

        if (filled($filters['print_type'] ?? null)) {
            $printType = (string) $filters['print_type'];
            $query->whereHas('orderItemPrint', fn (Builder $printQuery) => $printQuery->where('print_type', $printType));
        }

        if (filled($filters['method'] ?? null)) {
            $this->applyProductionMethodFilter($query, (string) $filters['method']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('production_status', (string) $filters['status']);
        }

        if (filled($filters['type'] ?? null)) {
            $query->where('production_type', (string) $filters['type']);
        }

        if (filled($filters['operator'] ?? null)) {
            $query->where('assigned_to', (int) $filters['operator']);
        }

        if (filled($filters['subcontractor'] ?? null)) {
            $query->where('production_company_id', (int) $filters['subcontractor']);
        }

        if (filled($filters['cliche_status'] ?? null)) {
            $query->where('cliche_status', (string) $filters['cliche_status']);
        }

        if (filled($filters['qc_status'] ?? null)) {
            $query->where('qc_status', (string) $filters['qc_status']);
        }

        if (filled($filters['graphic_status'] ?? null)) {
            $graphicStatus = (string) $filters['graphic_status'];
            $query->whereHas('graphicOperation', fn (Builder $graphicQuery) => $graphicQuery->where('status', $graphicStatus));
        }

        if (filled($filters['procurement_status'] ?? null)) {
            $procurementStatus = (string) $filters['procurement_status'];
            $query->whereHas('workForm.procurement', fn (Builder $procurementQuery) => $procurementQuery->where('procurement_status', $procurementStatus));
        }

        if (($filters['graphic_ready'] ?? '') === 'evet') {
            $query->whereHas('graphicOperation', fn (Builder $graphicQuery) => $graphicQuery
                ->where('status', \App\Models\OrderItemPrintGraphic::STATUS_PRODUCTION_READY)
                ->whereNotNull('latest_attachment_id'));
        } elseif (($filters['graphic_ready'] ?? '') === 'hayir') {
            $query->whereDoesntHave('graphicOperation', fn (Builder $graphicQuery) => $graphicQuery
                ->where('status', \App\Models\OrderItemPrintGraphic::STATUS_PRODUCTION_READY)
                ->whereNotNull('latest_attachment_id'));
        }

        if (($filters['procurement_ready'] ?? '') === 'evet') {
            $query->whereHas('workForm.procurement', fn (Builder $procurementQuery) => $procurementQuery->whereIn('procurement_status', [
                \App\Models\OrderItemProcurement::STATUS_FULLY_RECEIVED,
                \App\Models\OrderItemProcurement::STATUS_NOT_REQUIRED,
                \App\Models\OrderItemProcurement::STATUS_CUSTOMER_RECEIVED,
            ]));
        } elseif (($filters['procurement_ready'] ?? '') === 'hayir') {
            $query->whereDoesntHave('workForm.procurement', fn (Builder $procurementQuery) => $procurementQuery->whereIn('procurement_status', [
                \App\Models\OrderItemProcurement::STATUS_FULLY_RECEIVED,
                \App\Models\OrderItemProcurement::STATUS_NOT_REQUIRED,
                \App\Models\OrderItemProcurement::STATUS_CUSTOMER_RECEIVED,
            ]));
        }

        match (trim((string) ($filters['pool'] ?? ''))) {
            'partial' => $query->where('completed_quantity', '>', 0)->where('remaining_quantity', '>', 0),
            'qc' => $query->where('production_status', OrderItemPrintProduction::STATUS_QUALITY_CONTROL),
            'preparation' => null,
            'ready' => null,
            default => null,
        };
    }

    private function applyProductionMethodFilter(Builder $query, string $method): void
    {
        [$scope, $value] = array_pad(explode(':', $method, 2), 2, null);

        if ($scope === 'standard' && (int) $value > 0) {
            $query->whereHas('orderItemPrint', fn (Builder $printQuery) => $printQuery->where('standard_print_type_id', (int) $value));
            return;
        }

        if ($scope === 'tenant' && (int) $value > 0) {
            $query->whereHas('orderItemPrint', fn (Builder $printQuery) => $printQuery->where('tenant_print_setting_id', (int) $value));
            return;
        }

        if ($scope === 'legacy' && filled($value)) {
            $query->whereHas('orderItemPrint', fn (Builder $printQuery) => $printQuery->where('print_type', 'like', '%' . str_replace('-', ' ', (string) $value) . '%'));
        }
    }

    private function applyProductionPoolSort(Builder $query, string $route): void
    {
        if ($route === 'completed') {
            $query->orderByRaw('completed_at IS NULL')
                ->orderByDesc('completed_at')
                ->orderByDesc('updated_at')
                ->orderByDesc('id');
            return;
        }

        $query->orderByRaw("CASE production_status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END", [
            OrderItemPrintProduction::STATUS_PROBLEMATIC,
            OrderItemPrintProduction::STATUS_PENDING,
            OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
        ])->orderByDesc('id');
    }

    private function buildProductionPoolRows(Collection $productions): Collection
    {
        return $productions->map(function (OrderItemPrintProduction $production): array {
            $this->hydrateRuntimeSnapshot($production);
            $readiness = $this->readinessResolver->resolve($production);
            $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
            $poolReadiness = $this->productionPoolReadiness($readiness);
            $method = $this->productionMethodGroup($production, $snapshot);
            $planned = (float) $production->planned_quantity;
            $completed = (float) $production->completed_quantity;
            $remaining = (float) $production->remaining_quantity;
            $progress = $planned > 0 ? min(100, max(0, round(($completed / $planned) * 100))) : 0;

            return [
                'production' => $production,
                'snapshot' => $snapshot,
                'readiness' => $readiness,
                'pool_readiness' => $poolReadiness,
                'method' => $method,
                'next_action' => $this->productionPoolNextAction($production, $snapshot, $readiness),
                'progress_percent' => $progress,
                'planned_quantity' => $planned,
                'completed_quantity' => $completed,
                'remaining_quantity' => $remaining,
            ];
        })->values();
    }

    private function buildProductionMethodGroups(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row) => $row['method']['key'])
            ->map(function (Collection $groupRows): array {
                $first = $groupRows->first();
                $readyCount = $groupRows->filter(fn (array $row) => (bool) data_get($row, 'readiness.can_start', false))->count();
                $progressCount = $groupRows->filter(fn (array $row) => in_array($row['production']->production_status, [
                    OrderItemPrintProduction::STATUS_INTERNAL,
                    OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
                    OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
                    OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
                    OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
                ], true))->count();
                $problemCount = $groupRows->filter(fn (array $row) => $row['production']->isProblematic())->count();

                return [
                    'key' => $first['method']['key'],
                    'label' => $first['method']['label'],
                    'tone' => $first['method']['tone'],
                    'rows' => $groupRows->values(),
                    'metrics' => [
                        'total' => $groupRows->count(),
                        'ready' => $readyCount,
                        'progress' => $progressCount,
                        'problem' => $problemCount,
                    ],
                ];
            })
            ->values();
    }

    private function productionMethodGroup(OrderItemPrintProduction $production, array $snapshot): array
    {
        $print = $production->orderItemPrint;
        $label = trim((string) ($print?->displayPrintType() ?: ($snapshot['print_type'] ?? 'Baskı Tekniği Belirtilmedi')));

        if ($print?->standard_print_type_id) {
            $key = 'standard:' . $print->standard_print_type_id;
        } elseif ($print?->tenant_print_setting_id) {
            $key = 'tenant:' . $print->tenant_print_setting_id;
        } else {
            $key = 'legacy:' . Str::slug(Str::ascii($label ?: 'legacy-print'), '-');
        }

        $tones = ['blue', 'green', 'sand', 'lavender'];
        $tone = $tones[abs(crc32($key)) % count($tones)];

        return [
            'key' => $key,
            'label' => $label !== '' ? $label : 'Baskı Tekniği Belirtilmedi',
            'tone' => $tone,
        ];
    }

    private function productionPoolNextAction(OrderItemPrintProduction $production, array $snapshot, array $readiness): array
    {
        $showUrl = route('admin.productions.show', $production);
        $subcontractUrl = route('admin.productions.subcontract-assignment', $production);
        $trackingUrl = route('admin.productions.subcontract-tracking', $production);
        $isExternal = in_array($production->production_type, [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true);

        if ($production->production_status === OrderItemPrintProduction::STATUS_COMPLETED || $production->remainingQuantity() <= 0.0) {
            return ['label' => 'Kaydı Aç', 'url' => $showUrl, 'hint' => 'Tamamlanan üretim kaydı'];
        }

        if ($production->production_status === OrderItemPrintProduction::STATUS_CANCELLED) {
            return ['label' => 'Kaydı Aç', 'url' => $showUrl, 'hint' => 'İptal edilen üretim kaydı'];
        }

        if ($production->isProblematic()) {
            return ['label' => 'Sorunu Aç', 'url' => route($this->canonicalProductionRouteName($production), $production), 'hint' => 'Sorun kaydı var'];
        }

        if ($production->production_status === OrderItemPrintProduction::STATUS_QUALITY_CONTROL) {
            return ['label' => 'Kalite Kontrolü Aç', 'url' => route($this->canonicalProductionRouteName($production), $production), 'hint' => 'QC sonucu bekleniyor'];
        }

        if ($isExternal && !$production->production_company_id) {
            return ['label' => 'Fason Ata', 'url' => $subcontractUrl, 'hint' => 'Fason firma seçilmedi'];
        }

        if (! (bool) ($readiness['graphic_ready'] ?? false)) {
            $graphic = $production->graphicOperation ?: $production->orderItemPrint?->graphicOperation;
            return [
                'label' => 'Grafiği Gör',
                'url' => $graphic ? route('admin.graphics.show', $graphic) : $showUrl,
                'hint' => $readiness['blocking_reason_label'] ?? 'Grafik henüz üretime hazır değil.',
            ];
        }

        if (! (bool) ($readiness['procurement_ready'] ?? false)) {
            $procurementUrl = $production->workForm?->procurement
                ? route('admin.procurements.show', $production->workForm->procurement)
                : $showUrl;
            return ['label' => 'Tedarik Durumunu Aç', 'url' => $procurementUrl, 'hint' => 'Malzeme/tedarik bekleniyor'];
        }

        if ($isExternal) {
            return match ($production->production_status) {
                OrderItemPrintProduction::STATUS_PENDING => ['label' => 'Fasona Gönder', 'url' => $subcontractUrl, 'hint' => 'Fason firma hazır'],
                OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR => ['label' => 'Fason Takip', 'url' => $trackingUrl, 'hint' => 'Fason dönüşü bekleniyor'],
                OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
                OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED => ['label' => 'Gelen İşi Kontrol Et', 'url' => $trackingUrl, 'hint' => 'Kısmi/gelen iş kontrolü'],
                default => ['label' => 'Kaydı Aç', 'url' => $showUrl, 'hint' => 'Üretim kaydını aç'],
            };
        }

        $operatorUrl = route('admin.productions.operator', $production);

        return match ($production->production_status) {
            OrderItemPrintProduction::STATUS_PENDING => ['label' => 'Üretimi Aç', 'url' => $operatorUrl, 'hint' => 'Başlamaya hazır'],
            OrderItemPrintProduction::STATUS_INTERNAL => ['label' => 'İşe Devam Et', 'url' => $operatorUrl, 'hint' => 'İç üretim devam ediyor'],
            OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED => ['label' => 'Kalanı Tamamla', 'url' => $operatorUrl, 'hint' => 'Kısmi üretim var'],
            default => ['label' => 'Kaydı Aç', 'url' => $showUrl, 'hint' => 'Üretim kaydını aç'],
        };
    }
    private function internalOperatorNextAction(OrderItemPrintProduction $production, array $snapshot, array $readiness): array
    {
        $showUrl = route('admin.productions.show', $production);

        if ($production->production_status === OrderItemPrintProduction::STATUS_COMPLETED || $production->remainingQuantity() <= 0.0) {
            return ['type' => 'readonly', 'label' => 'Tamamlandı', 'title' => 'Üretim tamamlandı', 'hint' => 'Bu exact baskı işi salt okunur.', 'url' => $showUrl];
        }

        if ($production->production_status === OrderItemPrintProduction::STATUS_CANCELLED) {
            return ['type' => 'readonly', 'label' => 'Kaydı Aç', 'title' => 'Üretim iptal edildi', 'hint' => 'İptal edilen kayıt salt okunur.', 'url' => $showUrl];
        }

        if ($production->isProblematic()) {
            return ['type' => 'readonly', 'label' => 'Sorunu İncele', 'title' => 'Sorun/QC incelemesi gerekiyor', 'hint' => 'Sorun notunu ve geçmişi bu operator ekranında kontrol edin.', 'url' => route('admin.productions.operator', $production)];
        }

        if ($production->production_status === OrderItemPrintProduction::STATUS_QUALITY_CONTROL) {
            return ['type' => 'readonly', 'label' => 'Kalite Kontrolünü Aç', 'title' => 'Kalite kontrol sonucu bekleniyor', 'hint' => 'QC geçmişini ve son durumu bu operator ekranında kontrol edin.', 'url' => route('admin.productions.operator', $production)];
        }

        if (! (bool) ($readiness['graphic_ready'] ?? false)) {
            $graphic = $readiness['graphic_operation'] ?? ($production->graphicOperation ?: $production->orderItemPrint?->graphicOperation);

            return [
                'type' => 'link',
                'label' => 'Grafiği Aç',
                'title' => 'Grafik üretime hazır değil',
                'hint' => $readiness['blocking_reason_label'] ?? 'Final grafik onayı bekleniyor.',
                'url' => $graphic ? route('admin.graphics.show', $graphic) : $showUrl,
            ];
        }

        if (! (bool) ($readiness['procurement_ready'] ?? false)) {
            return [
                'type' => 'link',
                'label' => 'Tedarik Durumunu Aç',
                'title' => 'Malzeme tedariki bekleniyor',
                'hint' => $readiness['blocking_reason_label'] ?? 'Ürün/malzeme hazır olmadan üretime başlanamaz.',
                'url' => $production->workForm?->procurement ? route('admin.procurements.show', $production->workForm->procurement) : $showUrl,
            ];
        }

        if ($production->production_status === OrderItemPrintProduction::STATUS_PENDING && !$production->assigned_to) {
            return [
                'type' => 'assign_operator',
                'label' => 'Operatör Seç',
                'title' => 'Operatör seçimi gerekli',
                'hint' => 'Üretime başlamadan önce bu exact baskı işine operatör atanmalı.',
                'url' => route('admin.productions.operator', $production) . '#operator-assignment-panel',
            ];
        }

        if ($production->production_status === OrderItemPrintProduction::STATUS_PENDING) {
            return ['type' => 'start', 'label' => 'Üretime Başla', 'title' => 'İşe başlanabilir', 'hint' => 'Operatör, grafik ve tedarik kontrolleri tamam.', 'unit_name' => $production->production_unit_name ?: 'İç üretim'];
        }

        if ($production->production_status === OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED) {
            return ['type' => 'result', 'label' => 'Kalanı Tamamla', 'title' => 'Kalan üretimi tamamla', 'hint' => 'Kısmi üretim kaydı var; kalan adet üzerinden devam edin.'];
        }

        return ['type' => 'result', 'label' => 'Üretim Sonucu Gir', 'title' => 'Üretim sonucu gir', 'hint' => 'Kısmi basılan adedi, tamamlanmayı veya sorunu kaydedin.'];
    }

    private function canonicalProductionRouteName(OrderItemPrintProduction $production): string
    {
        if ($production->production_status === OrderItemPrintProduction::STATUS_COMPLETED
            || $production->production_status === OrderItemPrintProduction::STATUS_CANCELLED
            || $production->remainingQuantity() <= 0.0) {
            return 'admin.productions.show';
        }

        $resolvedType = $this->resolvedProductionTypeFor($production);

        if ($resolvedType === OrderItemPrintProduction::TYPE_INTERNAL) {
            return 'admin.productions.operator';
        }

        if (in_array($resolvedType, [
            OrderItemPrintProduction::TYPE_EXTERNAL,
            OrderItemPrintProduction::TYPE_OUTSOURCED,
        ], true)) {
            $trackingStatuses = [
                OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
                OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
                OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
                OrderItemPrintProduction::STATUS_PROBLEMATIC,
            ];
            $partialAfterSend = $production->production_status === OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED
                && filled($production->sent_to_subcontractor_at);

            if ((int) ($production->production_company_id ?? 0) > 0
                && ($partialAfterSend || in_array($production->production_status, $trackingStatuses, true))) {
                return 'admin.productions.subcontract-tracking';
            }

            return 'admin.productions.subcontract-assignment';
        }

        return 'admin.productions.show';
    }

    private function canonicalProductionActionLabel(OrderItemPrintProduction $production, string $routeName): string
    {
        return match ($routeName) {
            'admin.productions.operator' => 'Operatör Ekranını Aç',
            'admin.productions.subcontract-assignment' => (int) ($production->production_company_id ?? 0) > 0 ? 'Fason Atamayı Aç' : 'Fason Firma Ata',
            'admin.productions.subcontract-tracking' => 'Fason Takibi Aç',
            default => 'Kaydı İncele',
        };
    }

    private function productionReturnRouteName(OrderItemPrintProduction $production, ?string $returnTo): string
    {
        if ($returnTo === 'index') {
            return 'admin.productions.index';
        }

        return $this->canonicalProductionRouteName($production);
    }
    private function resolvedProductionTypeFor(OrderItemPrintProduction $production): ?string
    {
        return OrderItemPrintProduction::normalizeProductionType($production->production_type)
            ?? OrderItemPrintProduction::normalizeProductionType($production->orderItemPrint?->production_type);
    }

    private function canManageProductionAssignment(Request $request, TenantAccount $tenant): bool
    {
        $user = $request->user();

        return $user?->belongsToTenant($tenant) === true
            && $user->hasAnyPermissionInTenant(['edit_orders', 'manage_stock'], $tenant->id);
    }
    private function canUseInternalOperator(Request $request, TenantAccount $tenant, OrderItemPrintProduction $production): bool
    {
        $user = $request->user();

        if (!$user || !$user->belongsToTenant($tenant)) {
            return false;
        }

        if ((int) $production->assigned_to > 0 && (int) $production->assigned_to === (int) $user->id) {
            return true;
        }

        return $user->hasAnyPermissionInTenant(['edit_orders', 'manage_stock'], $tenant->id);
    }
    private function buildProductionDetailPresentation(
        OrderItemPrintProduction $production,
        array $readiness,
        bool $qcUiEnabled,
        string $canonicalRouteName,
        string $canonicalActionLabel
    ): array {
        $snapshot = is_array($production->production_snapshot) ? $production->production_snapshot : [];
        $planned = (float) $production->planned_quantity;
        $completed = (float) $production->completed_quantity;
        $remaining = max((float) $production->remaining_quantity, 0.0);
        $isCompleted = $production->production_status === OrderItemPrintProduction::STATUS_COMPLETED || $remaining <= 0.0;
        $isCancelled = $production->production_status === OrderItemPrintProduction::STATUS_CANCELLED;
        $isProblematic = $production->isProblematic();
        $progress = $planned > 0 ? min(100, max(0, round(($completed / $planned) * 100))) : ($isCompleted ? 100 : 0);
        $resolvedType = $this->resolvedProductionTypeFor($production);
        $isOutsourced = in_array($resolvedType, [OrderItemPrintProduction::TYPE_EXTERNAL, OrderItemPrintProduction::TYPE_OUTSOURCED], true);

        $graphicRequired = (bool) ($readiness['graphic_required'] ?? true);
        $graphicReady = $isCompleted || !$graphicRequired || (bool) ($readiness['graphic_ready'] ?? false);
        $procurementStatus = (string) ($readiness['procurement_status'] ?? '');
        $procurementReady = $isCompleted || (bool) ($readiness['procurement_ready'] ?? false);
        $procurementNotRequired = in_array($procurementStatus, ['tedarik_gerekmiyor', 'not_required'], true)
            || (($readiness['procurement_status_label'] ?? null) === 'Tedarik Gerekli Değil');

        $qcRequired = $qcUiEnabled && (
            $production->production_status === OrderItemPrintProduction::STATUS_QUALITY_CONTROL
            || $production->qc_status === OrderItemPrintProduction::QC_OK
            || $production->qc_status === OrderItemPrintProduction::QC_PROBLEMATIC
            || filled($production->qc_started_at)
        );
        $qcPassed = !$qcRequired || $production->qc_status === OrderItemPrintProduction::QC_OK || ($isCompleted && !$isProblematic);
        $deliveryReady = $isCompleted && !$isProblematic && $qcPassed;

        $steps = [];
        $steps[] = $this->productionDetailStep(
            'Grafik',
            !$graphicRequired ? 'not_required' : ($graphicReady ? 'done' : 'blocked'),
            !$graphicRequired ? 'Gerekli Değil' : ($graphicReady ? 'Hazır' : (string) (($readiness['graphic_status_label'] ?? null) ?: 'Bekliyor'))
        );
        $steps[] = $this->productionDetailStep(
            'Tedarik',
            $procurementNotRequired ? 'not_required' : ($procurementReady ? 'done' : 'blocked'),
            $procurementNotRequired ? 'Gerekli Değil' : ($procurementReady ? 'Tamamlandı' : 'Bekliyor')
        );

        if ($isOutsourced) {
            $steps[] = $this->productionDetailStep(
                'Fason Atama',
                (int) ($production->production_company_id ?? 0) > 0 || $isCompleted ? 'done' : 'current',
                (int) ($production->production_company_id ?? 0) > 0 || $isCompleted ? 'Tamamlandı' : 'Bekliyor'
            );
            $steps[] = $this->productionDetailStep(
                'Fasona Gönderildi',
                filled($production->sent_to_subcontractor_at) || in_array($production->production_status, [
                    OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR,
                    OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
                    OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
                    OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
                    OrderItemPrintProduction::STATUS_COMPLETED,
                ], true) ? 'done' : 'future',
                filled($production->sent_to_subcontractor_at) || $isCompleted ? 'Tamamlandı' : 'Bekliyor'
            );
            $steps[] = $this->productionDetailStep(
                'Fasondan Geldi',
                $isCompleted || filled($production->returned_from_subcontractor_at) || in_array($production->production_status, [
                    OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR,
                    OrderItemPrintProduction::STATUS_QUALITY_CONTROL,
                ], true) ? 'done' : (filled($production->sent_to_subcontractor_at) ? 'current' : 'future'),
                $isCompleted || filled($production->returned_from_subcontractor_at) ? 'Tamamlandı' : (filled($production->sent_to_subcontractor_at) ? 'Devam Ediyor' : 'Bekliyor')
            );
        } else {
            $steps[] = $this->productionDetailStep(
                'İç Baskı',
                $isCompleted ? 'done' : (in_array($production->production_status, [
                    OrderItemPrintProduction::STATUS_INTERNAL,
                    OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
                ], true) ? 'current' : 'future'),
                $isCompleted ? 'Tamamlandı' : (in_array($production->production_status, [
                    OrderItemPrintProduction::STATUS_INTERNAL,
                    OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
                ], true) ? 'Devam Ediyor' : 'Bekliyor')
            );
        }

        if ($qcRequired) {
            $steps[] = $this->productionDetailStep(
                'Kalite Kontrol',
                match ($production->qc_status) {
                    OrderItemPrintProduction::QC_OK => 'done',
                    OrderItemPrintProduction::QC_PROBLEMATIC => 'blocked',
                    default => $production->production_status === OrderItemPrintProduction::STATUS_QUALITY_CONTROL ? 'current' : 'future',
                },
                match ($production->qc_status) {
                    OrderItemPrintProduction::QC_OK => 'Geçti',
                    OrderItemPrintProduction::QC_PROBLEMATIC => 'Sorunlu',
                    default => $production->production_status === OrderItemPrintProduction::STATUS_QUALITY_CONTROL ? 'Devam Ediyor' : 'Bekliyor',
                }
            );
        }

        $steps[] = $this->productionDetailStep(
            'Teslimata Hazır',
            $deliveryReady ? 'done' : ($isCompleted && $isProblematic ? 'blocked' : 'future'),
            $deliveryReady ? 'Hazır' : ($isCompleted && $isProblematic ? 'Sorunlu' : 'Bekliyor')
        );

        $next = [
            'label' => $canonicalActionLabel,
            'url' => route($canonicalRouteName, $production),
            'message' => $this->nextActionLabel($production),
            'show_cta' => !$isCompleted && !$isCancelled,
        ];

        if (!$next['show_cta']) {
            $next['message'] = $isCancelled ? 'Üretim iptal edildi.' : 'Üretim tamamlandı.';
        } elseif (!$graphicReady && $production->orderItemPrint?->graphicOperation) {
            $next = [
                'label' => 'Grafiği Aç',
                'url' => route('admin.graphics.show', $production->orderItemPrint->graphicOperation),
                'message' => 'Üretim için exact grafik kararını tamamla.',
                'show_cta' => true,
            ];
        } elseif (!$procurementReady && $production->workForm?->procurement) {
            $next = [
                'label' => 'Tedarik Durumunu Aç',
                'url' => route('admin.procurements.show', $production->workForm->procurement),
                'message' => 'Üretim öncesi tedarik durumunu tamamla.',
                'show_cta' => true,
            ];
        } elseif (!($readiness['can_start'] ?? false) && filled($readiness['blocking_reason_label'] ?? null)) {
            $next = [
                'label' => $canonicalActionLabel,
                'url' => route($canonicalRouteName, $production),
                'message' => (string) $readiness['blocking_reason_label'],
                'show_cta' => false,
            ];
        }

        $finalGraphic = $readiness['final_graphic_attachment'] ?? null;

        return [
            'status_label' => $isCompleted ? 'Tamamlandı' : ($isCancelled ? 'İptal' : $production->safeStatusLabel()),
            'status_tone' => $isCompleted ? 'green' : ($isCancelled ? 'gray' : ($isProblematic ? 'red' : 'blue')),
            'progress_percent' => $isCompleted ? 100 : $progress,
            'planned' => $planned,
            'completed' => $isCompleted && $completed < $planned ? $planned : $completed,
            'remaining' => $isCompleted ? 0.0 : $remaining,
            'unit' => $snapshot['unit'] ?? ($production->orderItem?->unit ?: 'Adet'),
            'steps' => $steps,
            'next' => $next,
            'qc_required' => $qcRequired,
            'qc_source' => $qcUiEnabled ? 'tenant/module quality_control enabled' : 'tenant/module quality_control disabled or unavailable',
            'graphic_label' => !$graphicRequired ? 'Grafik Gerekli Değil' : ($graphicReady ? 'Grafik Hazır' : (string) (($readiness['graphic_status_label'] ?? null) ?: 'Grafik Bekliyor')),
            'procurement_label' => $procurementNotRequired ? 'Tedarik Gerekli Değil' : ($procurementReady ? 'Tedarik Tamamlandı' : 'Tedarik Bekliyor'),
            'product_image_url' => data_get($snapshot, 'product_image_url'),
            'graphic_url' => $finalGraphic ? route('admin.work-forms.attachments.preview', $finalGraphic) : null,
            'graphic_is_image' => $finalGraphic?->isImage() ?? false,
        ];
    }

    private function productionDetailStep(string $label, string $state, string $status): array
    {
        return [
            'label' => $label,
            'state' => $state,
            'status' => $status,
        ];
    }
    private function productionPoolReadiness(array $readiness): array
    {
        $graphicReady = (bool) ($readiness['graphic_ready'] ?? false);
        $graphicRequired = (bool) ($readiness['graphic_required'] ?? true);
        $procurementReady = (bool) ($readiness['procurement_ready'] ?? false);
        $procurementStatus = (string) ($readiness['procurement_status'] ?? '');

        if (!$graphicRequired) {
            $graphicLabel = 'Grafik Gerekli Değil';
            $graphicTone = 'gray';
        } elseif ($graphicReady) {
            $graphicLabel = 'Grafik Hazır';
            $graphicTone = 'green';
        } else {
            $graphicLabel = (string) (($readiness['graphic_status_label'] ?? null) ?: 'Grafik Bekliyor');
            $graphicTone = in_array($graphicLabel, ['Final Görsel Yok', 'Revize Bekliyor'], true) ? 'red' : 'amber';
        }

        if ($procurementReady) {
            $procurementLabel = in_array($procurementStatus, ['tedarik_gerekmiyor'], true)
                ? 'Tedarik Gerekli Değil'
                : 'Tedarik Tamamlandı';
            $procurementTone = $procurementStatus === 'tedarik_gerekmiyor' ? 'gray' : 'green';
        } else {
            $procurementLabel = 'Tedarik Bekliyor';
            $procurementTone = 'amber';
        }

        return [
            'graphic_label' => $graphicLabel,
            'graphic_tone' => $graphicTone,
            'procurement_label' => $procurementLabel,
            'procurement_tone' => $procurementTone,
        ];
    }
    private function buildProductionRouteTabs(TenantAccount $tenant, array $filters): array
    {
        $base = OrderItemPrintProduction::query()->where('tenant_account_id', $tenant->id);
        $tabs = [
            'internal' => 'İç Baskı',
            'outsourced' => 'Dış Baskı / Fason',
            'supplier_printed' => 'Tedarikçiden Baskılı',
            'completed' => 'Tamamlananlar',
            'all' => 'Tümü',
        ];

        return collect($tabs)->map(function (string $label, string $route) use ($base, $filters): array {
            $countQuery = clone $base;
            $this->applyProductionRouteFilter($countQuery, $route);
            $tabFilters = array_merge($filters, ['route' => $route, 'page' => null]);
            unset($tabFilters['pool']);

            return [
                'key' => $route,
                'label' => $label,
                'count' => $route === 'supplier_printed' ? 0 : $countQuery->count(),
                'url' => route('admin.productions.index', array_filter($tabFilters, fn ($value) => $value !== null && $value !== '')),
            ];
        })->values()->all();
    }

    private function buildProductionMethodOptions(TenantAccount $tenant, string $activeRoute): Collection
    {
        $query = OrderItemPrintProduction::query()
            ->where('tenant_account_id', $tenant->id)
            ->with(['orderItemPrint.standardPrintType', 'orderItemPrint.tenantPrintSetting.standardPrintType'])
            ->limit(500);

        $this->applyProductionRouteFilter($query, $activeRoute);

        return $query->get()
            ->map(function (OrderItemPrintProduction $production): array {
                return $this->productionMethodGroup($production, []);
            })
            ->unique('key')
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function buildProductionPoolSummaryCards(TenantAccount $tenant, bool $qcUiEnabled): array
    {
        $active = fn () => OrderItemPrintProduction::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('production_status', '!=', OrderItemPrintProduction::STATUS_CANCELLED)
            ->where('production_status', '!=', OrderItemPrintProduction::STATUS_COMPLETED)
            ->where('remaining_quantity', '>', 0);

        $cards = [
            ['label' => 'İç Baskı Bekleyen', 'value' => (clone $active())->where('production_type', OrderItemPrintProduction::TYPE_INTERNAL)->count()],
            ['label' => 'Dış Baskı / Fason', 'value' => (clone $active())->whereIn('production_type', [OrderItemPrintProduction::TYPE_EXTERNAL, OrderItemPrintProduction::TYPE_OUTSOURCED])->count()],
            ['label' => 'Devam Eden', 'value' => OrderItemPrintProduction::query()->where('tenant_account_id', $tenant->id)->whereIn('production_status', [OrderItemPrintProduction::STATUS_INTERNAL, OrderItemPrintProduction::STATUS_SENT_TO_SUBCONTRACTOR, OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED, OrderItemPrintProduction::STATUS_RETURNED_FROM_SUBCONTRACTOR])->count()],
            ['label' => 'Sorunlu', 'value' => OrderItemPrintProduction::query()->where('tenant_account_id', $tenant->id)->where(function (Builder $query): void { $query->where('production_status', OrderItemPrintProduction::STATUS_PROBLEMATIC)->orWhere('qc_status', OrderItemPrintProduction::QC_PROBLEMATIC); })->count()],
            ['label' => 'Bugün Tamamlanan', 'value' => OrderItemPrintProduction::query()->where('tenant_account_id', $tenant->id)->whereDate('completed_at', now()->toDateString())->count()],
        ];

        if ($qcUiEnabled) {
            $cards[] = ['label' => 'Kalite Kontrol', 'value' => OrderItemPrintProduction::query()->where('tenant_account_id', $tenant->id)->where('production_status', OrderItemPrintProduction::STATUS_QUALITY_CONTROL)->count()];
        }

        return $cards;
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
            'production_route_changed',
            'production_subcontractor_reassigned',
        ];
    }

    private function isProductionActionType(string $actionType): bool
    {
        $key = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($actionType)));
        $key = trim($key, '_');

        return in_array($key, $this->productionActionTypes(), true);
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
            'ready' => (bool) ($readiness['can_start'] ?? false),
            'internal' => $production->production_type === OrderItemPrintProduction::TYPE_INTERNAL,
            'outsourced' => in_array($production->production_type, [
                OrderItemPrintProduction::TYPE_EXTERNAL,
                OrderItemPrintProduction::TYPE_OUTSOURCED,
            ], true),
            'preparation' => !(bool) ($readiness['can_start'] ?? false),
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
