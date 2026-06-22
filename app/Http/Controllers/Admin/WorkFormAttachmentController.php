<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Services\TenantResolver;
use App\Services\WorkFormAttachmentService;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Route;

class WorkFormAttachmentController extends Controller
{
    private const ATTACHMENT_TYPES = [
        'graphic_visual',
        'customer_approval',
        'production_photo',
        'delivery_photo',
        'delivery_document',
        'other',
    ];

    public function __construct(
        protected TenantResolver $tenantResolver,
        protected WorkFormAttachmentService $attachmentService
    ) {
    }

    public function store(Request $request, OrderItemWorkForm $workForm): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant || $workForm->tenant_account_id !== $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'attachment_type' => ['required', Rule::in(self::ATTACHMENT_TYPES)],
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:10240'],
            'note' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['nullable', Rule::in(['internal', 'customer_visible'])],
            'order_item_print_graphic_id' => ['nullable', 'integer'],
            'section' => ['nullable', 'string', 'max:50'],
        ]);

        $graphicId = (int) ($validated['order_item_print_graphic_id'] ?? 0);

        if ($graphicId > 0 && in_array($validated['attachment_type'], ['graphic_visual', 'customer_approval'], true)) {
            /** @var OrderItemPrintGraphic $graphic */
            $graphic = $workForm->printGraphics()
                ->where('tenant_account_id', $tenant->id)
                ->findOrFail($graphicId);

            if ($validated['attachment_type'] === 'customer_approval') {
                $this->attachmentService->attachCustomerApprovalToPrintGraphic(
                    $graphic,
                    $request->file('file'),
                    [
                        'note' => $validated['note'] ?? null,
                        'visibility' => $validated['visibility'] ?? 'internal',
                    ],
                    $request->user()
                );
            } else {
                $this->attachmentService->attachGraphicVisualToPrintGraphic(
                    $graphic,
                    $request->file('file'),
                    [
                        'note' => $validated['note'] ?? null,
                        'visibility' => $validated['visibility'] ?? 'internal',
                    ],
                    $request->user()
                );
            }

            if (filled($validated['note'] ?? null)) {
                $graphic->forceFill([
                    'graphic_note' => trim((string) $validated['note']),
                    'updated_by' => $request->user()?->id,
                ])->save();
            }
        } else {
            $this->attachmentService->store(
                $workForm,
                $request->file('file'),
                $validated['attachment_type'],
                $validated['note'] ?? null,
                $validated['visibility'] ?? 'internal',
                $request->user()
            );
        }

        return redirect()
            ->route(...$this->resolveRedirectTarget($request, $workForm))
            ->with('success', 'Dosya İş Formu\'na eklendi.');
    }

    public function destroy(Request $request, OrderItemWorkForm $workForm, OrderItemWorkFormAttachment $attachment): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (
            !$tenant
            || $workForm->tenant_account_id !== $tenant->id
            || $attachment->tenant_account_id !== $tenant->id
            || $attachment->work_form_id !== $workForm->id
        ) {
            abort(403);
        }

        $this->attachmentService->destroy($workForm, $attachment);

        return redirect()
            ->route(...$this->resolveRedirectTarget($request, $workForm))
            ->with('success', 'Dosya kaldırıldı.');
    }

    public function preview(Request $request, OrderItemWorkFormAttachment $attachment): Response
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $workForm = $attachment->workForm;

        if (
            !$tenant
            || !$workForm
            || $attachment->tenant_account_id !== $tenant->id
            || $workForm->tenant_account_id !== $tenant->id
            || $attachment->work_form_id !== $workForm->id
            || $attachment->order_id !== $workForm->order_id
            || $attachment->order_item_id !== $workForm->order_item_id
        ) {
            abort(403);
        }

        $disk = $attachment->disk ?: config('filesystems.default');

        if (!$attachment->file_path || !Storage::disk($disk)->exists($attachment->file_path)) {
            abort(404);
        }

        $mimeType = $attachment->mime_type ?: Storage::disk($disk)->mimeType($attachment->file_path) ?: 'application/octet-stream';
        $fileName = $attachment->file_name ?: basename((string) $attachment->file_path);
        $disposition = sprintf('inline; filename="%s"', addslashes($fileName));

        return response(Storage::disk($disk)->get($attachment->file_path), 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function resolveRedirectTarget(Request $request, OrderItemWorkForm $workForm): array
    {
        $requestedRoute = (string) $request->input('redirect_to', 'admin.work-forms.show');
        $allowedRoutes = [
            'admin.work-forms.show',
            'admin.graphics.show',
            'admin.deliveries.show',
        ];

        if (in_array($requestedRoute, $allowedRoutes, true) && Route::has($requestedRoute)) {
            if ($requestedRoute === 'admin.deliveries.show') {
                $deliveryId = (int) $request->input('redirect_delivery_id', $workForm->delivery?->id ?? 0);

                if ($deliveryId > 0) {
                    return [$requestedRoute, ['delivery' => $deliveryId]];
                }
            }

            return [$requestedRoute, $workForm];
        }

        return ['admin.work-forms.show', $workForm];
    }
}
