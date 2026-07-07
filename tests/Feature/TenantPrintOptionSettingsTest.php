<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantPrintOption;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPrintOptionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_print_setting_edit_page_allows_creating_and_updating_print_options(): void
    {
        $setting = TenantPrintSetting::query()->where('tenant_account_id', $this->tenant->id)->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.edit', $setting))
            ->assertOk()
            ->assertSee('Baskı Seçenekleri');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.print-settings.options.store', $setting), [
                'name' => 'Tenant Option Test',
                'code' => 'tenant-option-test',
                'description' => 'Kısa açıklama',
                'is_active' => '1',
                'is_default' => '1',
                'sort_order' => '40',
                'requires_setup' => '1',
                'setup_type' => 'cliche',
                'setup_status_default' => 'Yeni üretilecek',
                'default_unit_price' => '7.50',
            ])
            ->assertRedirect(route('admin.settings.print-settings.edit', $setting));

        $option = TenantPrintOption::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('tenant_print_setting_id', $setting->id)
            ->where('code', 'tenant-option-test')
            ->firstOrFail();

        $this->assertTrue($option->is_default);
        $this->assertTrue($option->requires_setup);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.print-settings.options.update', [$setting, $option]), [
                'name' => 'Tenant Option Test Güncel',
                'code' => 'tenant-option-test',
                'description' => 'Yeni açıklama',
                'is_active' => '0',
                'is_default' => '0',
                'sort_order' => '50',
                'requires_setup' => '0',
                'setup_type' => '',
                'setup_status_default' => '',
                'default_unit_price' => '9.00',
            ])
            ->assertRedirect(route('admin.settings.print-settings.edit', $setting));

        $option->refresh();
        $this->assertSame('Tenant Option Test Güncel', $option->name);
        $this->assertFalse($option->is_active);
        $this->assertFalse($option->requires_setup);
    }
}
