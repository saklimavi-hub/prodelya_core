<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItemWorkForm;
use App\Services\TenantResolver;
use App\Services\WorkFormPdfService;
use App\Services\WorkFormRenderDataBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\View\View;

class WorkFormController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected WorkFormRenderDataBuilder $renderDataBuilder,
        protected WorkFormPdfService $pdfService
    ) {
    }

    public function show(Request $request, OrderItemWorkForm $workForm): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $workForm->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $workForm->loadMissing([
            'tenant',
            'order',
            'orderItem',
            'attachments.uploader',
            'activityLogs.attachment',
        ]);

        return view('admin.work-forms.show', $this->renderDataBuilder->build($workForm));
    }

    public function pdf(Request $request, OrderItemWorkForm $workForm): Response
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $workForm->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        if ($workForm->status !== 'active') {
            abort(404);
        }

        $workForm->loadMissing([
            'tenant',
            'order',
            'orderItem',
            'attachments.uploader',
            'activityLogs.attachment',
        ]);

        return $this->pdfService->downloadResponse($workForm);
    }
}
