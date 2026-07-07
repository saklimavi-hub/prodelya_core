<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantPrintOption;
use App\Models\TenantPrintSetting;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPrintOptionModelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tenant_print_option_supports_basic_fields_and_tenant_scope(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        app(TenantPrintSettingSyncService::class)->syncForTenant($tenant);
        $setting = TenantPrintSetting::query()->where('tenant_account_id', $tenant->id)->firstOrFail();

        $option = TenantPrintOption::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_print_setting_id' => $setting->id,
            'standard_print_type_id' => $setting->standard_print_type_id,
            'name' => 'Model Test Opsiyonu',
            'code' => 'model-test-option',
            'is_active' => true,
            'sort_order' => 25,
            'is_default' => true,
            'default_unit_price' => 12.75,
            'requires_setup' => true,
            'setup_type' => 'cliche',
            'setup_status_default' => 'Yeni üretilecek',
        ]);

        $this->assertTrue($option->is_active);
        $this->assertTrue($option->is_default);
        $this->assertTrue($option->requires_setup);
        $this->assertSame(25, $option->sort_order);
        $this->assertSame($tenant->id, $option->tenant->id);
        $this->assertSame($setting->id, $option->tenantPrintSetting->id);
        $this->assertSame('Model Test Opsiyonu', $option->displayName());
        $this->assertSame('12.7500', (string) $option->default_unit_price);
    }
}
