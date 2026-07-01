<?php

namespace App\Http\Controllers\CustomerPortal;

use App\Http\Controllers\Controller;
use App\Models\CustomerPortalUser;
use App\Services\CustomerPortalAuthWorkflowService;
use App\Services\CustomerPortalAccessService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerPortalAuthController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CustomerPortalAccessService $portalAccessService,
        protected CustomerPortalAuthWorkflowService $workflowService,
    ) {
    }

    public function showLoginForm(Request $request): View|RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant) {
            return view('customer-portal.auth.login', [
                'tenant' => null,
                'portalTenant' => null,
            ]);
        }

        if (! $this->portalAccessService->portalLoginEnabled($tenant)) {
            abort(404);
        }

        if (Auth::guard('customer_portal')->check()) {
            return redirect()->route('customer.portal.home');
        }

        return view('customer-portal.auth.login', [
            'tenant' => $tenant,
            'portalTenant' => $tenant,
        ]);
    }

    public function showInviteForm(Request $request, string $token): View|RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || ! $this->portalAccessService->portalLoginEnabled($tenant)) {
            abort(404);
        }

        $portalUser = $this->workflowService->findUserByInviteToken($tenant, $token);

        if (! $portalUser instanceof CustomerPortalUser || ! $this->workflowService->isValidInviteToken($portalUser, $token)) {
            abort(404);
        }

        return view('customer-portal.auth.invite', [
            'tenant' => $tenant,
            'portalUser' => $portalUser,
            'token' => $token,
        ]);
    }

    public function acceptInvite(Request $request, string $token): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || ! $this->portalAccessService->portalLoginEnabled($tenant)) {
            abort(404);
        }

        $portalUser = $this->workflowService->findUserByInviteToken($tenant, $token);

        if (! $portalUser instanceof CustomerPortalUser || ! $this->workflowService->isValidInviteToken($portalUser, $token)) {
            abort(404);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $portalUser = $this->workflowService->acceptInvite($tenant, $portalUser, $token, (string) $validated['password']);

        Auth::guard('customer_portal')->login($portalUser);
        $request->session()->regenerate();

        return redirect()->route('customer.portal.home');
    }

    public function showForgotPasswordForm(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || ! $this->portalAccessService->portalLoginEnabled($tenant)) {
            abort(404);
        }

        return view('customer-portal.auth.forgot-password', [
            'tenant' => $tenant,
        ]);
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || ! $this->portalAccessService->portalLoginEnabled($tenant)) {
            abort(404);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $this->workflowService->requestPasswordReset($tenant, (string) $validated['email'], (string) $request->getHost());

        return back()->with('success', 'Eğer hesabınız uygunsa şifre yenileme bağlantısı e-posta adresinize gönderildi.');
    }

    public function showResetPasswordForm(Request $request, string $token): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || ! $this->portalAccessService->portalLoginEnabled($tenant)) {
            abort(404);
        }

        $portalUser = $this->workflowService->findUserByResetToken($tenant, $token);

        if (! $portalUser instanceof CustomerPortalUser || ! $this->workflowService->isValidResetToken($portalUser, $token)) {
            abort(404);
        }

        return view('customer-portal.auth.reset-password', [
            'tenant' => $tenant,
            'portalUser' => $portalUser,
            'token' => $token,
        ]);
    }

    public function resetPassword(Request $request, string $token): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant || ! $this->portalAccessService->portalLoginEnabled($tenant)) {
            abort(404);
        }

        $portalUser = $this->workflowService->findUserByResetToken($tenant, $token);

        if (! $portalUser instanceof CustomerPortalUser || ! $this->workflowService->isValidResetToken($portalUser, $token)) {
            abort(404);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->workflowService->resetPassword($tenant, $portalUser, $token, (string) $validated['password']);

        return redirect()
            ->route('customer.login')
            ->with('success', 'Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.');
    }

    public function login(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (! $tenant) {
            return back()
                ->withErrors(['email' => 'Firma bilginiz bulunamadı. Mümkünse firmanızın size verdiği portal linki ile giriş yapın.'])
                ->onlyInput('email');
        }

        if (! $this->portalAccessService->portalLoginEnabled($tenant)) {
            abort(404);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $throttleKey = $this->throttleKey($tenant->id, (string) $validated['email'], (string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()
                ->withErrors(['email' => 'Çok fazla deneme yapıldı. Lütfen kısa süre sonra tekrar deneyin.'])
                ->onlyInput('email');
        }

        /** @var CustomerPortalUser|null $user */
        $user = CustomerPortalUser::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereRaw('LOWER(email) = ?', [Str::lower((string) $validated['email'])])
            ->first();

        if (! $user || ! Auth::guard('customer_portal')->validate([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ])) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withErrors(['email' => 'Giriş bilgileri hatalı veya portal erişiminiz aktif değil.'])
                ->onlyInput('email');
        }

        if (! $this->portalAccessService->canAccessPortal($tenant, $user)) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withErrors(['email' => 'Giriş bilgileri hatalı veya portal erişiminiz aktif değil.'])
                ->onlyInput('email');
        }

        Auth::guard('customer_portal')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        RateLimiter::clear($throttleKey);

        return redirect()->intended(route('customer.portal.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $webGuard = Auth::guard('web');
        $portalGuard = Auth::guard('customer_portal');
        $webUser = $webGuard->user();

        if ($portalGuard->check()) {
            $portalGuard->logout();
        }

        if ($webUser) {
            if (method_exists($webGuard, 'getName')) {
                $request->session()->put($webGuard->getName(), $webUser->getAuthIdentifier());
            }

            $webGuard->login($webUser);
        }

        $request->session()->regenerateToken();

        return redirect()->route('customer.login');
    }

    private function throttleKey(int $tenantId, string $email, string $ip): string
    {
        return Str::lower($tenantId.'|'.$email.'|'.$ip);
    }
}
