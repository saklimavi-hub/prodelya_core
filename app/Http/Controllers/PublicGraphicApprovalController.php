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
    private const FORBIDDEN_TERMS = [
        'unit_price',
        'print_unit_price',
        'print_total',
        'product_total',
        'subtotal',
        'vat_total',
        'grand_total',
        'purchase_total',
        'purchase_unit_price',
        'supplier_cost',
        'subcontractor_cost',
        'setup_cost',
        'balance_due',
        'balance',
        'paid_total',
        'payment_amount',
        'current_account_transactions',
        'notification_logs',
        'group_code',
        'pdh_raw',
        'raw xml',
        'raw json',
        'file_path',
        'physical_path',
        'internal note',
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
                    'customerCompany',
                    'graphic.order.customer.contacts',
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
            'customerCompany',
            'graphic.order.customer.contacts',
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
        $productSnapshot = (array) ($workForm?->product_snapshot ?? []);
        $referenceImageUrl = $this->sanitizePublicText(data_get($productSnapshot, 'image_url'));
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
                'customer_name' => $approvalRequest->customerCompany?->legal_name
                    ?: $approvalRequest->contact_name
                    ?: data_get($workForm?->customer_snapshot, 'company_name', '-'),
                'company_name' => $approvalRequest->customerCompany?->legal_name
                    ?: data_get($workForm?->customer_snapshot, 'company_name', '-'),
                'order_number' => $graphic?->order?->document_number ?: data_get($workForm?->order_snapshot, 'document_number', '-'),
                'work_form_number' => $workForm?->work_form_number ?: '-',
                'product_name' => $orderItem?->product_name ?: data_get($productSnapshot, 'product_name', '-'),
                'product_code' => $orderItem?->product_code ?: data_get($productSnapshot, 'product_code', '-'),
                'quantity' => $this->formatQuantity(
                    $orderItem?->quantity ?? data_get($productSnapshot, 'quantity'),
                    $orderItem?->unit ?? data_get($productSnapshot, 'unit')
                ),
                'print_label' => $this->resolvePrintLabel($graphic),
                'print_type' => $graphic?->orderItemPrint?->displayPrintType() ?: '-',
                'status_label' => $graphic?->safeCustomerApprovalLabel() ?: 'Onay Bekliyor',
                'attachment_name' => $attachment?->file_name ?: 'Görsel',
                'attachment_preview_url' => $this->resolvePublicAttachmentUrl($workForm, $attachment),
                'attachment_original_url' => $this->resolvePublicAttachmentUrl($workForm, $attachment),
                'attachment_uploaded_at' => optional($attachment?->created_at)->format('d.m.Y H:i'),
                'attachment_missing' => ! $this->attachmentFileExists($attachment),
                'attachment_is_image' => (bool) $attachment?->isImage(),
                'customer_note' => $this->sanitizePublicText($graphic?->customer_note),
                'reference_image_url' => $referenceImageUrl,
                'reference_image_title' => $orderItem?->product_name ?: data_get($productSnapshot, 'product_name', 'Ürün Referansı'),
                'updated_at' => optional($graphic?->updated_at ?: $attachment?->created_at)->format('d.m.Y H:i'),
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

        $normalized = mb_strtolower($text, 'UTF-8');

        foreach (self::FORBIDDEN_TERMS as $term) {
            if (str_contains($normalized, $term)) {
                return null;
            }
        }

        $normalizedPaths = str_replace('\\', '/', $normalized);

        if (str_contains($normalizedPaths, 'storage/')) {
            return null;
        }

        if (str_contains($normalizedPaths, 'work-forms/')) {
            return null;
        }

        if (preg_match('/[A-Za-z]:\\\\/u', $text) === 1) {
            return null;
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

    private function resolvePublicAttachmentUrl(?\App\Models\OrderItemWorkForm $workForm, ?\App\Models\OrderItemWorkFormAttachment $attachment): ?string
    {
        if (! $workForm || ! $attachment || ! filled($workForm->public_tracking_token) || ! $this->attachmentFileExists($attachment)) {
            return null;
        }

        return route('public.work-forms.attachments.show', [
            'token' => $workForm->public_tracking_token,
            'attachment' => $attachment->id,
        ]);
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

    private function formatQuantity(mixed $quantity, ?string $unit = null): string
    {
        if ($quantity === null || $quantity === '') {
            return '-';
        }

        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }
}
