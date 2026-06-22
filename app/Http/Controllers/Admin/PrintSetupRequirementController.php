<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItemPrintSetupRequirement;
use App\Services\OrderItemPrintSetupRequirementService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PrintSetupRequirementController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected OrderItemPrintSetupRequirementService $service
    ) {
    }

    public function markRequested(Request $request, OrderItemPrintSetupRequirement $requirement): RedirectResponse
    {
        $this->guardTenantScope($request, $requirement);
        $this->service->markRequested($requirement, $request->user());

        return back()->with('success', 'Hazırlık kaydı talep edildi olarak güncellendi.');
    }

    public function markReady(Request $request, OrderItemPrintSetupRequirement $requirement): RedirectResponse
    {
        $this->guardTenantScope($request, $requirement);
        $this->service->markReady($requirement, $request->user());

        return back()->with('success', 'Hazırlık kaydı hazır olarak güncellendi.');
    }

    public function cancel(Request $request, OrderItemPrintSetupRequirement $requirement): RedirectResponse
    {
        $this->guardTenantScope($request, $requirement);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->service->cancel($requirement, (string) $validated['reason'], $request->user());

        return back()->with('success', 'Hazırlık kaydı iptal edildi.');
    }

    private function guardTenantScope(Request $request, OrderItemPrintSetupRequirement $requirement): void
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $requirement->tenant_account_id !== $tenant->id) {
            abort(403);
        }
    }
}
