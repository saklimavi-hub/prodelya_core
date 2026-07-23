<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GraphicApprovalRequest;
use App\Models\NotificationLog;
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

        $redirect = redirect()->route('admin.graphics.show', $graphic->workForm);

        if (! $approvalRequest->publicUrl()) {
            return $redirect->with('warning', 'Onay kaydı oluşturuldu; public onay bağlantısı hazırlanamadı.');
        }

        return $this->redirectWithDispatchOutcome($redirect, $approvalRequest);
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

    private function redirectWithDispatchOutcome(RedirectResponse $redirect, GraphicApprovalRequest $approvalRequest): RedirectResponse
    {
        $notificationLogs = NotificationLog::query()
            ->where('related_type', $approvalRequest->getMorphClass())
            ->where('related_id', $approvalRequest->id)
            ->get();

        $emailLog = $notificationLogs->firstWhere('channel', NotificationLog::CHANNEL_EMAIL);
        $maskedRecipient = $this->maskEmail($emailLog?->recipient_email);

        if (! $emailLog) {
            return $redirect->with('warning', 'Onay bağlantısı hazırlandı; e-posta bildirimi oluşturulamadı. Bağlantıyı manuel olarak paylaşabilirsiniz.');
        }

        if ($emailLog->status === NotificationLog::STATUS_SKIPPED) {
            if (! filled($emailLog->recipient_email)) {
                return $redirect->with('warning', 'Bu müşteri için kullanılabilir bir e-posta adresi bulunamadı. Onay bağlantısını manuel olarak paylaşabilirsiniz.');
            }

            $detail = trim((string) ($emailLog->safeDisplayError() ?? $emailLog->error_message));
            $message = 'Onay bağlantısı hazırlandı; e-posta gönderilemedi.';

            if ($maskedRecipient !== null) {
                $message .= ' Alıcı: ' . $maskedRecipient . '.';
            }

            if ($detail !== '') {
                $message .= ' ' . $detail;
            }

            return $redirect->with('warning', trim($message));
        }

        if ($emailLog->status === NotificationLog::STATUS_FAILED) {
            $message = 'Onay bağlantısı hazırlandı; e-posta gönderilemedi.';

            if ($maskedRecipient !== null) {
                $message .= ' Alıcı: ' . $maskedRecipient . '.';
            }

            return $redirect->with('warning', $message);
        }

        if ($emailLog->status === NotificationLog::STATUS_PENDING) {
            $message = 'E-posta gönderim kuyruğuna alındı.';

            if ($maskedRecipient !== null) {
                $message .= ' Alıcı: ' . $maskedRecipient . '.';
            }

            return $redirect->with('warning', $message);
        }

        if ($emailLog->status === NotificationLog::STATUS_PREVIEW) {
            if ((string) config('mail.default', 'log') === 'log') {
                $message = 'Alıcı bulundu; e-posta log kanalına yazıldı. Gerçek posta kutusuna gönderilmedi.';
            } else {
                $message = 'Alıcı bulundu; e-posta önizleme kaydı oluşturuldu. Gerçek SMTP gönderimi yapılmadı.';
            }

            if ($maskedRecipient !== null) {
                $message .= ' Alıcı: ' . $maskedRecipient . '.';
            }

            return $redirect->with('warning', $message);
        }

        if ($emailLog->status === NotificationLog::STATUS_SENT) {
            $message = 'E-posta gönderildi.';

            if ($maskedRecipient !== null) {
                $message .= ' Alıcı: ' . $maskedRecipient . '.';
            }

            return $redirect->with('success', $message);
        }

        return $redirect->with('success', 'Grafik müşteri onayına gönderildi.');
    }

    private function maskEmail(?string $email): ?string
    {
        $value = trim((string) ($email ?? ''));

        if ($value === '' || ! str_contains($value, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $value, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible . '***@' . $domain;
    }
}
