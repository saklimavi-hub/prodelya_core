<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\TenantPackageUpgradeRequest;
use App\Services\SuperAdminOperationAuditService;
use App\Services\TenantUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantPackageUpgradeRequestController extends Controller
{
    public function __construct(
        protected TenantUsageService $tenantUsageService,
        protected SuperAdminOperationAuditService $operationAuditService,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search'));
        $status = trim((string) $request->input('status'));
        $package = trim((string) $request->input('package'));

        $baseQuery = TenantPackageUpgradeRequest::query();
        $requests = (clone $baseQuery)
            ->with(['tenant', 'requester', 'approver', 'currentPackage', 'requestedPackage'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->whereHas('tenant', function ($tenantQuery) use ($search): void {
                        $tenantQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('slug', 'like', '%' . $search . '%');
                    })->orWhereHas('requester', function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($package !== '', fn ($query) => $query->where('requested_package_key', $package))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $all = (clone $baseQuery)->get(['status', 'requested_package_key']);
        $topPackage = $all->pluck('requested_package_key')->filter()->countBy()->sortDesc()->keys()->first();
        $filteredCount = $requests->total();
        $newCount = $all->where('status', TenantPackageUpgradeRequest::STATUS_NEW)->count();
        $approvedCount = $all->where('status', TenantPackageUpgradeRequest::STATUS_APPROVED)->count();
        $completedCount = $all->where('status', TenantPackageUpgradeRequest::STATUS_COMPLETED)->count();

        return view('super-admin.package-requests.index', [
            'requests' => $requests,
            'filters' => compact('search', 'status', 'package'),
            'statusOptions' => TenantPackageUpgradeRequest::statusOptions(),
            'packages' => Package::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(),
            'summaryCards' => [
                ['label' => 'Toplam Talep', 'value' => $all->count()],
                ['label' => 'Yeni Talepler', 'value' => $newCount],
                ['label' => 'Onaylananlar', 'value' => $approvedCount],
                ['label' => 'Tamamlananlar', 'value' => $completedCount],
                ['label' => 'En Çok İstenen Paket', 'value' => $topPackage ?: 'Veri yok'],
            ],
            'sideSummary' => [
                'new_count' => $newCount,
                'approved_count' => $approvedCount,
                'completed_count' => $completedCount,
                'top_package' => $topPackage ?: 'Veri yok',
                'filtered_count' => $filteredCount,
            ],
        ]);
    }

    public function show(TenantPackageUpgradeRequest $packageRequest): View
    {
        $packageRequest->load(['tenant.package', 'requester', 'approver', 'currentPackage', 'requestedPackage']);

        $usage = collect($this->tenantUsageService->getUsageSnapshot($packageRequest->tenant))
            ->filter(fn ($item) => is_array($item) && in_array($item['key'] ?? null, ['users', 'products', 'supplier_feeds', 'orders'], true))
            ->map(function (array $item): array {
                return [
                    'key' => $item['key'],
                    'label' => $item['label'] ?? ($item['key'] ?? '-'),
                    'used_label' => (string) ($item['current'] ?? 0),
                    'limit_label' => ($item['limit'] ?? null) === null ? 'Sınırsız' : (string) $item['limit'],
                    'status_label' => match ($item['status'] ?? 'ok') {
                        'warning' => 'Uyarı',
                        'exceeded' => 'Limit Aşıldı',
                        'unlimited' => 'Sınırsız',
                        default => 'Normal',
                    },
                ];
            })
            ->values()
            ->all();

        $statusOptions = TenantPackageUpgradeRequest::statusOptions();
        $currentPackageLabel = $packageRequest->currentPackage?->name ?? ($packageRequest->current_package_key ?: '-');
        $requestedPackageLabel = $packageRequest->requestedPackage?->name ?? ($packageRequest->requested_package_key ?: '-');
        $tenantReady = filled($packageRequest->tenant?->panel_subdomain) && $packageRequest->tenant?->status === 'active';
        return view('super-admin.package-requests.show', [
            'packageRequest' => $packageRequest,
            'statusOptions' => $statusOptions,
            'usageSummary' => $usage,
            'canApply' => $packageRequest->status === TenantPackageUpgradeRequest::STATUS_APPROVED,
            'decisionSummary' => [
                'status_label' => $statusOptions[$packageRequest->status] ?? $packageRequest->status,
                'current_package_label' => $currentPackageLabel,
                'requested_package_label' => $requestedPackageLabel,
                'tenant_ready' => $tenantReady,
                'usage_count' => count($usage),
            ],
            'timeline' => $this->operationAuditService->packageRequestTimeline($packageRequest),
        ]);
    }

    public function updateStatus(Request $request, TenantPackageUpgradeRequest $packageRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(TenantPackageUpgradeRequest::statusOptions()))],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($packageRequest->status === TenantPackageUpgradeRequest::STATUS_COMPLETED
            && $validated['status'] !== TenantPackageUpgradeRequest::STATUS_COMPLETED) {
            return redirect()
                ->route('admin.super.package-requests.show', $packageRequest)
                ->with('error', 'Tamamlanan talep daha düşük bir duruma alınamaz.');
        }

        $from = (string) $packageRequest->status;

        $payload = [
            'status' => $validated['status'],
            'admin_note' => trim((string) ($validated['admin_note'] ?? '')),
        ];

        if ($validated['status'] === TenantPackageUpgradeRequest::STATUS_APPROVED) {
            $payload['approved_by_user_id'] = $request->user()->id;
            $payload['approved_at'] = now();
        }

        $packageRequest->update($payload);

        $this->operationAuditService->logPackageRequestStatusChanged(
            $packageRequest->fresh(),
            $request->user(),
            $from,
            (string) $validated['status'],
            $payload['admin_note']
        );

        return redirect()
            ->route('admin.super.package-requests.show', $packageRequest)
            ->with('success', 'Paket talebi durumu güncellendi.');
    }

    public function apply(Request $request, TenantPackageUpgradeRequest $packageRequest): RedirectResponse
    {
        if ($packageRequest->status !== TenantPackageUpgradeRequest::STATUS_APPROVED) {
            return redirect()
                ->route('admin.super.package-requests.show', $packageRequest)
                ->with('error', 'Paket uygulama için talebin önce onaylanması gerekir.');
        }

        $requestedPackage = Package::query()
            ->where('key', $packageRequest->requested_package_key)
            ->where('status', 'active')
            ->first();

        if (! $requestedPackage) {
            return redirect()
                ->route('admin.super.package-requests.show', $packageRequest)
                ->with('error', 'Talep edilen paket artık aktif değil. Manuel kontrol gerekiyor.');
        }

        DB::transaction(function () use ($request, $packageRequest, $requestedPackage): void {
            $tenant = $packageRequest->tenant()->lockForUpdate()->firstOrFail();
            $tenant->update([
                'package_key' => $requestedPackage->key,
            ]);

            $packageRequest->update([
                'status' => TenantPackageUpgradeRequest::STATUS_COMPLETED,
                'approved_by_user_id' => $packageRequest->approved_by_user_id ?: $request->user()->id,
                'approved_at' => $packageRequest->approved_at ?: now(),
                'applied_at' => now(),
            ]);

            $this->operationAuditService->logPackageRequestApplied(
                $packageRequest->fresh(),
                $tenant->fresh(),
                $requestedPackage,
                $request->user()
            );
        });

        return redirect()
            ->route('admin.super.package-requests.show', $packageRequest)
            ->with('success', 'Paket talebi uygulandı ve tenant paketi güncellendi.');
    }
}
