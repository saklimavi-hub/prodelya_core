<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\TenantUpgradeRequest;
use App\Services\SuperAdmin\TenantUpgradeRequestApplyService;
use App\Services\SuperAdmin\TenantUpgradeRequestReviewService;
use App\Services\Tenant\TenantUpgradeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantUpgradeRequestController extends Controller
{
    public function __construct(
        protected TenantUpgradeRequestReviewService $reviewService,
        protected TenantUpgradeRequestService $requestService,
        protected TenantUpgradeRequestApplyService $applyService,
    ) {}

    public function index(Request $request): View
    {
        return view('super-admin.upgrade-requests.index', $this->reviewService->buildIndexContext($request->all()));
    }

    public function show(TenantUpgradeRequest $tenantUpgradeRequest): View
    {
        return view('super-admin.upgrade-requests.show', $this->reviewService->buildShowContext($tenantUpgradeRequest));
    }

    public function inReview(Request $request, TenantUpgradeRequest $tenantUpgradeRequest): RedirectResponse
    {
        return $this->handleDecision($request, $tenantUpgradeRequest, 'in_review');
    }

    public function approve(Request $request, TenantUpgradeRequest $tenantUpgradeRequest): RedirectResponse
    {
        return $this->handleDecision($request, $tenantUpgradeRequest, 'approve');
    }

    public function reject(Request $request, TenantUpgradeRequest $tenantUpgradeRequest): RedirectResponse
    {
        return $this->handleDecision($request, $tenantUpgradeRequest, 'reject');
    }

    public function cancel(Request $request, TenantUpgradeRequest $tenantUpgradeRequest): RedirectResponse
    {
        return $this->handleDecision($request, $tenantUpgradeRequest, 'cancel');
    }

    public function apply(Request $request, TenantUpgradeRequest $tenantUpgradeRequest): RedirectResponse
    {
        $validated = $request->validate([
            'apply_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->applyService->apply($tenantUpgradeRequest, $request->user(), [
                'apply_note' => $validated['apply_note'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('admin.super.upgrade-requests.show', $tenantUpgradeRequest)
                ->withErrors($exception->errors())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'Talep uygulanamadı.');
        } catch (\Throwable $throwable) {
            return redirect()
                ->route('admin.super.upgrade-requests.show', $tenantUpgradeRequest)
                ->withInput()
                ->with('error', 'Talep uygulanırken beklenmeyen bir hata oluştu.');
        }

        return redirect()
            ->route('admin.super.upgrade-requests.show', $tenantUpgradeRequest)
            ->with('success', 'Talep güvenli şekilde uygulandı.');
    }

    private function handleDecision(Request $request, TenantUpgradeRequest $tenantUpgradeRequest, string $action): RedirectResponse
    {
        $rules = [
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];

        if ($action === 'reject') {
            $rules['admin_note'] = ['required', 'string', 'max:2000'];
        }

        $validated = $request->validate($rules);
        $note = trim((string) ($validated['admin_note'] ?? ''));
        $note = $note === '' ? null : $note;

        try {
            if ($action === 'in_review') {
                $this->requestService->markInReview($tenantUpgradeRequest, $request->user(), $note);
                $message = 'Talep incelemeye alındı.';
            } elseif ($action === 'approve') {
                $this->requestService->approve($tenantUpgradeRequest, $request->user(), $note);
                $message = 'Talep onaylandı. Uygulama adımı sonraki fazda açılacaktır.';
            } elseif ($action === 'reject') {
                $this->requestService->reject($tenantUpgradeRequest, $request->user(), $note);
                $message = 'Talep reddedildi.';
            } else {
                $this->requestService->cancel($tenantUpgradeRequest, $request->user(), $note);
                $message = 'Talep iptal edildi.';
            }
        } catch (ValidationException $exception) {
            return redirect()
                ->route('admin.super.upgrade-requests.show', $tenantUpgradeRequest)
                ->withErrors($exception->errors())
                ->withInput()
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'Talep işlemi tamamlanamadı.');
        }

        return redirect()
            ->route('admin.super.upgrade-requests.show', $tenantUpgradeRequest)
            ->with('success', $message);
    }
}
