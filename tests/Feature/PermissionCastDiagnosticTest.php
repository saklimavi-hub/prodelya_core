<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionCastDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_factory_array_input_casts_correctly()
    {
        // Test with array input (correct way)
        $role = Role::factory()->create([
            'permissions' => ['manage_users'],
        ]);

        $role = $role->fresh();

        $this->assertIsArray($role->permissions);
        $this->assertContains('manage_users', $role->permissions);
        $this->assertSame(['manage_users'], $role->permissions);

        // Check raw value
        $raw = $role->getRawOriginal('permissions');
        $this->assertIsString($raw);
        $this->assertStringContainsString('manage_users', $raw);
    }

    public function test_role_factory_double_encoded_input()
    {
        // Test with json_encode input (wrong way)
        $role = Role::factory()->create([
            'permissions' => json_encode(['manage_users']),
        ]);

        $role = $role->fresh();

        // Check what actually happens with double encoding
        $raw = $role->getRawOriginal('permissions');
        $this->assertIsString($raw);

        // Log what we got
        var_dump([
            'raw_permissions' => $raw,
            'casted_permissions' => $role->permissions,
            'casted_type' => gettype($role->permissions),
            'is_array' => is_array($role->permissions),
        ]);

        // If cast works, it should be array
        if (is_array($role->permissions)) {
            $this->assertContains('manage_users', $role->permissions);
        } else {
            // If cast fails, it will be string
            $this->assertIsString($role->permissions);
            $this->assertStringContainsString('manage_users', $role->permissions);
        }
    }

    public function test_permission_helper_with_correct_cast()
    {
        $role = Role::factory()->create([
            'permissions' => ['manage_users'],
        ]);

        $this->assertTrue($role->hasPermission('manage_users'));
        $this->assertFalse($role->hasPermission('other_permission'));
    }
}
