<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Services\TenantSubscriptionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSubscriptionStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_subscription_status_service_resolves_lifecycle_states_safely(): void
    {
        $service = app(TenantSubscriptionStatusService::class);

        $active = TenantAccount::query()->firstOrFail();
        $trial = new TenantAccount(['name' => 'Trial', 'slug' => 'trial', 'status' => 'trial']);
        $trial->setAttribute('trial_ends_at', now()->addDays(7)->toDateString());
        $expired = new TenantAccount(['name' => 'Expired', 'slug' => 'expired', 'status' => 'trial']);
        $expired->setAttribute('trial_ends_at', now()->subDay()->toDateString());
        $suspended = new TenantAccount(['name' => 'Suspended', 'slug' => 'suspended', 'status' => 'suspended']);
        $passive = new TenantAccount(['name' => 'Passive', 'slug' => 'passive', 'status' => 'inactive']);

        $activeStatus = $service->getStatus($active);
        $trialStatus = $service->getStatus($trial);
        $expiredStatus = $service->getStatus($expired);
        $suspendedStatus = $service->getStatus($suspended);
        $passiveStatus = $service->getStatus($passive);

        $this->assertSame('active', $activeStatus['status']);
        $this->assertSame('trial', $trialStatus['status']);
        $this->assertSame('expired', $expiredStatus['status']);
        $this->assertSame('suspended', $suspendedStatus['status']);
        $this->assertSame('passive', $passiveStatus['status']);

        $this->assertTrue($service->isActive($active));
        $this->assertTrue($service->isTrial($trial));
        $this->assertTrue($service->isExpired($expired));
        $this->assertTrue($service->isSuspended($suspended));
        $this->assertTrue($service->isPassive($passive));

        $this->assertTrue($service->canAccessAdmin($active));
        $this->assertTrue($service->canAccessAdmin($trial));
        $this->assertTrue($service->canAccessAdmin($expired));
        $this->assertFalse($service->canAccessAdmin($suspended));
        $this->assertFalse($service->canAccessAdmin($passive));

        $this->assertTrue($service->canCreateOrUpdate($active));
        $this->assertTrue($service->canCreateOrUpdate($trial));
        $this->assertFalse($service->canCreateOrUpdate($expired));
        $this->assertFalse($service->canCreateOrUpdate($suspended));
        $this->assertFalse($service->canCreateOrUpdate($passive));

        $this->assertSame('Aktif', $service->statusLabel('active'));
        $this->assertSame('Deneme', $service->statusLabel('trial'));
        $this->assertSame('Suresi Dolmus', $service->statusLabel('expired'));
    }
}
