<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TenantAccount;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect($this->redirectPath(request()));
        }

        return view('auth.login');
    }

    /**
     * Handle a login request to the application.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = Auth::user();

            if ($user) {
                $this->persistLastLoginMetadata($user, $request);
            }

            $this->forgetUnsafeIntendedUrlForTenantUser($request, $user);

            return redirect($this->redirectPath($request));
        }

        return back()->withErrors([
            'email' => 'Bu kimlik bilgileri kayıtlarımızla eşleşmiyor.',
        ])->onlyInput('email');
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request)
    {
        if ($this->shouldUseLocalSqliteGuardLogoutFallback()) {
            Auth::guard('web')->logoutCurrentDevice();
        } else {
            Auth::logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Get the post login redirect path.
     */
    protected function redirectPath(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return '/admin/dashboard';
        }

        if ($user->isPlatformAdmin()) {
            return $this->centralDashboardUrl($request);
        }

        $tenant = $user->preferredTenant();

        if (!$tenant instanceof TenantAccount) {
            return '/admin/dashboard';
        }

        $tenantUrl = $this->tenantDashboardUrl($request, $tenant);

        return $tenantUrl ?: '/admin/dashboard';
    }

    protected function forgetUnsafeIntendedUrlForTenantUser(Request $request, mixed $user): void
    {
        if (! $user || $user->isPlatformAdmin()) {
            return;
        }

        $intended = (string) $request->session()->get('url.intended', '');

        if ($intended === '') {
            return;
        }

        $path = '/' . ltrim((string) parse_url($intended, PHP_URL_PATH), '/');

        if (str_starts_with($path, '/admin/super-admin')) {
            $request->session()->forget('url.intended');
        }
    }

    protected function tenantDashboardUrl(Request $request, TenantAccount $tenant): ?string
    {
        $host = strtolower(trim((string) $request->getHost()));
        $scheme = $this->preferredScheme($request);

        $candidateHosts = array_filter([
            trim((string) $tenant->custom_domain),
            trim((string) $tenant->portal_domain),
        ]);

        foreach ($candidateHosts as $candidateHost) {
            if ($candidateHost !== '') {
                return $scheme . '://' . $candidateHost . '/admin/dashboard';
            }
        }

        $subdomain = trim((string) $tenant->panel_subdomain);

        if ($subdomain === '') {
            return null;
        }

        if ($host !== '' && str_starts_with($host, $subdomain . '.')) {
            return '/admin/dashboard';
        }

        $panelDomain = $this->panelDomain($host);

        if ($panelDomain === '' || !str_contains($panelDomain, '.')) {
            return null;
        }

        return $scheme . '://' . $subdomain . '.' . $panelDomain . '/admin/dashboard';
    }

    protected function panelDomain(string $requestHost = ''): string
    {
        $configured = strtolower(trim((string) config('prodelya_domains.panel_domain')));

        if ($configured !== '') {
            return $configured;
        }

        $host = strtolower(trim($requestHost));

        if ($host !== '' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $host;
        }

        $fallback = strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST)));

        return $fallback;
    }

    protected function preferredScheme(Request $request): string
    {
        if (config('prodelya_domains.force_https')) {
            return 'https';
        }

        return $request->getScheme();
    }

    protected function centralDashboardUrl(Request $request): string
    {
        $scheme = $this->preferredScheme($request);
        $host = $this->centralHost($request);

        if ($host === '') {
            return route('admin.super.dashboard');
        }

        return $scheme . '://' . $host . '/admin/super-admin/dashboard';
    }

    protected function centralHost(Request $request): string
    {
        $hosts = config('prodelya_domains.central_hosts', []);
        $requestHost = strtolower(trim((string) $request->getHost()));

        if (is_array($hosts)) {
            foreach ($hosts as $host) {
                $normalized = strtolower(trim((string) $host));

                if ($normalized !== '' && $normalized === $requestHost) {
                    return $normalized;
                }
            }

            foreach ($hosts as $host) {
                $normalized = strtolower(trim((string) $host));

                if ($normalized !== '' && !$this->isLocalLikeHost($normalized)) {
                    return $normalized;
                }
            }

            foreach ($hosts as $host) {
                $normalized = strtolower(trim((string) $host));

                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        if ($requestHost !== '') {
            return $requestHost;
        }

        return strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST)));
    }

    protected function isLocalLikeHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === ''
            || in_array($host, ['localhost', '127.0.0.1'], true)
            || Str::endsWith($host, ['.test', '.local']);
    }

    protected function persistLastLoginMetadata(mixed $user, Request $request): void
    {
        if (!$user) {
            return;
        }

        if ($this->shouldSkipLastLoginWriteForLocalSqlite()) {
            return;
        }

        try {
            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->save();
        } catch (QueryException $exception) {
            if ($this->shouldIgnoreLocalSqliteLockException($exception)) {
                Log::warning('Local SQLite login metadata write skipped due to database lock.', [
                    'user_id' => $user->id ?? null,
                ]);

                return;
            }

            throw $exception;
        }
    }

    protected function shouldSkipLastLoginWriteForLocalSqlite(): bool
    {
        return in_array(strtolower((string) config('app.env')), ['local', 'development'], true)
            && strtolower((string) config('database.default')) === 'sqlite';
    }

    protected function shouldUseLocalSqliteGuardLogoutFallback(): bool
    {
        return $this->shouldSkipLastLoginWriteForLocalSqlite();
    }

    protected function shouldIgnoreLocalSqliteLockException(QueryException $exception): bool
    {
        if (! $this->shouldSkipLastLoginWriteForLocalSqlite()) {
            return false;
        }

        return str_contains(strtolower($exception->getMessage()), 'database is locked');
    }
}
