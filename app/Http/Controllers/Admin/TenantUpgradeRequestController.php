<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Tenant\TenantUpgradeRequestFormOptionsService;
use App\Services\Tenant\TenantUpgradeRequestService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantUpgradeRequestController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantUpgradeRequestFormOptionsService $formOptionsService,
        protected TenantUpgradeRequestService $upgradeRequestService,
    ) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $selectedType = (string) ($request->query('type', old('request_type', 'package_upgrade')));

        return view('admin.upgrade-requests.index', [
            'tenant' => $tenant,
            'overview' => $this->formOptionsService->build($tenant, $selectedType),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        try {
            $this->upgradeRequestService->createRequest($request->all(), $tenant, $request->user());
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return redirect()
            ->route('admin.upgrade-requests.index', ['type' => $request->input('request_type')])
            ->with('success', 'Talebiniz alındı ve inceleme kuyruğuna eklendi.');
    }
}
