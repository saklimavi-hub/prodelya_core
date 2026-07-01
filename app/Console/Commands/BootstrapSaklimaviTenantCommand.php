<?php

namespace App\Console\Commands;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\SuperAdmin\TenantController;
use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantOnboardingStatusService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use ReflectionMethod;
use Illuminate\Support\Str;

class BootstrapSaklimaviTenantCommand extends Command
{
    protected $signature = 'tenant:bootstrap-saklimavi {--json : JSON cikti ver}';

    protected $description = 'SAKLImavi tenant, owner ve varsayilan onboarding ayarlarini kontrollu sekilde hazirlar.';

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const TENANT_NAME = 'SAKLImavi Reklam Matbaa İletişim Hizmetleri San. Tic. Ltd. Şti.';
    private const OWNER_EMAIL = 'admin@saklimavi.local';
    private const OWNER_NAME = 'SAKLImavi Admin';
    private const OWNER_TEMP_PASSWORD = 'Saklimavi@2026!';

    public function handle(TenantController $tenantController, TenantOnboardingStatusService $statusService, Kernel $kernel): int
    {
        $platformAdmin = User::query()->where('email', 'admin@prodelya.local')->first();

        if (! $platformAdmin instanceof User || ! $platformAdmin->isPlatformAdmin()) {
            $this->error('Platform admin kullanicisi bulunamadi: admin@prodelya.local');

            return self::FAILURE;
        }

        $package = $this->resolvePackage();

        if (! $package instanceof Package) {
            $this->error('Uygun aktif paket bulunamadi.');

            return self::FAILURE;
        }

        $tenant = TenantAccount::query()
            ->where('slug', 'saklimavi')
            ->orWhere('panel_subdomain', 'saklimavi')
            ->first();

        $tenantCreated = false;

        if (! $tenant) {
            $request = Request::create($this->centralUrl('/admin/super-admin/tenants'), 'POST', [
                'name' => self::TENANT_NAME,
                'legal_name' => self::TENANT_NAME,
                'slug' => 'saklimavi',
                'panel_subdomain' => 'saklimavi',
                'custom_domain' => '',
                'portal_domain' => '',
                'status' => 'active',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
            ]);
            $request->setUserResolver(fn (): User => $platformAdmin);

            $tenantController->store($request);

            $tenant = TenantAccount::query()->where('slug', 'saklimavi')->first();
            $tenantCreated = true;
        }

        if (! $tenant instanceof TenantAccount) {
            $this->error('SAKLImavi tenant kaydi olusturulamadi.');

            return self::FAILURE;
        }

        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();
        $ownerCreated = false;
        $ownerConflict = null;
        $temporaryPassword = null;

        if ($owner instanceof User && $owner->isPlatformAdmin()) {
            $ownerConflict = 'platform_admin_conflict';
        } elseif (! $owner) {
            $temporaryPassword = self::OWNER_TEMP_PASSWORD;

            $ownerRequest = Request::create(
                $this->centralUrl('/admin/super-admin/tenants/' . $tenant->id . '/owner'),
                'POST',
                [
                    'name' => self::OWNER_NAME,
                    'email' => self::OWNER_EMAIL,
                    'phone' => '',
                    'password' => $temporaryPassword,
                    'role' => 'tenant_owner',
                    'is_active' => true,
                ]
            );
            $ownerRequest->setUserResolver(fn (): User => $platformAdmin);

            $tenantController->storeOwner($ownerRequest, $tenant);

            $owner = User::query()->where('email', self::OWNER_EMAIL)->first();
            $ownerCreated = true;
        } elseif (! $this->hasTenantOwnerAssignment($tenant, $owner)) {
            $ownerConflict = 'duplicate_email_existing_user';
        }

        $prepareRequest = Request::create(
            $this->centralUrl('/admin/super-admin/tenants/' . $tenant->id . '/prepare-defaults'),
            'POST'
        );
        $prepareRequest->setUserResolver(fn (): User => $platformAdmin);
        $tenantController->prepareDefaults($prepareRequest, $tenant);

        $prepareRequestAgain = Request::create(
            $this->centralUrl('/admin/super-admin/tenants/' . $tenant->id . '/prepare-defaults'),
            'POST'
        );
        $prepareRequestAgain->setUserResolver(fn (): User => $platformAdmin);
        $tenantController->prepareDefaults($prepareRequestAgain, $tenant);

        $status = $statusService->forTenant($tenant->fresh());
        $smoke = $this->runSmokeChecks($kernel, $tenant, $owner, $temporaryPassword);

        $payload = [
            'tenant_created' => $tenantCreated,
            'tenant_id' => $tenant->id,
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'panel_subdomain' => $tenant->panel_subdomain,
                'package_key' => $tenant->package_key,
                'status' => $tenant->status,
                'local_panel_url' => $this->tenantUrl($tenant, '/admin'),
            ],
            'owner_created' => $ownerCreated,
            'owner_conflict' => $ownerConflict,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'is_platform_admin' => (bool) $owner->is_platform_admin,
                'tenant_owner_assignment' => $this->hasTenantOwnerAssignment($tenant, $owner),
            ] : null,
            'temporary_password' => $temporaryPassword,
            'onboarding_status' => $status,
            'smoke' => $smoke,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->info('SAKLImavi Tenant Bootstrap');
            $this->line('Tenant ID: ' . $tenant->id);
            $this->line('Slug/Subdomain: ' . $tenant->slug . ' / ' . $tenant->panel_subdomain);
            $this->line('Paket: ' . $tenant->package_key);
            $this->line('Owner: ' . ($owner?->email ?: '-'));
            $this->line('Local panel: ' . $this->tenantUrl($tenant, '/admin'));

