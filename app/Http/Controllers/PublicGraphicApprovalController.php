<?php

namespace App\Http\Controllers;

use App\Models\GraphicApprovalRequest;
use App\Services\GraphicApprovalRequestService;
use App\Services\TenantAccessService;
use App\Services\TenantCompanyProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;

class PublicGraphicApprovalController extends Controller
{
    private const FORBIDDEN_PATTERNS = [
        '/\bunit_price\b/i',
        '/\bprint_unit_price\b/i',
        '/\bprint_total\b/i',
        '/\bproduct_total\b/i',
        '/\bsubtotal\b/i',
        '/\bvat_total\b/i',
        '/\bgrand_total\b/i',
        '/\bpurchase_total\b/i',
        '/\bpurchase_unit_price\b/i',
        '/\bsupplier_cost\b/i',
        '/\bsubcontractor_cost\b/i',
        '/\bsetup_cost\b/i',
        '/\bbalance_due\b/i',
        '/\bbalance\b/i',
        '/\bpaid_total\b/i',
        '/\bpayment_amount\b/i',
        '/\bcurrent_account_transactions\b/i',
        '/\bnotification_logs\b/i',
        '/\bgroup_code\b/i',
        '/\bpdh_raw\b/i',
        '/\braw xml\b/i',
        '/\braw json\b/i',
        '/\bfile_path\b/i',
        '/\bphysical_path\b/i',
        '/\binternal note\b/i',
        '/(^|[\s"\'])storage[\/\\\\]/i',
        '/(^|[\s"\'])work-forms[\/\\\\]/i',
        '/[A-Z]:\\\\/i',
    ];

    public function __construct(
        protected GraphicApprovalRequestService $graphicApprovalRequestService,
        protected TenantAccessService $tenantAccessService,
        protected TenantCompanyProfileService $tenantCompanyProfileService,
    ) {
    }

    public function show(string $token): View
    {
        $approvalRequest = $this->resolvePublicApprovalRequest($token);
        $approvalRequest = $this->markExpiredIfNeeded($approvalRequest);

        if ($this->shouldHideRequest($approvalRequest)) {
            abort(404);
        }

        if ($this->canRespond($approvalRequest)) {
            try {
                $approvalRequest = $this->graphicApprovalRequestService->markViewed($approvalRequest);
            } catch (RuntimeException) {
                $approvalRequest = $approvalRequest->fresh([
                    'tenant',
                    'graphic.orderItem',
                    'graphic.orderItemPrint.tenantPrintSetting',
                    'graphic.workForm',
                    'attachment',
                ]);
            }
        }

        return view('public.graphics.approval.show', $this->buildViewPayload($approvalRequest));
    }

    public function respond(Request $request, string $token): RedirectResponse
    {
        return match ((string) $request->input('decision')) {
            'approve' => $this->approve($request, $token),
            'revision' => $this->requestRevision($request, $token),
            default => abort(404),
        };
    }

