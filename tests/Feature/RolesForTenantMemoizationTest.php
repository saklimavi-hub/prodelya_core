<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * User::rolesForTenant() was previously uncached, issuing a fresh query on
 * every hasPermissionInTenant()/hasAnyPermissionInTenant() call - which
 * multiplied with every new permission-gated admin_menu.php entry (each
 * render of the menu re-queried roles for every gated item). This proves
 * the added memoization (scoped to the current HTTP Request instance, not
 * to the User model instance - see rolesForTenant()'s docblock for why)
 * actually avoids repeat queries, and -critically- that the cache never
 * leaks across different users, different tenants for the same user, or
 * across two separate HTTP requests - and that it can never go stale: any
 * UserRole or Role write invalidates it immediately, even without a request
 * boundary in between (the scenario that broke an Artisan command test:
 * repair the role, then re-check permissions in the same PHP process).
 */
class RolesForTenantMemoizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenantA;
    private TenantAccount $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = TenantAccount::query()->firstOrFail();
        $this->tenantB = TenantAccount::query()->create([
            'name' => 'Roles Cache Tenant B',
            'legal_name' => 'Roles Cache Tenant B Ltd.',
            'slug' => 'roles-cache-tenant-b',
            'panel_subdomain' => 'roles-cache-tenant-b',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    public function test_repeated_calls_for_same_user_and_tenant_do_not_reissue_query(): void
    {
        $user = $this->makeUser('memo-repeat@example.test', $this->tenantA, 'sales');

        $first = $user->rolesForTenant($this->tenantA->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $second = $user->rolesForTenant($this->tenantA->id);
        $third = $user->rolesForTenant($this->tenantA->id);

        $this->assertSame(0, $queryCount, 'A cached rolesForTenant() call must not issue any new query.');
        $this->assertSame($first->pluck('key')->all(), $second->pluck('key')->all());
        $this->assertSame($first->pluck('key')->all(), $third->pluck('key')->all());
    }

    public function test_cache_does_not_leak_between_different_user_instances(): void
    {
        $salesUser = $this->makeUser('memo-sales@example.test', $this->tenantA, 'sales');
        $productionUser = $this->makeUser('memo-production@example.test', $this->tenantA, 'production');

        // Warm both caches.
        $salesRoles = $salesUser->rolesForTenant($this->tenantA->id)->pluck('key')->all();
        $productionRoles = $productionUser->rolesForTenant($this->tenantA->id)->pluck('key')->all();

        $this->assertSame(['sales'], $salesRoles);
        $this->assertSame(['production'], $productionRoles);

        // Re-reading each (from cache) must still return only that user's own role,
        // proving the memoization key is not accidentally global/shared.
        $this->assertSame(['sales'], $salesUser->rolesForTenant($this->tenantA->id)->pluck('key')->all());
        $this->assertSame(['production'], $productionUser->rolesForTenant($this->tenantA->id)->pluck('key')->all());
    }

    public function test_cache_is_scoped_per_tenant_for_the_same_user(): void
    {
        $user = $this->makeUser('memo-multi-tenant@example.test', $this->tenantA, 'sales');
        UserRole::query()->create([
            'tenant_account_id' => $this->tenantB->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', 'production')->firstOrFail()->id,
        ]);

        $rolesInA = $user->rolesForTenant($this->tenantA->id)->pluck('key')->all();
        $rolesInB = $user->rolesForTenant($this->tenantB->id)->pluck('key')->all();

        $this->assertSame(['sales'], $rolesInA);
        $this->assertSame(['production'], $rolesInB);

        // Re-reading (from cache, two distinct keys) must still resolve correctly per tenant.
        $this->assertSame(['sales'], $user->rolesForTenant($this->tenantA->id)->pluck('key')->all());
        $this->assertSame(['production'], $user->rolesForTenant($this->tenantB->id)->pluck('key')->all());
    }

    public function test_a_new_http_request_does_not_inherit_a_previous_requests_cache(): void
    {
        $user = $this->makeUser('memo-fresh-request@example.test', $this->tenantA, 'sales');

        // Simulate request #1 - this is exactly what
        // Illuminate\Foundation\Http\Kernel::handle() does on every real
        // (and every simulated test) HTTP request: rebind a fresh Request
        // instance into the container.
        app()->instance('request', new \Illuminate\Http\Request());
        $this->assertSame(['sales'], $user->rolesForTenant($this->tenantA->id)->pluck('key')->all());

        UserRole::query()->create([
            'tenant_account_id' => $this->tenantA->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', 'production')->firstOrFail()->id,
        ]);

        // Simulate request #2 (same User PHP object, as can happen in tests
        // and in long-running workers - but a genuinely new HTTP request).
        app()->instance('request', new \Illuminate\Http\Request());

        $freshRoles = $user->rolesForTenant($this->tenantA->id)->pluck('key')->sort()->values()->all();

        $this->assertSame(
            ['production', 'sales'],
            $freshRoles,
            'A new request must never inherit a previous request\'s cached roles.'
        );
    }

    public function test_a_role_assignment_change_immediately_invalidates_the_cache_even_within_the_same_request(): void
    {
        // This is the guarantee that actually matters for Artisan commands /
        // console contexts, which never go through Kernel::handle() and so
        // never get a fresh Request instance mid-process: a UserRole write
        // must invalidate the cache on its own, regardless of any request
        // boundary. (EnsureTenantAdminPermissionsCommand's real-run repair
        // followed immediately by a permission re-check, in the same PHP
        // process, is exactly this scenario.)
        $user = $this->makeUser('memo-immediate-invalidate@example.test', $this->tenantA, 'sales');

        $this->assertSame(['sales'], $user->rolesForTenant($this->tenantA->id)->pluck('key')->all());

        UserRole::query()->create([
            'tenant_account_id' => $this->tenantA->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', 'production')->firstOrFail()->id,
        ]);

        $this->assertSame(
            ['production', 'sales'],
            $user->rolesForTenant($this->tenantA->id)->pluck('key')->sort()->values()->all(),
            'A UserRole write must invalidate the cache immediately, with no request boundary needed.'
        );
    }

    public function test_a_roles_permission_set_change_also_invalidates_the_cache(): void
    {
        $user = $this->makeUser('memo-role-permission-change@example.test', $this->tenantA, 'sales');

        $this->assertTrue($user->hasPermissionInTenant('create_customers', $this->tenantA->id));

        Role::query()->where('key', 'sales')->firstOrFail()->update(['permissions' => []]);

        $this->assertFalse(
            $user->hasPermissionInTenant('create_customers', $this->tenantA->id),
            'A Role permissions write must invalidate the cache immediately, with no request boundary needed.'
        );
    }

    private function makeUser(string $email, TenantAccount $tenant, string $roleKey): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
        ]);

        return $user;
    }
}
