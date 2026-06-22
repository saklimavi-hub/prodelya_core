<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrderPaymentRequest;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\OrderPaymentService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderPaymentController extends Controller
{
    private const VIEW_PERMISSIONS = [
        'view_order_finance_summary',
        'view_payment_details',
        'manage_payments',
        'mark_payments_received',
    ];

    public function __construct(
        protected TenantResolver $tenantResolver,
        protected OrderPaymentService $orderPaymentService
    ) {
    }

    public function store(StoreOrderPaymentRequest $request, Order $order): RedirectResponse
    {
        $tenant = $this->resolveAuthorizedTenant($request, ['manage_payments', 'mark_payments_received']);
        $this->assertOrderAccess($order, $tenant->id);

        try {
            $this->orderPaymentService->createPayment($order, $request->validated(), $request->user());
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.finance.show', $order)
            ->with('success', 'Tahsilat hareketi kaydedildi.');
    }

    public function cancel(Request $request, Order $order, OrderPayment $payment): RedirectResponse
    {
        $tenant = $this->resolveAuthorizedTenant($request, ['manage_payments']);
        $this->assertOrderAccess($order, $tenant->id);
        $this->assertPaymentAccess($payment, $order, $tenant->id);

        $validated = $request->validate([
            'cancel_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'cancel_note.max' => 'İptal notu en fazla 1000 karakter olabilir.',
        ]);

        try {
            $this->orderPaymentService->cancelPayment($payment, $request->user(), $validated['cancel_note'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'payment' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.finance.show', $order)
            ->with('success', 'Tahsilat hareketi iptal edildi.');
    }

    public function markPaid(Request $request, Order $order): RedirectResponse
    {
        $tenant = $this->resolveAuthorizedTenant($request, ['manage_payments', 'mark_payments_received']);
        $this->assertOrderAccess($order, $tenant->id);

        $validated = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $payment = $this->orderPaymentService->markOrderPaid(
                $order,
                $request->user(),
                $validated['payment_method'] ?? null
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'payment' => $exception->getMessage(),
            ]);
        }

        $message = $payment
            ? 'Kalan bakiye kadar tahsilat kaydı oluşturuldu.'
            : 'Sipariş zaten ödenmiş görünüyor.';

        return redirect()
            ->route('admin.finance.show', $order)
            ->with('success', $message);
    }

    private function resolveAuthorizedTenant(Request $request, array $requiredPermissions)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $user = $request->user();

        abort_unless(
            $tenant
            && $user
            && $user->hasAnyPermissionInTenant(self::VIEW_PERMISSIONS, $tenant->id)
            && $user->hasAnyPermissionInTenant($requiredPermissions, $tenant->id),
            403
        );

        return $tenant;
    }

    private function assertOrderAccess(Order $order, int $tenantId): void
    {
        if ($order->tenant_account_id !== $tenantId) {
            abort(403);
        }
    }

    private function assertPaymentAccess(OrderPayment $payment, Order $order, int $tenantId): void
    {
        if ($payment->tenant_account_id !== $tenantId || $payment->order_id !== $order->id) {
            abort(403);
        }
    }
}
