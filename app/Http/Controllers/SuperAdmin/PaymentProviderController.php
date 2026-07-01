<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentProviderConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentProviderController extends Controller
{
    public function __construct(
        protected PaymentProviderConfigService $configService,
        protected PaymentGatewayManager $gatewayManager
    ) {
    }

    public function index(): View
    {
        $providers = PaymentProvider::query()
            ->with(['sharedCredential'])
            ->withCount(['checkoutSessions', 'webhookLogs'])
            ->orderBy('display_name')
            ->get();

        $stats = [
            'total' => $providers->count(),
            'active' => $providers->where('status', PaymentProvider::STATUS_ACTIVE)->count(),
            'shared_ready' => $providers->filter(fn (PaymentProvider $provider) => $this->configService->sharedCredentialReady($provider))->count(),
            'tenant_module_ready' => $providers->where('supports_tenant_module', true)->count(),
        ];

        return view('super-admin.payment-providers.index', [
            'providers' => $providers,
            'stats' => $stats,
            'configService' => $this->configService,
        ]);
    }

    public function create(): View
    {
        return view('super-admin.payment-providers.create', $this->formPayload(new PaymentProvider([
            'status' => PaymentProvider::STATUS_DRAFT,
            'checkout_mode' => 'hosted',
            'supports_shared_saas_payments' => true,
            'supports_tenant_module' => false,
        ]), route('admin.super.payment-providers.store'), 'POST'));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $provider = $this->configService->saveProvider(null, $this->validated($request), $actor);

        return redirect()
            ->route('admin.super.payment-providers.edit', $provider)
            ->with('success', 'Ödeme sağlayıcısı omurgası oluşturuldu.');
    }

    public function edit(PaymentProvider $paymentProvider): View
    {
        $paymentProvider->load(['sharedCredential', 'checkoutSessions' => fn ($query) => $query->latest()->limit(10), 'webhookLogs' => fn ($query) => $query->latest()->limit(10)]);

        return view('super-admin.payment-providers.edit', $this->formPayload(
            $paymentProvider,
            route('admin.super.payment-providers.update', $paymentProvider),
            'PUT'
        ));
    }

    public function update(Request $request, PaymentProvider $paymentProvider): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $this->configService->saveProvider($paymentProvider, $this->validated($request, $paymentProvider), $actor);

        return redirect()
            ->route('admin.super.payment-providers.edit', $paymentProvider)
            ->with('success', 'Ödeme sağlayıcısı güncellendi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PaymentProvider $provider = null): array
    {
        return $request->validate([
            'provider_key' => [
                'required',
                'string',
                'max:50',
                Rule::unique('payment_providers', 'provider_key')->ignore($provider?->id),
            ],
            'driver_key' => ['required', Rule::in(array_keys($this->gatewayManager->driverOptions()))],
            'display_name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys($this->configService->statusOptions()))],
            'checkout_mode' => ['required', Rule::in(array_keys($this->configService->checkoutModeOptions()))],
            'supports_shared_saas_payments' => ['nullable', 'boolean'],
            'supports_tenant_module' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'shared_credential_is_active' => ['nullable', 'boolean'],
            'shared_api_key' => ['nullable', 'string', 'max:255'],
            'shared_secret_key' => ['nullable', 'string', 'max:255'],
            'shared_merchant_key' => ['nullable', 'string', 'max:255'],
            'shared_base_url' => ['nullable', 'string', 'max:255'],
            'shared_webhook_secret' => ['nullable', 'string', 'max:255'],
            'shared_sandbox_mode' => ['nullable', 'boolean'],
            'shared_use_live_api' => ['nullable', 'boolean'],
            'shared_checkout_initialize_path' => ['nullable', 'string', 'max:255'],
            'shared_timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:120'],
            'shared_credential_notes' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(PaymentProvider $provider, string $formAction, string $formMethod): array
    {
        return [
            'provider' => $provider,
            'driverOptions' => $this->gatewayManager->driverOptions(),
            'statusOptions' => $this->configService->statusOptions(),
            'checkoutModeOptions' => $this->configService->checkoutModeOptions(),
            'formAction' => $formAction,
            'formMethod' => $formMethod,
        ];
    }
}
