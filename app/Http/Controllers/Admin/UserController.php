<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    private const ROLE_ORDER = [
        'tenant_owner',
        'admin',
        'sales',
        'graphic',
        'supplier_operator',
        'production',
        'warehouse',
        'delivery',
        'finance',
    ];

    public function __construct(
        private readonly TenantResolver $tenantResolver,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->resolveTenant($request);
        $search = Str::lower(trim((string) $request->input('search')));
        $roleFilter = trim((string) $request->input('role'));

        $memberships = $this->tenantMembershipQuery($tenant)
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $this->buildUserRow($tenant, $rows))
            ->filter(function (array $row) use ($search, $roleFilter): bool {
                if ($search !== '') {
                    $haystack = Str::lower(implode(' ', array_filter([
                        $row['name'],
                        $row['email'],
                        $row['phone'],
                        implode(' ', $row['role_labels']),
                    ])));

                    if (! str_contains($haystack, $search)) {
                        return false;
                    }
                }

                if ($roleFilter !== '' && ! in_array($roleFilter, $row['role_keys'], true)) {
                    return false;
                }

                return true;
            })
            ->sortBy([
                ['is_owner', 'desc'],
                ['name', 'asc'],
            ])
            ->values();

        return view('admin.users.index', [
            'tenant' => $tenant,
            'memberships' => $memberships,
            'roleOptions' => $this->roleOptions(),
            'filters' => [
                'search' => $search,
                'role' => $roleFilter,
            ],
            'summary' => $this->buildTeamSummary($memberships),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.users.create', [
            'tenant' => $this->resolveTenant($request),
            'roleOptions' => $this->roleOptions(),
            'userRecord' => null,
            'selectedRoleKeys' => ['admin'],
            'permissionSummary' => $this->permissionSummaryForRoles(['admin']),
            'guardSummary' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->resolveTenant($request);
        $validated = $this->validatePayload($request, null);

        DB::transaction(function () use ($tenant, $validated): void {
            $user = User::query()->where('email', $validated['email'])->first();

            if ($user instanceof User) {
                $user->forceFill([
                    'name' => $validated['name'],
                    'phone' => $validated['phone'] ?: $user->phone,
                ])->save();
            } else {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?: null,
                    'password' => $validated['password'],
                    'is_platform_admin' => false,
                ]);
            }

            foreach ($validated['role_keys'] as $roleKey) {
                UserRole::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'user_id' => $user->id,
                        'role_id' => $this->roleByKey($roleKey)->id,
                    ],
                    []
                );
            }
        });

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Kullanıcı Abone Firma ekibine eklendi.');
    }

    public function edit(Request $request, User $user): View
    {
        $tenant = $this->resolveTenant($request);
        $user = $this->resolveTenantUser($tenant, $user);
        $selectedRoleKeys = $this->tenantRoleKeysForUser($tenant, $user);

        return view('admin.users.edit', [
            'tenant' => $tenant,
            'userRecord' => $user,
            'roleOptions' => $this->roleOptions(),
            'selectedRoleKeys' => $selectedRoleKeys,
            'permissionSummary' => $this->permissionSummaryForRoles($selectedRoleKeys),
            'guardSummary' => $this->membershipGuardSummary($tenant, $user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $tenant = $this->resolveTenant($request);
        $user = $this->resolveTenantUser($tenant, $user);
        $validated = $this->validatePayload($request, $user);
        $selectedRoleKeys = $validated['role_keys'];

        $this->guardMembershipMutation($tenant, $user, $selectedRoleKeys, $request->user());

        DB::transaction(function () use ($tenant, $user, $validated, $selectedRoleKeys): void {
            $user->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?: null,
            ])->save();

            $roleIds = collect($selectedRoleKeys)
                ->map(fn (string $roleKey) => $this->roleByKey($roleKey)->id)
                ->values();

            UserRole::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('user_id', $user->id)
                ->whereNotIn('role_id', $roleIds->all())
                ->delete();

            foreach ($selectedRoleKeys as $roleKey) {
                UserRole::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'user_id' => $user->id,
                        'role_id' => $this->roleByKey($roleKey)->id,
                    ],
                    []
                );
            }
        });

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Kullanıcı bilgileri güncellendi.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $tenant = $this->resolveTenant($request);
        $user = $this->resolveTenantUser($tenant, $user);

        $this->guardMembershipRemoval($tenant, $user, $request->user());

        UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('user_id', $user->id)
            ->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Kullanıcının Abone Firma erişimi kaldırıldı.');
    }

    private function resolveTenant(Request $request): TenantAccount
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        abort_unless($tenant instanceof TenantAccount, 404);

        return $tenant;
    }

    private function tenantMembershipQuery(TenantAccount $tenant)
    {
        return UserRole::query()
            ->with(['user', 'role'])
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('user', fn ($query) => $query->where('is_platform_admin', false))
            ->whereHas('role', fn ($query) => $query->where('is_active', true));
    }

    private function buildUserRow(TenantAccount $tenant, Collection $rows): array
    {
        /** @var UserRole $first */
        $first = $rows->first();
        $user = $first->user;
        $roles = $rows->pluck('role')->filter();
        $roleKeys = $roles->pluck('key')->filter()->values()->all();
        $hasFinance = $user->hasAnyPermissionInTenant([
            'view_order_finance_summary',
            'manage_payments',
            'mark_payments_received',
        ], $tenant->id);
        $hasOperations = $user->hasAnyPermissionInTenant([
            'manage_procurement_requests',
            'generate_supplier_request_form',
            'manage_stock',
        ], $tenant->id);

        return [
            'user' => $user,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_labels' => $roles->map(fn ($role) => $this->roleDisplayLabel($role->key, $role->name))->values()->all(),
            'role_keys' => $roleKeys,
            'status_label' => 'Aktif',
            'owner_label' => in_array('tenant_owner', $roleKeys, true) ? 'Evet' : 'Hayır',
            'is_owner' => in_array('tenant_owner', $roleKeys, true),
            'has_finance' => $hasFinance,
            'has_operations' => $hasOperations,
            'permission_summary' => $this->permissionSummaryForRoles($roleKeys),
            'last_login_at' => $user->last_login_at?->format('d.m.Y H:i') ?: 'Henüz giriş yok',
            'created_at' => $user->created_at?->format('d.m.Y H:i') ?: '-',
        ];
    }

    private function roleOptions(): array
    {
        $roles = Role::query()
            ->whereIn('key', self::ROLE_ORDER)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Role $role) => array_search($role->key, self::ROLE_ORDER, true));

        return $roles->map(fn (Role $role) => [
            'key' => $role->key,
            'label' => $this->roleDisplayLabel($role->key, $role->name),
            'description' => $role->description,
        ])->values()->all();
    }

    private function permissionSummaryForRoles(array $roleKeys): array
    {
        $permissions = [];

        foreach (array_values(array_unique($roleKeys)) as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();

            if (! $role instanceof Role) {
                continue;
            }

            foreach ($this->normalizedRolePermissions($role) as $permissionKey) {
                $permissions[$permissionKey] = $this->permissionLabel($permissionKey);
            }
        }

        return array_values($permissions);
    }

    private function normalizedRolePermissions(Role $role): array
    {
        $permissions = $role->permissions;

        if ($permissions === '*') {
            return [
                'manage_users',
                'manage_roles',
                'create_quotes',
                'edit_orders',
                'view_order_finance_summary',
                'manage_payments',
                'manage_procurement_requests',
                'manage_stock',
                'manage_tenant_settings',
            ];
        }

        if (! is_array($permissions)) {
            return [];
        }

        $flattened = [];
        array_walk_recursive($permissions, function ($value) use (&$flattened): void {
            if (is_string($value)) {
                $flattened[] = $value;
            }
        });

        return array_values(array_unique($flattened));
    }

    private function permissionLabel(string $permissionKey): string
    {
        return match ($permissionKey) {
            'manage_users' => 'Kullanıcıları yönetir',
            'manage_roles' => 'Rol ve yetkileri yönetir',
            'create_quotes', 'edit_quotes', 'approve_quotes' => 'Teklifleri yönetir',
            'create_orders', 'edit_orders', 'approve_orders' => 'Siparişleri yönetir',
            'view_order_finance_summary' => 'Finans özetini görür',
            'manage_payments' => 'Ödeme ekler',
            'mark_payments_received' => 'Tahsilat işaretler',
            'manage_procurement_requests' => 'Tedarik yönetir',
            'manage_stock' => 'Üretim / stok yönetir',
            'manage_tenant_settings' => 'Ayarları yönetir',
            default => Str::headline(str_replace('_', ' ', $permissionKey)),
        };
    }

    private function roleDisplayLabel(string $roleKey, ?string $fallback = null): string
    {
        return match ($roleKey) {
            'tenant_owner' => 'Hesap Sahibi',
            'admin' => 'Yönetici',
            'sales' => 'Satış',
            'graphic' => 'Grafik',
            'supplier_operator' => 'Tedarik',
            'production' => 'Üretim',
            'warehouse' => 'Depo',
            'delivery' => 'Teslimat',
            'finance' => 'Finans',
            default => $fallback ?: Str::headline($roleKey),
        };
    }

    private function buildTeamSummary(Collection $memberships): array
    {
        return [
            'total' => $memberships->count(),
            'active' => $memberships->count(),
            'owner_count' => $memberships->filter(fn (array $row) => $row['is_owner'])->count(),
            'has_finance' => $memberships->contains(fn (array $row) => $row['has_finance']),
            'has_operations' => $memberships->contains(fn (array $row) => $row['has_operations']),
            'last_user_created_at' => $memberships->pluck('user.created_at')->filter()->max()?->format('d.m.Y H:i') ?: 'Takip edilmiyor',
            'status' => $memberships->contains(fn (array $row) => $row['is_owner']) ? 'Hazır' : 'Eksik',
        ];
    }

    private function tenantRoleKeysForUser(TenantAccount $tenant, User $user): array
    {
        return UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('user_id', $user->id)
            ->with('role')
            ->get()
            ->pluck('role.key')
            ->filter()
            ->values()
            ->all();
    }

    private function resolveTenantUser(TenantAccount $tenant, User $user): User
    {
        abort_if($user->isPlatformAdmin(), 403);
        abort_unless($user->belongsToTenant($tenant), 403);

        return $user;
    }

    private function validatePayload(Request $request, ?User $user): array
    {
        $tenant = $this->resolveTenant($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
            'role_keys' => ['required', 'array', 'min:1'],
            'role_keys.*' => ['required', Rule::in(self::ROLE_ORDER)],
        ], [
            'role_keys.required' => 'En az bir rol seçilmelidir.',
            'role_keys.min' => 'En az bir rol seçilmelidir.',
        ]);

        $existingUser = User::query()
            ->where('email', $validated['email'])
            ->when($user, fn ($query) => $query->whereKeyNot($user->id))
            ->first();

        if ($existingUser instanceof User) {
            if ($existingUser->isPlatformAdmin()) {
                throw ValidationException::withMessages([
                    'email' => 'Platform admin kullanıcısı tenant ekibine bağlanamaz.',
                ]);
            }

            $hasOtherTenantRole = $existingUser->userRoles()
                ->where('tenant_account_id', '!=', $tenant->id)
                ->exists();

            if ($hasOtherTenantRole) {
                throw ValidationException::withMessages([
                    'email' => 'Bu e-posta başka bir Abone Firma kullanıcısına bağlı.',
                ]);
            }

            if ($user === null && $existingUser->belongsToTenant($tenant)) {
                throw ValidationException::withMessages([
                    'email' => 'Bu e-posta zaten bu Abone Firma ekibinde kayıtlı.',
                ]);
            }
        }

        return $validated;
    }

    private function roleByKey(string $roleKey): Role
    {
        return Role::query()->where('key', $roleKey)->where('is_active', true)->firstOrFail();
    }

    private function guardMembershipMutation(TenantAccount $tenant, User $targetUser, array $selectedRoleKeys, User $actingUser): void
    {
        $currentRoleKeys = $this->tenantRoleKeysForUser($tenant, $targetUser);

        if (in_array('tenant_owner', $currentRoleKeys, true)
            && ! in_array('tenant_owner', $selectedRoleKeys, true)
            && $this->ownerCount($tenant) <= 1) {
            throw ValidationException::withMessages([
                'role_keys' => 'Son owner rolü kaldırılamaz.',
            ]);
        }

        if ($actingUser->is($targetUser) && ! $this->selectedRolesCanManageUsers($selectedRoleKeys)) {
            throw ValidationException::withMessages([
                'role_keys' => 'Kendi hesabınızdan kullanıcı yönetimi yetkisini kaldıramazsınız.',
            ]);
        }
    }

    private function guardMembershipRemoval(TenantAccount $tenant, User $targetUser, User $actingUser): void
    {
        $roleKeys = $this->tenantRoleKeysForUser($tenant, $targetUser);

        if (in_array('tenant_owner', $roleKeys, true) && $this->ownerCount($tenant) <= 1) {
            abort(403, 'Son owner silinemez.');
        }

        if ($actingUser->is($targetUser)) {
            abort(403, 'Kendi Abone Firma erişiminizi bu ekrandan kaldıramazsınız.');
        }
    }

    private function ownerCount(TenantAccount $tenant): int
    {
        return (int) UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('role', fn ($query) => $query->where('key', 'tenant_owner')->where('is_active', true))
            ->whereHas('user', fn ($query) => $query->where('is_platform_admin', false))
            ->count();
    }

    private function selectedRolesCanManageUsers(array $roleKeys): bool
    {
        return collect($roleKeys)->contains(function (string $roleKey): bool {
            $role = Role::query()->where('key', $roleKey)->first();

            return $role instanceof Role
                && ($role->permissions === '*' || in_array('manage_users', $this->normalizedRolePermissions($role), true));
        });
    }

    private function membershipGuardSummary(TenantAccount $tenant, User $user): array
    {
        $roleKeys = $this->tenantRoleKeysForUser($tenant, $user);

        return [
            'is_owner' => in_array('tenant_owner', $roleKeys, true),
            'owner_count' => $this->ownerCount($tenant),
            'is_self_manage_lock_risk' => auth()->id() === $user->id,
        ];
    }
}
