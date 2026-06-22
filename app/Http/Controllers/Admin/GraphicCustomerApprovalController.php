<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GraphicApprovalRequest;
use App\Models\OrderItemPrintGraphic;
use App\Services\GraphicApprovalRequestService;
use App\Services\TenantAccessService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GraphicCustomerApprovalController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantAccessService $tenantAccessService,
        protected GraphicApprovalRequestService $graphicApprovalRequestService,
    ) {
    }

    public function send(Request $request, OrderItemPrintGraphic $graphic): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || $graphic->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        abort_unless(
            $this->tenantAccessService->canAccessModule($tenant, 'graphic_customer_approval')
            && $this->tenantAccessService->canAccessFeature($tenant, 'public_graphic_approval', 'graphic_customer_approval'),
            403
        );

        $validated = $request->validate([
            'attachment_id' => ['required', 'integer'],
        ]);

        $graphic->loadMissing('workForm');
        $attachment = $graphic->attachments()
            ->where('tenant_account_id', $tenant->id)
            ->find($validated['attachment_id']);

        if (! $attachment) {
            abort(403);
        }

        try {
            $approvalRequest = $this->graphicApprovalRequestService->createRequest(
                $graphic,
                $attachment,
                [],
                $request->user()
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'attachment_id' => $exception->getMessage(),
            ]);
        }

        $message = 'Grafik müşteri onayına gönderildi.';

        if (! $approvalRequest->publicUrl()) {
            $message .= ' Onay kaydı oluşturuldu.';
        }

        return redirect()
            ->route('admin.graphics.show', $graphic->workForm)
            ->with('success', $message);
    }

    public function open(Request $request, GraphicApprovalRequest $approvalRequest): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || $approvalRequest->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        abort_unless(
            $this->tenantAccessService->canAccessModule($tenant, 'graphic_customer_approval')
            && $this->tenantAccessService->canAccessFeature($tenant, 'public_graphic_approval', 'graphic_customer_approval'),
            403
        );

        if (! filled($approvalRequest->token)) {
            abort(404);
        }

        return redirect()->route('public.graphics.approval.show', [
            'token' => $approvalRequest->token,
        ]);
    }
}
