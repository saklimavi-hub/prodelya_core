<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;

class DefaultRolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultRoles = config('prodelya_permissions.default_roles', []);

        foreach ($defaultRoles as $roleKey => $roleConfig) {
            $role = Role::query()->updateOrCreate(
                ['key' => $roleKey],
                [
                    'name' => $roleConfig['name'],
                    'description' => $roleConfig['description'],
                    'permissions' => $roleConfig['permissions'],
                    'is_system' => $roleConfig['is_system'] ?? false,
                    'is_active' => true,
                ]
            );

            $this->command->info("Ensured role: {$role->name}");
        }

        // Create a default admin user for development
        $this->createDefaultAdminUser();
    }

    /**
     * Create default admin user
     */
    private function createDefaultAdminUser()
    {
        $adminRole = Role::where('key', 'admin')->first();
        $defaultTenantId = TenantAccount::query()
            ->where('panel_subdomain', 'demo')
            ->orWhere('slug', 'demo-sirketi')
            ->orderBy('id')
            ->value('id');
        
        if ($adminRole && $defaultTenantId) {
            $user = User::query()->firstOrNew([
                'email' => 'admin@prodelya.local',
            ]);

            $user->forceFill([
                'name' => $user->name ?: 'Admin User',
                'is_platform_admin' => true,
            ]);

            if (! $user->exists) {
                $user->password = 'password';
            }

            $user->save();

            // Assign admin role to the user for the default demo tenant.
            $user->userRoles()->updateOrCreate(
                [
                    'role_id' => $adminRole->id,
                    'tenant_account_id' => $defaultTenantId,
                ],
                []
            );

            $this->command->info("Ensured default admin user: {$user->email}");

            if (! $user->wasRecentlyCreated) {
                return;
            }

            $this->command->warn("Default password: password (change in production!)");
        }
    }
}
