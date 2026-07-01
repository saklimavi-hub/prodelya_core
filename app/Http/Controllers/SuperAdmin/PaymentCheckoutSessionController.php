<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PaymentCheckoutSession;
use App\Models\PaymentProvider;
use App\Models\TenantAccount;
use App\Models\TenantBillingEntry;
use App\Models\User;
use App\Services\Payments\PaymentCheckoutStatusService;
use App\Services\Payments\SuperAdminPaymentCheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentCheckoutSessionController extends Controller
{
    public function __construct(
        protected SuperAdminPaymentCheckoutService $checkoutService,
        protected PaymentCheckoutStatusService $checkoutStatusService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->string('status'),
            'payment_provider_id' => (string) $request->string('payment_provider_id'),
            'tenant' => trim((string) $request->string('tenant')),
            'q' => trim((string) $request->string('q')),
        ];

        $query = PaymentCheckoutSession::query()
            ->with(['provider', 'tenant', 'subject'])
            ->latest('id');

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['payment_provider_id'] !== '') {
            $query->where('payment_provider_id', (int) $filters['payment_provider_id']);
        }

        if ($filters['tenant'] !== '') {
            $tenantSearch = $filters['tenant'];
            $query->whereHas('tenant', function ($tenantQuery) use ($tenantSearch): void {
                $tenantQuery->where('name', 'like', '%' . $tenantSearch . '%')
                    ->orWhere('slug', 'like', '%' . $tenantSearch . '%');
            });
        }

        if ($filters['q'] !== '') {
            $search = $filters['q'];
            $query->where(function ($innerQuery) use ($search): void {
                $innerQuery->where('reference_no', 'like', '%' . $search . '%')
                    ->orWhere('gateway_reference', 'like', '%' . $search . '%')
                    ->orWhere('external_reference', 'like', '%' . $search . '%');
            });
        }

        $sessions = $query->paginate(20)->withQueryString();
        $providers = PaymentProvider::query()->orderBy('display_name')->get(['id', 'display_name']);
        $stats = [
            'total' => PaymentCheckoutSession::query()->count(),
            'pending' => PaymentCheckoutSession::query()->where('status', PaymentCheckoutSession::STATUS_PENDING)->count(),
            'paid' => PaymentCheckoutSession::query()->where('status', PaymentCheckoutSession::STATUS_PAID)->count(),
            'attention' => PaymentCheckoutSession::query()->whereIn('status', [
                PaymentCheckoutSession::STATUS_FAILED,
                PaymentCheckoutSession::STATUS_CANCELLED,
                PaymentCheckoutSession::STATUS_EXPIRED,
            ])->count(),
        ];

        return view('super-admin.payment-checkouts.index', [
            'sessions' => $sessions,
            'providers' => $providers,
            'filters' => $filters,
            'stats' => $stats,
            'statusOptions' => [
                '' => 'Tümü',
                PaymentCheckoutSession::STATUS_DRAFT => 'Taslak',
                PaymentCheckoutSession::STATUS_PENDING => 'Bekliyor',
                PaymentCheckoutSession::STATUS_PAID => 'Tahsil Edildi',
                PaymentCheckoutSession::STATUS_FAILED => 'Başarısız',
                PaymentCheckoutSession::STATUS_CANCELLED => 'İptal',
                PaymentCheckoutSession::STATUS_EXPIRED => 'Süresi Doldu',
            ],
        ]);
    }

    public function create(TenantAccount $tenant, Request $request): View
    {
        $entry = null;

        if ($request->filled('billing_entry_id')) {
            $entry = TenantBillingEntry::query()
                ->where('tenant_account_id', $tenant->id)
                ->findOrFail((int) $request->input('billing_entry_id'));
        }

        $providers = PaymentProvider::query()
            ->with('sharedCredential')
            ->where('supports_shared_saas_payments', true)
            ->orderBy('display_name')
            ->get();

        return view('super-admin.payment-checkouts.create', [
            'tenant' => $tenant,
            'billingEntry' => $entry,
            'providers' => $providers,
            'formAction' => route('admin.super.tenants.payment-checkouts.store', $tenant),
        ]);
    }

    public function store(Request $request, TenantAccount $tenant): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $request->validate([
            'payment_provider_id' => ['required', 'integer', 'exists:payment_providers,id'],
            'billing_entry_id' => ['nullable', 'integer', 'exists:tenant_billing_entries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])],
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $provider = PaymentProvider::query()->findOrFail((int) $validated['payment_provider_id']);
        $entry = null;

        if (filled($validated['billing_entry_id'] ?? null)) {
            $entry = TenantBillingEntry::query()
                ->where('tenant_account_id', $tenant->id)
                ->findOrFail((int) $validated['billing_entry_id']);
        }

        $session = $this->checkoutService->createSaasBillingSession($tenant, $provider, $validated, $actor, $entry);

        return redirect()
            ->route('admin.super.payment-checkouts.show', $session)
            ->with('success', 'Ortak ödeme checkout oturumu oluşturuldu.');
    }

    public function show(PaymentCheckoutSession $paymentCheckout): View
    {
        $paymentCheckout->load(['provider', 'credential', 'tenant', 'subject', 'transactions']);

        return view('super-admin.payment-checkouts.show', [
            'session' => $paymentCheckout,
        ]);
    }

    public function cancel(Request $request, PaymentCheckoutSession $paymentCheckout): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        abort_unless($paymentCheckout->canBeCancelled(), 422, 'Bu checkout oturumu iptal edilemez.');

        $this->checkoutStatusService->applyStatus(
            $paymentCheckout,
            PaymentCheckoutSession::STATUS_CANCELLED,
            'manual_cancelled',
            ['source' => 'super_admin_operation'],
            'Super Admin tarafından manuel iptal edildi.',
            $actor
        );

        return redirect()
            ->route('admin.super.payment-checkouts.show', $paymentCheckout)
            ->with('success', 'Checkout oturumu iptal edildi.');
    }

    public function expire(Request $request, PaymentCheckoutSession $paymentCheckout): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        abort_unless($paymentCheckout->canBeExpired(), 422, 'Bu checkout oturumu süresi dolmuş olarak işaretlenemez.');

        $this->checkoutStatusService->applyStatus(
            $paymentCheckout,
            PaymentCheckoutSession::STATUS_EXPIRED,
            'manual_expired',
            ['source' => 'super_admin_operation'],
            'Super Admin tarafından manuel süre sonlandırması yapıldı.',
            $actor
        );

        return redirect()
            ->route('admin.super.payment-checkouts.show', $paymentCheckout)
            ->with('success', 'Checkout oturumu süresi doldu olarak güncellendi.');
    }

    public function retry(Request $request, PaymentCheckoutSession $paymentCheckout): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        abort_unless($paymentCheckout->canBeRetried(), 422, 'Bu checkout oturumu yeniden üretilemez.');

        $newSession = $this->checkoutService->retrySaasBillingSession($paymentCheckout, $actor);

        return redirect()
            ->route('admin.super.payment-checkouts.show', $newSession)
            ->with('success', 'Yeni checkout oturumu üretildi.');
    }
}
