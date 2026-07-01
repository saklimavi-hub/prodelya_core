<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class EnsureTenantAdminPermissionsCommand extends Command
{
    private const TARGET_ROLE_KEYS = ['tenant_owner', 'tenant_admin', 'admin'];

    private const REQUIRED_FINANCE_PERMISSIONS = [
        'view_order_finance_summary',
        'view_payment_details',
        'manage_payments',
        'mark_payments_received',
    ];

    protected $signature = 'prodelya:ensure-tenant-admin-permissions
        {--tenant= : Hedef abone firma slug/panel_subdomain/name}
        {--tenant-id= : Hedef abone firma numeric id}
        {--email= : Belirli bir kullanici e-postasi ile daralt}
        {--dry-run : Veri yazmadan onarim planini raporlar}';

    protected $description = 'Abone firma admin/owner kullanicilarinin finans yetkisini guvenli sekilde dogrular; gerekirse finance rol atamasini onarir.';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        if (! $tenant) {
            return self::FAILURE;
        }

        $financeRole = Role::query()
            ->where('key', 'finance')
            ->where('is_active', true)
            ->first();

        if (! $financeRole) {
            $this->error('Aktif finance rolü bulunamadı.');

            return self::FAILURE;
        }

        $assignments = $this->targetAssignments($tenant);

        if ($assignments->isEmpty()) {
            $this->warn('Hedef abone firma için admin/owner kapsamına giren kullanıcı bulunamadı.');

            return self::SUCCESS;
        }

        $plannedRepairs = collect();

        $this->line('Abone Firma Hesabı: ' . $tenant->name);
        $this->line('Slug: ' . $tenant->slug);
        $this->line('Hedef kullanıcı sayısı: ' . $assignments->count());

        foreach ($assignments as $assignment) {
            $user = $assignment['user'];
            $roleKeys = $assignment['role_keys'];
            $effectiveFinance = $assignment['effective_finance'];
            $hasFinanceRole = $assignment['has_finance_role'];

            $status = $effectiveFinance ? 'hazır' : ($hasFinanceRole ? 'incele' : 'onarım_adayı');
            $this->line(sprintf(
                '- %s | roller: %s | finans erişimi: %s | durum: %s',
                $user->email,
                implode(', ', $roleKeys),
                $effectiveFinance ? 'var' : 'yok',
                $status
            ));

            if (! $effectiveFinance && ! $hasFinanceRole) {
                $plannedRepairs->push([
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }
        }

        $this->line('Planlanan finance rol ataması: ' . $plannedRepairs->count());

        if ($this->option('dry-run')) {
            $this->info('Dry-run: Veri yazılmadı.');

            return self::SUCCESS;
        }

        $applied = 0;

        foreach ($plannedRepairs as $repair) {
            UserRole::query()->firstOrCreate([
                'tenant_account_id' => $tenant->id,
                'user_id' => $repair['user_id'],
                'role_id' => $financeRole->id,
            ]);

            $applied++;
        }

        $this->info('Permission repair tamamlandı.');
        $this->line('Eklenen finance rol ataması: ' . $applied);

        return self::SUCCESS;
    }

    private function resolveTenant(): ?TenantAccount
    {
        $tenantId = $this->option('tenant-id');
        $tenantKey = trim((string) $this->option('tenant'));

        if ($tenantId === null && $tenantKey === '') {
            $this->error('--tenant veya --tenant-id zorunludur.');

            return null;
        }

        if ($tenantId !== null && $tenantKey !== '') {
            $this->error('Aynı anda yalnız bir hedef verin: --tenant veya --tenant-id.');

            return null;
        }

        $tenant = $tenantId !== null
            ? TenantAccount::query()->find((int) $tenantId)
            : TenantAccount::query()
                ->where(function ($query) use ($tenantKey) {
                    $query->where('slug', $tenantKey)
                        ->orWhere('panel_subdomain', $tenantKey)
                        ->orWhere('name', $tenantKey);
                })
                ->first();

        if (! $tenant) {
            $this->error('Hedef abone firma hesabı bulunamadı.');
        }

        return $tenant;
    }

    private function targetAssignments(TenantAccount $tenant): Collection
    {
        $emailFilter = trim((string) $this->option('email'));

        $userRoles = UserRole::query()
            ->with(['user', 'role'])
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('role', fn ($query) => $query->whereIn('key', self::TARGET_ROLE_KEYS)->where('is_active', true))
            ->when($emailFilter !== '', fn ($query) => $query->whereHas('user', fn ($builder) => $builder->where('email', $emailFilter)))
            ->get()
            ->filter(fn (UserRole $userRole) => $userRole->user && $userRole->role);

        return $userRoles
            ->groupBy('user_id')
            ->map(function (Collection $group) use ($tenant) {
                /** @var UserRole $first */
                $first = $group->first();
                $user = $first->user;
                $roleKeys = $group
                    ->map(fn (UserRole $userRole) => (string) $userRole->role->key)
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'user' => $user,
                    'role_keys' => $roleKeys,
                    'has_finance_role' => $user->hasRoleInTenant('finance', $tenant->id),
                    'effective_finance' => $user->hasAnyPermissionInTenant(self::REQUIRED_FINANCE_PERMISSIONS, $tenant->id),
                ];
            })
            ->values();
    }
}