    public function approve(Request $request, string $token): RedirectResponse
    {
        $approvalRequest = $this->resolvePublicApprovalRequest($token);
        $approvalRequest = $this->markExpiredIfNeeded($approvalRequest);

        if ($this->shouldHideRequest($approvalRequest)) {
            abort(404);
        }

        if (! $this->canRespond($approvalRequest)) {
            return $this->redirectWithResolvedStatusMessage($approvalRequest, $token);
        }

        try {
            $this->graphicApprovalRequestService->approve(
                $approvalRequest,
                ['customer_note' => $this->sanitizePublicText($request->input('customer_note'))]
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('public.graphics.approval.show', ['token' => $token])
                ->with('error', $this->humanizePublicError($exception));
        }

        return redirect()
            ->route('public.graphics.approval.show', ['token' => $token])
            ->with('success', 'Grafik onayınız alınmıştır.');
    }

    public function requestRevision(Request $request, string $token): RedirectResponse
    {
        $approvalRequest = $this->resolvePublicApprovalRequest($token);
        $approvalRequest = $this->markExpiredIfNeeded($approvalRequest);

        if ($this->shouldHideRequest($approvalRequest)) {
            abort(404);
        }

        if (! $this->canRespond($approvalRequest)) {
            return $this->redirectWithResolvedStatusMessage($approvalRequest, $token);
        }

        $validated = $request->validate([
            'customer_note' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'customer_note.required' => 'Revize notu gerekli.',
            'customer_note.min' => 'Revize notu en az 3 karakter olmalı.',
        ]);

        try {
            $this->graphicApprovalRequestService->requestRevision(
                $approvalRequest,
                trim((string) $validated['customer_note'])
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('public.graphics.approval.show', ['token' => $token])
                ->with('error', $this->humanizePublicError($exception))
                ->withInput();
        }

        return redirect()
            ->route('public.graphics.approval.show', ['token' => $token])
            ->with('success', 'Revize talebiniz iletilmiştir.');
    }

    private function resolvePublicApprovalRequest(string $token): GraphicApprovalRequest
    {
        $approvalRequest = $this->graphicApprovalRequestService->findByToken($token);

        if (! $approvalRequest || ! $approvalRequest->tenant || ! $approvalRequest->graphic || ! $approvalRequest->attachment) {
            abort(404);
        }

        if (! $this->tenantAccessService->canAccessFeature(
            $approvalRequest->tenant,
            'public_graphic_approval',
            'graphic_customer_approval'
        )) {
            abort(404);
        }

        if (! $this->isAttachmentStillEligibleForPublicDisplay($approvalRequest)) {
            abort(404);
        }

        return $approvalRequest;
    }

    private function isAttachmentStillEligibleForPublicDisplay(GraphicApprovalRequest $approvalRequest): bool
    {
        $attachment = $approvalRequest->attachment;
        $graphic = $approvalRequest->graphic;

        if (! $attachment || ! $graphic) {
            return false;
        }

        return $attachment->tenant_account_id === $approvalRequest->tenant_account_id
            && $attachment->tenant_account_id === $graphic->tenant_account_id
            && $attachment->isCustomerVisible()
            && in_array($attachment->attachment_type, ['graphic_visual', 'customer_approval'], true)
            && (int) $attachment->order_id === (int) $approvalRequest->order_id
            && (int) $attachment->order_item_id === (int) $approvalRequest->order_item_id
            && (int) $attachment->work_form_id === (int) $approvalRequest->work_form_id
            && (int) $attachment->order_item_print_id === (int) $approvalRequest->order_item_print_id
            && (int) $attachment->order_item_print_graphic_id === (int) $approvalRequest->order_item_print_graphic_id;
    }

    private function shouldHideRequest(GraphicApprovalRequest $approvalRequest): bool
    {
        return $approvalRequest->isCancelled();
    }

    private function canRespond(GraphicApprovalRequest $approvalRequest): bool
    {
        return $approvalRequest->canRespond();
    }

    private function markExpiredIfNeeded(GraphicApprovalRequest $approvalRequest): GraphicApprovalRequest
    {
        if ($approvalRequest->status !== GraphicApprovalRequest::STATUS_CANCELLED
            && $approvalRequest->status !== GraphicApprovalRequest::STATUS_EXPIRED
            && $approvalRequest->isExpired()) {
            $approvalRequest->forceFill([
                'status' => GraphicApprovalRequest::STATUS_EXPIRED,
            ])->save();
        }

        return $approvalRequest->fresh([
            'tenant',
            'graphic.orderItem',
            'graphic.orderItemPrint.tenantPrintSetting',
            'graphic.workForm',
            'attachment',
        ]);
    }

    private function buildViewPayload(GraphicApprovalRequest $approvalRequest): array
    {
        $graphic = $approvalRequest->graphic;
        $attachment = $approvalRequest->attachment;
        $workForm = $graphic?->workForm;
        $orderItem = $graphic?->orderItem;

        $pageStatus = $approvalRequest->isExpired()
            ? GraphicApprovalRequest::STATUS_EXPIRED
            : $approvalRequest->status;

        return [
            'tenantName' => $approvalRequest->tenant
                ? $this->tenantCompanyProfileService->getProfile($approvalRequest->tenant)['display_name']
                : 'Prodelya',
            'request' => $approvalRequest,
            'pageStatus' => $pageStatus,
            'pageStatusLabel' => $this->publicStatusLabel($pageStatus),
            'pageMessage' => $this->publicStatusMessage($pageStatus),
            'canRespond' => $this->canRespond($approvalRequest),
            'graphic' => [
                'order_number' => $graphic?->order?->document_number ?: data_get($workForm?->order_snapshot, 'document_number', '-'),
                'work_form_number' => $workForm?->work_form_number ?: '-',
                'product_name' => $orderItem?->product_name ?: data_get($workForm?->product_snapshot, 'product_name', '-'),
                'print_label' => $this->resolvePrintLabel($graphic),
                'status_label' => $graphic?->safeCustomerApprovalLabel() ?: 'Onay Bekliyor',
                'attachment_name' => $attachment?->file_name ?: 'Görsel',
                'customer_note' => $this->sanitizePublicText($graphic?->customer_note),
                'attachment_preview_data_url' => $this->resolveInlinePreviewDataUrl($attachment),
                'attachment_missing' => ! $this->attachmentFileExists($attachment),
            ],
        ];
    }

    private function publicStatusLabel(string $status): string
    {
        return match ($status) {
            GraphicApprovalRequest::STATUS_WAITING => 'Yanıt Bekleniyor',
            GraphicApprovalRequest::STATUS_VIEWED => 'İnceleniyor',
            GraphicApprovalRequest::STATUS_APPROVED => 'Onaylandı',
            GraphicApprovalRequest::STATUS_REVISION_REQUESTED => 'Revize İstendi',
            GraphicApprovalRequest::STATUS_EXPIRED => 'Süresi Doldu',
            default => 'Bağlantı Kapalı',
        };
    }

    private function publicStatusMessage(string $status): string
    {
        return match ($status) {
            GraphicApprovalRequest::STATUS_WAITING,
            GraphicApprovalRequest::STATUS_VIEWED => 'Grafik görselini inceleyip aşağıdaki aksiyonlardan birini seçebilirsiniz.',
            GraphicApprovalRequest::STATUS_APPROVED => 'Bu grafik daha önce onaylanmış.',
            GraphicApprovalRequest::STATUS_REVISION_REQUESTED => 'Bu grafik için revize talebiniz iletilmiş.',
            GraphicApprovalRequest::STATUS_EXPIRED => 'Bu grafik onay bağlantısının süresi dolmuş.',
            default => 'Bu grafik onay bağlantısı artık geçerli değil.',
        };
    }

    private function humanizePublicError(RuntimeException $exception): string
    {
        return match ($exception->getMessage()) {
            'Süresi dolan grafik onay isteği yanıtlanamaz.' => 'Bu grafik onay bağlantısının süresi dolmuş.',
            'İptal edilen grafik onay isteği yanıtlanamaz.' => 'Bu grafik onay bağlantısı artık geçerli değil.',
            'Bu grafik onay isteği artık yanıtlanamaz.' => 'Bu grafik için işlem daha önce tamamlanmış.',
            'Revize isteği için not gerekli.' => 'Revize notu gerekli.',
            default => 'Bu grafik için işlem daha önce tamamlanmış.',
        };
    }

    private function redirectWithResolvedStatusMessage(GraphicApprovalRequest $approvalRequest, string $token): RedirectResponse
    {
        $status = $approvalRequest->isExpired()
            ? GraphicApprovalRequest::STATUS_EXPIRED
            : $approvalRequest->status;

        return redirect()
            ->route('public.graphics.approval.show', ['token' => $token])
            ->with('error', $this->publicStatusMessage($status));
    }

    private function sanitizePublicText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return null;
            }
        }

