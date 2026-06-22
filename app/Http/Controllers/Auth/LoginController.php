<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TenantAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect()->intended($this->redirectPath(request()));
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
                $user->forceFill([
                    'last_login_at' => now(),
                    'last_login_ip' => $request->ip(),
                ])->save();
            }
            
            return redirect()->intended($this->redirectPath($request));
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
        Auth::logout();
        
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
            return route('admin.super.dashboard');
        }

        $tenant = $user->preferredTenant();

        if (!$tenant instanceof TenantAccount) {
            return '/admin/dashboard';
        }

        $tenantUrl = $this->tenantDashboardUrl($request, $tenant);

        return $tenantUrl ?: '/admin/dashboard';
    }

    protected function tenantDashboardUrl(Request $request, TenantAccount $tenant): ?string
    {
        $host = strtolower(trim((string) $request->getHost()));
        $scheme = $request->getScheme();

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

        if ($subdomain === '' || $host === '' || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        if (str_starts_with($host, $subdomain . '.')) {
            return '/admin/dashboard';
        }

        if (!str_contains($host, '.')) {
            return null;
        }

        return $scheme . '://' . $subdomain . '.' . $host . '/admin/dashboard';
    }
}
