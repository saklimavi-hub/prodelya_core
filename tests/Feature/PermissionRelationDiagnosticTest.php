<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionRelationDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_permission_relation_chain(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $user = User::factory()->create();
        $role = Role::query()->where('key', 'admin')->firstOrFail();

        UserRole::factory()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        $this->assertTrue($role->hasPermission('manage_users'));
        $this->assertTrue($user->hasPermissionInTenant('manage_users', $tenant->id));
    }
}