        return $text;
    }

    private function resolvePrintLabel(?\App\Models\OrderItemPrintGraphic $graphic): string
    {
        if (! $graphic) {
            return '-';
        }

        $print = $graphic->orderItemPrint;
        $parts = array_filter([
            $graphic->sequence_code,
            $print?->displayPrintType(),
            $print?->print_option,
        ], fn ($value) => filled($value));

        return $parts === [] ? '-' : implode(' ', $parts);
    }

    private function resolveInlinePreviewDataUrl(?\App\Models\OrderItemWorkFormAttachment $attachment): ?string
    {
        if (! $attachment || ! $attachment->isImage() || ! $this->attachmentFileExists($attachment)) {
            return null;
        }

        try {
            $contents = Storage::disk($attachment->disk ?: config('filesystems.default'))->get($attachment->file_path);
        } catch (\Throwable) {
            return null;
        }

        if ($contents === '') {
            return null;
        }

        return 'data:' . ($attachment->mime_type ?: 'image/png') . ';base64,' . base64_encode($contents);
    }

    private function attachmentFileExists(?\App\Models\OrderItemWorkFormAttachment $attachment): bool
    {
        if (! $attachment || ! filled($attachment->file_path)) {
            return false;
        }

        try {
            return Storage::disk($attachment->disk ?: config('filesystems.default'))->exists($attachment->file_path);
        } catch (\Throwable) {
            return false;
        }
    }
}