<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\Package;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Services\ProcessDepth\TenantProcessDepthPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantProcessDepthPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_capability_map_is_returned(): void
    {
        $policy = app(TenantProcessDepthPolicy::class);
        $map = $policy->forDepth('fast');

        $this->assertSame('compact', $map['operation_card_density']);
        $this->assertFalse($map['show_extended_readiness_details']);
        $this->assertFalse($map['show_evidence_sections']);
        $this->assertArrayNotHasKey('requires_customer_graphic_approval', $map);
    }

    public function test_standard_capability_map_is_returned(): void
    {
        $policy = app(TenantProcessDepthPolicy::class);
        $map = $policy->forDepth('standard');

        $this->assertSame('standard', $map['operation_card_density']);
        $this->assertTrue($map['show_quality_control_section']);
    }

    public function test_controlled_capability_map_is_returned(): void
    {
        $policy = app(TenantProcessDepthPolicy::class);
        $map = $policy->forDepth('controlled');

        $this->assertSame('detailed', $map['operation_card_density']);
        $this->assertTrue($map['show_advanced_activity_timeline']);
    }

    public function test_invalid_depth_normalizes_to_standard_map(): void
    {
        $policy = app(TenantProcessDepthPolicy::class);
        $map = $policy->forDepth('invalid');

        $this->assertSame('standard', $map['operation_card_density']);
    }

    public function test_tenant_helpers_use_resolved_depth(): void
    {
        $package = Package::query()->create([
            'key' => 'fast-package',
            'name' => 'Fast Package',
            'status' => 'active',
            'currency' => 'TRY',
            'process_depth' => 'fast',
        ]);

        $tenant = TenantAccount::factory()->create([
            'package_key' => $package->key,
        ]);

        TenantSetting::setValue($tenant->id, 'process_depth', 'controlled', 'string');

        $policy = app(TenantProcessDepthPolicy::class);

        $this->assertFalse($policy->usesCompactOperationCards($tenant->fresh()));
        $this->assertTrue($policy->showsExtendedReadinessDetails($tenant->fresh()));
        $this->assertSame('detailed', $policy->capability($tenant->fresh(), 'operation_card_density'));
        $this->assertSame('fallback', $policy->capability($tenant->fresh(), 'missing_capability', 'fallback'));
    }
}
