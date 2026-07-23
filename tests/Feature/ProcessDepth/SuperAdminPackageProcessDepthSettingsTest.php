<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\Package;
use App\Models\PackageFeature;
use App\Models\PackageLimit;
use App\Models\PackageModule;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminPackageProcessDepthSettingsTest extends TestCase
{
    use RefreshDatabase;
    protected bool $seed = true;



    private const CENTRAL_HOST = 'prodelya_core.test';
    private const TENANT_HOST = 'demo.prodelya.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_package_create_form_shows_process_depth_field_with_turkish_labels_and_standard_default(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.packages.create'));

        $response->assertOk()
            ->assertSee('Varsayılan Süreç Derinliği')
            ->assertSee('Hızlı Akış')
            ->assertSee('Standart Akış')
            ->assertSee('Kontrollü Akış')
            ->assertSee('Bu seçim paketi kullanan Abone Firmalar için varsayılan çalışma şeklini belirler. Abone Firma kendi ayarından farklı bir seçim yapabilir.')
            ->assertSee('Lisans değil')
            ->assertSee('option value="standard" selected', false);
    }

    public function test_package_store_and_update_persist_process_depth_without_touching_modules_features_or_limits(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.packages.store'), [
                'key' => 'process-depth-suite',
                'name' => 'Process Depth Suite',
                'description' => 'UI scope package',
                'status' => 'active',
                'is_public' => '1',
                'trial_days' => 14,
                'monthly_price' => '900',
                'yearly_price' => '9000',
                'currency' => 'TRY',
                'process_depth' => 'fast',
                'sort_order' => 21,
                'notes' => 'initial note',
            ])
            ->assertRedirect();

        $package = Package::query()->where('key', 'process-depth-suite')->firstOrFail();

        $this->assertSame('fast', $package->getRawOriginal('process_depth'));
        $this->assertSame('Hızlı Akış', $package->processDepthLabel());

        PackageModule::query()->create([
            'package_id' => $package->id,
            'module_key' => 'order_flow',
            'is_enabled' => true,
            'status' => 'active',
        ]);
        PackageFeature::query()->create([
            'package_id' => $package->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => true,
            'status' => 'active',
        ]);
        PackageLimit::query()->create([
            'package_id' => $package->id,
            'limit_key' => 'users',
            'limit_value' => 15,
            'is_unlimited' => false,
            'notes' => 'core team',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.packages.update', $package), [
                'key' => 'process-depth-suite',
                'name' => 'Process Depth Suite Updated',
                'description' => 'updated package',
                'status' => 'passive',
                'is_public' => '0',
                'trial_days' => 30,
                'monthly_price' => '1200',
                'yearly_price' => '12000',
                'currency' => 'USD',
                'process_depth' => 'controlled',
                'sort_order' => 24,
                'notes' => 'updated note',
            ])
            ->assertRedirect(route('admin.super.packages.edit', $package));

        $package->refresh();

        $this->assertSame('controlled', $package->getRawOriginal('process_depth'));
        $this->assertSame('Kontrollü Akış', $package->processDepthLabel());
        $this->assertSame(1, $package->modules()->count());
        $this->assertSame(1, $package->features()->count());
        $this->assertSame(1, $package->limits()->count());

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.packages.show', $package));

        $show->assertOk()
            ->assertSee('Varsayılan Süreç Derinliği')
            ->assertSee('Kontrollü Akış');
    }

    public function test_invalid_process_depth_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.super.packages.create'))
            ->post(route('admin.super.packages.store'), [
                'key' => 'process-depth-invalid',
                'name' => 'Process Depth Invalid',
                'description' => 'invalid',
                'status' => 'active',
                'is_public' => '1',
                'trial_days' => 7,
                'monthly_price' => '100',
                'yearly_price' => '1000',
                'currency' => 'TRY',
                'process_depth' => 'invalid-depth',
                'sort_order' => 5,
                'notes' => 'bad value',
            ]);

        $response->assertRedirect(route('admin.super.packages.create'))
            ->assertSessionHasErrors(['process_depth']);

        $this->assertNull(Package::query()->where('key', 'process-depth-invalid')->first());
    }

    public function test_tenant_context_cannot_access_super_admin_package_process_depth_surface(): void
    {
        $tenant = TenantAccount::query()->firstOrCreate(
            ['panel_subdomain' => 'demo'],
            [
                'name' => 'Demo Tenant',
                'legal_name' => 'Demo Tenant Ltd.',
                'slug' => 'demo',
                'status' => 'active',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'number_format_locale' => 'tr_TR',
            ]
        );

        $this->actingAs($this->adminUser)
            ->get('http://' . self::TENANT_HOST . '/admin/super-admin/packages/create')
            ->assertForbidden();

        $this->assertSame('demo', $tenant->panel_subdomain);
    }
}
