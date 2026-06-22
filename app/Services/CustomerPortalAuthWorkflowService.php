<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CustomerPortalUser;
use App\Models\TenantAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class CustomerPortalAuthWorkflowService
{
    private const INVITE_EXPIRE_HOURS = 168;
    private const RESET_EXPIRE_HOURS = 2;

    public function __construct(
        protected CustomerPortalMailService $mailService,
    ) {
    }

    public function createPortalUser(
        TenantAccount $tenant,
        Company $company,
        array $data,
        User $actor,
        string $currentHost,
    ): array {
        /** @var CompanyContact|null $contact */
        $contact = null;

        if (filled($data['company_contact_id'] ?? null)) {
            $contact = CompanyContact::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('company_id', $company->id)
                ->whereKey((int) $data['company_contact_id'])
                ->first();
        }

        $portalUser = CustomerPortalUser::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'company_contact_id' => $contact?->id,
            'name' => trim((string) $data['name']),
            'email' => Str::lower(trim((string) $data['email'])),
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'password' => null,
            'status' => CustomerPortalUser::STATUS_INVITED,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return $this->issueInvite($tenant, $portalUser, $actor, $currentHost);
    }

    public function issueInvite(
        TenantAccount $tenant,
        CustomerPortalUser $portalUser,
        ?User $actor,
        string $currentHost,
    ): array {
        $plainToken = $this->generatePlainToken();
        $expiresAt = now()->addHours(self::INVITE_EXPIRE_HOURS);

        $portalUser->forceFill([
            'invite_token' => $this->hashToken($plainToken),
            'invited_at' => now(),
            'invite_expires_at' => $expiresAt,
            'status' => $portalUser->isActive()
                ? CustomerPortalUser::STATUS_ACTIVE
                : ($portalUser->status === CustomerPortalUser::STATUS_PASSIVE || $portalUser->status === CustomerPortalUser::STATUS_SUSPENDED
                    ? $portalUser->status
                    : CustomerPortalUser::STATUS_INVITED),
            'updated_by' => $actor?->id,
        ])->save();

        $inviteUrl = $this->tenantPortalUrl($tenant, '/musteri-davet/' . $plainToken, $currentHost);
        $mailStatus = $this->mailService->sendInvite($tenant, $portalUser->fresh(), $inviteUrl, $actor);

        return [
            'user' => $portalUser->fresh(),
            'invite_link' => $inviteUrl,
            'mail_status' => $mailStatus,
        ];
    }

    public function findUserByInviteToken(TenantAccount $tenant, string $plainToken): ?CustomerPortalUser
    {
        return CustomerPortalUser::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('invite_token', $this->hashToken($plainToken))
            ->first();
    }

    public function acceptInvite(TenantAccount $tenant, CustomerPortalUser $portalUser, string $plainToken, string $password): CustomerPortalUser
    {
        if (! $portalUser->belongsToTenant($tenant) || ! $this->isValidInviteToken($portalUser, $plainToken)) {
            abort(404);
        }

        $portalUser->forceFill([
            'password' => $password,
            'status' => CustomerPortalUser::STATUS_ACTIVE,
            'password_set_at' => now(),
            'email_verified_at' => now(),
            'invite_token' => null,
            'invite_expires_at' => null,
            'password_reset_token' => null,
            'password_reset_expires_at' => null,
            'remember_token' => Str::random(60),
            'updated_by' => Auth::guard('web')->id(),
        ])->save();

        return $portalUser->fresh();
    }

    public function requestPasswordReset(TenantAccount $tenant, string $email, string $currentHost): void
    {
        $portalUser = CustomerPortalUser::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])
            ->first();

        if (! $portalUser instanceof CustomerPortalUser) {
            return;
        }

        if (! $portalUser->isActive() || ! (bool) $portalUser->company?->portal_enabled) {
            return;
        }

        $plainToken = $this->generatePlainToken();
        $expiresAt = now()->addHours(self::RESET_EXPIRE_HOURS);

        $portalUser->forceFill([
            'password_reset_token' => $this->hashToken($plainToken),
            'password_reset_expires_at' => $expiresAt,
        ])->save();

        $resetUrl = $this->tenantPortalUrl($tenant, '/musteri-sifre-yenile/' . $plainToken, $currentHost);
        $this->mailService->sendPasswordReset($tenant, $portalUser->fresh(), $resetUrl, null);
    }

    public function findUserByResetToken(TenantAccount $tenant, string $plainToken): ?CustomerPortalUser
    {
        return CustomerPortalUser::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('password_reset_token', $this->hashToken($plainToken))
            ->first();
    }

    public function resetPassword(TenantAccount $tenant, CustomerPortalUser $portalUser, string $plainToken, string $password): CustomerPortalUser
    {
        if (! $portalUser->belongsToTenant($tenant) || ! $this->isValidResetToken($portalUser, $plainToken)) {
            abort(404);
        }

        $portalUser->forceFill([
            'password' => $password,
            'password_set_at' => $portalUser->password_set_at ?: now(),
            'password_reset_token' => null,
            'password_reset_expires_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $this->mailService->logPasswordChanged($tenant, $portalUser->fresh());

        return $portalUser->fresh();
    }

    public function markStatus(TenantAccount $tenant, Company $company, CustomerPortalUser $portalUser, string $status, User $actor): CustomerPortalUser
    {
        if (! $portalUser->belongsToTenant($tenant) || ! $portalUser->belongsToCompany($company)) {
            abort(404);
        }

        $portalUser->forceFill([
            'status' => $status,
            'updated_by' => $actor->id,
        ])->save();

        return $portalUser->fresh();
    }

    public function isValidInviteToken(CustomerPortalUser $portalUser, string $plainToken): bool
    {
        return filled($portalUser->invite_token)
            && hash_equals((string) $portalUser->invite_token, $this->hashToken($plainToken))
            && $this->isFuture($portalUser->invite_expires_at);
    }

    public function isValidResetToken(CustomerPortalUser $portalUser, string $plainToken): bool
    {
        return filled($portalUser->password_reset_token)
            && hash_equals((string) $portalUser->password_reset_token, $this->hashToken($plainToken))
            && $this->isFuture($portalUser->password_reset_expires_at)
            && $portalUser->isActive()
            && (bool) $portalUser->company?->portal_enabled;
    }

    public function tenantPortalUrl(TenantAccount $tenant, string $path, string $currentHost): string
    {
        return $this->portalBaseUrl($tenant, $currentHost) . $path;
    }

    private function portalBaseUrl(TenantAccount $tenant, string $currentHost): string
    {
        $scheme = (string) (parse_url((string) Config::get('app.url'), PHP_URL_SCHEME) ?: 'http');
        $host = $this->portalHost($tenant, $currentHost);

        return $scheme . '://' . $host;
    }

    private function portalHost(TenantAccount $tenant, string $currentHost): string
    {
        $portalDomain = trim((string) $tenant->portal_domain);

        if ($portalDomain !== '') {
            return $portalDomain;
        }

        $customDomain = trim((string) $tenant->custom_domain);

        if ($customDomain !== '') {
            return $customDomain;
        }

        $centralLocalHost = $this->centralLocalHost($currentHost);

        if ($centralLocalHost !== null) {
            return $centralLocalHost;
        }

        $host = $currentHost;
        $subdomain = trim((string) $tenant->panel_subdomain);

        if ($subdomain !== '' && ! str_starts_with($host, $subdomain . '.')) {
            $host = $subdomain . '.' . ltrim($host, '.');
        }

        return $host;
    }

    private function centralLocalHost(string $currentHost): ?string
    {
        if (! app()->environment(['local', 'testing'])) {
            return null;
        }

        $host = (string) parse_url((string) Config::get('app.url'), PHP_URL_HOST);

        if ($host === '') {
            return null;
        }

        if (
            $currentHost !== $host
            && ! str_ends_with($currentHost, '.' . $host)
            && ! in_array($currentHost, ['localhost', '127.0.0.1'], true)
        ) {
            return null;
        }

        return $host;
    }

    private function generatePlainToken(): string
    {
        return Str::random(64);
    }

    private function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function isFuture(mixed $value): bool
    {
        return $value instanceof CarbonInterface && $value->isFuture();
    }
}
