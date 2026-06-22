<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Services\UsageLimitGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageLimitGuardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_usage_limit_guard_service_handles_unlimited_warning_exceeded_and_unknown_keys(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $guard = app(UsageLimitGuardService::class);

        TenantSetting::setValue($tenant->id, 'limit_users', 'unlimited', 'string');
        $unlimited = $guard->check($tenant->fresh(), 'users');
        $this->assertTrue($unlimited['allowed']);
        $this->assertNull($unlimited['limit']);

        TenantSetting::setValue($tenant->id, 'limit_orders', 3, 'integer');
        $belowLimit = $guard->check($tenant->fresh(), 'orders');
        $this->assertTrue($belowLimit['allowed']);
        $this->assertSame('ok', $belowLimit['status']);

        TenantSetting::setValue($tenant->id, 'limit_orders', 0, 'integer');
        $equalLimit = $guard->check($tenant->fresh(), 'orders');
        $this->assertFalse($equalLimit['allowed']);

        TenantSetting::setValue($tenant->id, 'limit_current_accounts', 0, 'integer');
        $overLimit = $guard->check($tenant->fresh(), 'current_accounts');
        $this->assertFalse($overLimit['allowed']);

        TenantSetting::setValue($tenant->id, 'limit_storage_mb', 300, 'integer');
        TenantSetting::setValue($tenant->id, 'storage_used_mb', 256, 'integer');
        $warning = $guard->check($tenant->fresh(), 'storage_mb');
        $this->assertTrue($warning['allowed']);
        $this->assertSame('warning', $warning['status']);

        $unknown = $guard->check($tenant->fresh(), 'unknown_key');
        $this->assertTrue($unknown['allowed']);
        $this->assertSame('Bu işlem için paket limitiniz doldu.', $guard->messageFor('unknown_key', $unknown));

        $this->assertSame('Bu paket için kullanıcı limiti doldu.', $guard->messageFor('users', $unlimited));
        $this->assertStringNotContainsString('users', $guard->messageFor('users', $unlimited));
        $this->assertStringNotContainsString('current_accounts', $guard->messageFor('current_accounts', $overLimit));
    }
}
