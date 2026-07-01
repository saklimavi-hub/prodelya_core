<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\TenantPackageUpgradeRequest;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantPackageRequestController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
    ) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $requests = TenantPackageUpgradeRequest::query()
            ->with(['requestedPackage', 'requester', 'approver'])
            ->where('tenant_account_id', $tenant->id)
            ->latest()
            ->get();

        $packages = Package::query()
            ->where('status', 'active')
            ->where('key', '!=', $tenant->package_key)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.package-requests.index', [
            'tenant' => $tenant,
            'requests' => $requests,
            'packages' => $packages,
            'summaryCards' => [
                ['label' => 'Toplam Talep', 'value' => $requests->count()],
                ['label' => 'Yeni', 'value' => $requests->where('status', TenantPackageUpgradeRequest::STATUS_NEW)->count()],
                ['label' => 'Onaylandı', 'value' => $requests->where('status', TenantPackageUpgradeRequest::STATUS_APPROVED)->count()],
                ['label' => 'Tamamlandı', 'value' => $requests->where('status', TenantPackageUpgradeRequest::STATUS_COMPLETED)->count()],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $validated = $request->validate([
            'requested_package_key' => [
                'required',
                'string',
                Rule::exists('packages', 'key')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'request_note' => ['nullable', 'string', 'max:2000'],
        ], [
            'requested_package_key.exists' => 'Yalnız aktif paketler talep edilebilir.',
        ]);

        if ($validated['requested_package_key'] === $tenant->package_key) {
            return redirect()
                ->route('admin.package-requests.index')
                ->withErrors(['requested_package_key' => 'Mevcut paket için yeniden talep oluşturulamaz.'])
                ->withInput();
        }

        $hasOpenRequest = TenantPackageUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('requested_package_key', $validated['requested_package_key'])
            ->whereIn('status', [
                TenantPackageUpgradeRequest::STATUS_NEW,
                TenantPackageUpgradeRequest::STATUS_APPROVED,
            ])
            ->exists();

        if ($hasOpenRequest) {
            return redirect()
                ->route('admin.package-requests.index')
                ->withErrors(['requested_package_key' => 'Bu paket için bekleyen bir talep zaten var.'])
                ->withInput();
        }

        TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $tenant->id,
            'requested_by_user_id' => $request->user()->id,
            'current_package_key' => $tenant->package_key,
            'requested_package_key' => $validated['requested_package_key'],
            'status' => TenantPackageUpgradeRequest::STATUS_NEW,
            'request_note' => trim((string) ($validated['request_note'] ?? '')),
            'meta_json' => [
                'submitted_host' => $request->getHost(),
                'submitted_route' => 'admin.package-requests.store',
            ],
        ]);

        return redirect()
            ->route('admin.package-requests.index')
            ->with('success', 'Paket talebiniz Super Admin onayına gönderildi.');
    }
}