            if ($temporaryPassword) {
                $this->warn('Gecici owner sifresi: ' . $temporaryPassword);
            }

            if ($ownerConflict) {
                $this->warn('Owner olusturma notu: ' . $ownerConflict);
            }
        }

        return self::SUCCESS;
    }

    private function resolvePackage(): ?Package
    {
        foreach (['enterprise', 'suite', 'promotion', 'starter'] as $key) {
            $package = Package::query()
                ->where('key', $key)
                ->where('status', 'active')
                ->first();

            if ($package) {
                return $package;
            }
        }

        return Package::query()
            ->where('status', 'active')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->first();
    }

    private function hasTenantOwnerAssignment(TenantAccount $tenant, User $owner): bool
    {
        $roleId = Role::query()->where('key', 'tenant_owner')->where('is_active', true)->value('id');

        if (! $roleId) {
            return false;
        }

        return UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('user_id', $owner->id)
            ->where('role_id', $roleId)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function runSmokeChecks(Kernel $kernel, TenantAccount $tenant, ?User $owner, ?string $ownerPassword): array
    {
        $ownerSmoke = [
            'owner_login_redirect' => null,
            'owner_tenant_dashboard_status' => null,
            'owner_superadmin_status' => null,
            'owner_demo_status' => null,
            'owner_login_smoke_ran' => false,
        ];

        if ($owner instanceof User) {
            Auth::guard('web')->login($owner);
            $ownerLoginRedirect = $this->resolveLoginRedirectFor($owner, self::CENTRAL_HOST);
            [$ownerTenantResponse] = $this->dispatch(
                $kernel,
                Request::create($this->tenantUrl($tenant, '/admin/dashboard'), 'GET', [], [], [], [
                    'HTTP_HOST' => $tenant->panel_subdomain . '.' . self::CENTRAL_HOST,
                ])
            );

            [$ownerSuperadminResponse] = $this->dispatch(
                $kernel,
                Request::create($this->centralUrl('/admin/super-admin/tenants'), 'GET', [], [], [], [
                    'HTTP_HOST' => self::CENTRAL_HOST,
                ])
            );

            [$ownerDemoResponse] = $this->dispatch(
                $kernel,
                Request::create('http://demo.' . self::CENTRAL_HOST . '/admin/dashboard', 'GET', [], [], [], [
                    'HTTP_HOST' => 'demo.' . self::CENTRAL_HOST,
                ])
            );
            Auth::guard('web')->logout();

            $ownerSmoke = [
                'owner_login_redirect' => $ownerLoginRedirect,
                'owner_tenant_dashboard_status' => $ownerTenantResponse->getStatusCode(),
                'owner_superadmin_status' => $ownerSuperadminResponse->getStatusCode(),
                'owner_demo_status' => $ownerDemoResponse->getStatusCode(),
                'owner_login_smoke_ran' => true,
            ];
        }

        $platformAdmin = User::query()->where('email', 'admin@prodelya.local')->first();
        $platformLoginRedirect = null;
        $platformTenantStatus = null;

        if ($platformAdmin instanceof User) {
            Auth::guard('web')->login($platformAdmin);
            $platformLoginRedirect = $this->resolveLoginRedirectFor($platformAdmin, self::CENTRAL_HOST);
            [$platformTenantResponse] = $this->dispatch(
                $kernel,
                Request::create($this->tenantUrl($tenant, '/admin/dashboard'), 'GET', [], [], [], [
                    'HTTP_HOST' => $tenant->panel_subdomain . '.' . self::CENTRAL_HOST,
                ])
            );
            $platformTenantStatus = $platformTenantResponse->getStatusCode();
            Auth::guard('web')->logout();
        }

        return array_merge($ownerSmoke, [
            'platform_login_redirect' => $platformLoginRedirect,
            'platform_tenant_dashboard_status' => $platformTenantStatus,
        ]);
    }

    /**
     * @return array{0:\Symfony\Component\HttpFoundation\Response,1:array<string,string>}
     */
    private function dispatch(Kernel $kernel, Request $request): array
    {
        $response = $kernel->handle($request);
        $cookies = [];

        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        $kernel->terminate($request, $response);

        return [$response, $cookies];
    }

    private function resolveLoginRedirectFor(User $user, string $host): ?string
    {
        $request = Request::create('http://' . $host . '/login', 'GET', [], [], [], [
            'HTTP_HOST' => $host,
            'HTTPS' => 'off',
        ]);

        Auth::guard('web')->setUser($user);
        URL::forceRootUrl('http://' . $host);

        $method = new ReflectionMethod(LoginController::class, 'redirectPath');
        $method->setAccessible(true);

        /** @var string|null $redirect */
        $redirect = $method->invoke(app(LoginController::class), $request);

        URL::forceRootUrl(null);

        return $redirect;
    }

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
