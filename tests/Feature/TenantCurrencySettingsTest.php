<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Currency\TenantCurrencySettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantCurrencySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private User $operatorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::factory()->create([
            'default_currency' => 'TRY',
            'panel_subdomain' => 'currency-settings-main',
        ]);
        $this->enableCurrencySettingsAccess($this->tenant, true);

        $this->adminUser = $this->createTenantUser($this->tenant, 'currency-admin', ['manage_users']);
        $this->operatorUser = $this->createTenantUser($this->tenant, 'currency-operator', []);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tenant_admin_can_access_currency_settings_page_with_correct_turkish_text(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.settings.currency'));

        $response->assertOk()
            ->assertSee('Para Birimi ve Kur Ayarları')
            ->assertSee('Kur yönetim merkeziniz')
            ->assertSee('Bu ekran firma ana para birimini, teklifte açık olacak para birimlerini ve kur kullanım davranışını birlikte yönetir.')
            ->assertSee('Ana para birimi değiştiğinde Abone Firma varsayılanı da buna göre güncellenir.')
            ->assertSee('Varsayılan Teklif Para Birimi')
            ->assertSee('Kullanılabilir Teklif Para Birimleri')
            ->assertSee('Kur Güncelleme Yaklaşımı')
            ->assertSee('Teklif sırasında kullanıcı yenilesin')
            ->assertSee('Son Kayıtlı Kurlar');

        $this->assertBrokenTurkishPatternsAreAbsent($response->getContent());
    }

    public function test_currency_settings_page_renders_put_save_form_and_separate_refresh_form_without_nesting(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.settings.currency'));

        $response->assertOk();
        $response->assertSee('action="' . route('admin.settings.currency.update') . '"', false);
        $response->assertSee('name="_method" value="PUT"', false);
        $response->assertSee('<form method="POST" action="' . route('admin.settings.currency.refresh-rates') . '" class="inline">', false);
        $response->assertSee('type="submit" class="pd-btn pd-btn-primary">Kurları Güncelle</button>', false);
        $response->assertSee('type="submit" class="pd-btn pd-btn-primary">Ayarları Kaydet</button>', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'action="' . route('admin.settings.currency.refresh-rates') . '"'));
        $this->assertSame(1, substr_count($html, 'action="' . route('admin.settings.currency.update') . '"'));

        $refreshOpen = strpos($html, '<form method="POST" action="' . route('admin.settings.currency.refresh-rates') . '" class="inline">');
        $refreshClose = strpos($html, '</form>', $refreshOpen);
        $saveOpen = strpos($html, '<form method="POST" action="' . route('admin.settings.currency.update') . '" class="currency-settings-stack">');

        $this->assertNotFalse($refreshOpen);
        $this->assertNotFalse($refreshClose);
        $this->assertNotFalse($saveOpen);
        $this->assertTrue($refreshClose < $saveOpen, 'Refresh formu save formu içinde olmamalı.');
    }

    public function test_settings_menu_shows_currency_link_when_multi_currency_is_enabled(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.settings'));

        $response->assertOk();
        $response->assertSee(route('admin.settings.currency'), false);
        $response->assertSee('Para Birimi ve Kur Ayarları');
    }

    public function test_settings_menu_hides_currency_link_when_multi_currency_is_disabled(): void
    {
        $this->enableCurrencySettingsAccess($this->tenant, false);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.settings'));

        $response->assertOk();
        $response->assertDontSee(route('admin.settings.currency'), false);
        $response->assertDontSee('Para Birimi ve Kur Ayarları');
    }

    public function test_currency_menu_item_is_active_on_currency_settings_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.settings.currency'));

        $response->assertOk();
        $response->assertSee('<a href="' . route('admin.settings.currency') . '" class="pd-sidebar-submenu-item active', false);
    }

    public function test_operator_cannot_access_currency_settings_page(): void
    {
        $this->actingAs($this->operatorUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.settings.currency'))
            ->assertForbidden();
    }

    public function test_cross_tenant_membership_does_not_grant_access_to_another_tenant(): void
    {
        $otherTenant = TenantAccount::factory()->create([
            'panel_subdomain' => 'currency-settings-other',
        ]);
        $this->enableCurrencySettingsAccess($otherTenant, true);

        $otherUser = $this->createTenantUser($otherTenant, 'foreign-admin', ['manage_users']);

        $this->assertFalse($otherUser->belongsToTenant($this->tenant));
        $this->assertTrue($otherUser->belongsToTenant($otherTenant));
        $this->assertFalse($otherUser->hasPermissionInTenant('manage_users', $this->tenant->id));
        $this->assertTrue($otherUser->hasPermissionInTenant('manage_users', $otherTenant->id));
    }

    public function test_currency_whitelist_validation(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put(route('admin.settings.currency.update'), [
                'base_currency' => 'INVALID',
                'default_quote_currency' => 'INVALID',
                'enabled_quote_currencies' => ['INVALID'],
                'currency_rate_source' => 'tcmb',
                'currency_rate_type' => 'forex_selling',
                'currency_stale_after_days' => 2,
                'currency_refresh_policy' => 'manual',
            ])
            ->assertSessionHasErrors(['base_currency', 'default_quote_currency', 'enabled_quote_currencies.0']);
    }

    public function test_default_currency_must_be_in_enabled_list(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put(route('admin.settings.currency.update'), [
                'base_currency' => 'TRY',
                'default_quote_currency' => 'USD',
                'enabled_quote_currencies' => ['TRY', 'EUR'],
                'currency_rate_source' => 'tcmb',
                'currency_rate_type' => 'forex_selling',
                'currency_stale_after_days' => 2,
                'currency_refresh_policy' => 'manual',
            ])
            ->assertSessionHasErrors(['default_quote_currency']);
    }

    public function test_at_least_one_currency_must_be_enabled(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put(route('admin.settings.currency.update'), [
                'base_currency' => 'TRY',
                'default_quote_currency' => 'TRY',
                'enabled_quote_currencies' => [],
                'currency_rate_source' => 'tcmb',
                'currency_rate_type' => 'forex_selling',
                'currency_stale_after_days' => 2,
                'currency_refresh_policy' => 'manual',
            ])
            ->assertSessionHasErrors(['enabled_quote_currencies']);
    }

    public function test_settings_are_persisted_correctly(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put(route('admin.settings.currency.update'), [
                'base_currency' => 'USD',
                'default_quote_currency' => 'USD',
                'enabled_quote_currencies' => ['TRY', 'USD', 'EUR'],
                'currency_rate_source' => 'tcmb',
                'currency_rate_type' => 'forex_selling',
                'currency_stale_after_days' => 3,
                'currency_refresh_policy' => 'manual',
            ])
            ->assertRedirect(route('admin.settings.currency'));

        $service = app(TenantCurrencySettingsService::class);
        $settings = $service->effectiveSettings($this->tenant->fresh());

        $this->assertEquals('USD', $this->tenant->fresh()->default_currency);
        $this->assertEquals('USD', $settings['base_currency']);
        $this->assertEquals('USD', $settings['default_quote_currency']);
        $this->assertEquals(['TRY', 'USD', 'EUR'], $settings['enabled_quote_currencies']);
        $this->assertEquals('tcmb', $settings['rate_source']);
        $this->assertEquals('forex_selling', $settings['rate_type']);
        $this->assertEquals(3, $settings['stale_after_days']);
        $this->assertEquals('manual', $settings['refresh_policy']);
    }

    public function test_base_currency_change_updates_tenant_default_currency(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put(route('admin.settings.currency.update'), [
                'base_currency' => 'EUR',
                'default_quote_currency' => 'EUR',
                'enabled_quote_currencies' => ['TRY', 'USD', 'EUR'],
                'currency_rate_source' => 'tcmb',
                'currency_rate_type' => 'forex_selling',
                'currency_stale_after_days' => 2,
                'currency_refresh_policy' => 'manual',
            ])
            ->assertRedirect(route('admin.settings.currency'));

        $this->assertSame('EUR', $this->tenant->fresh()->default_currency);
        $settings = app(TenantCurrencySettingsService::class)->effectiveSettings($this->tenant->fresh());
        $this->assertSame('EUR', $settings['base_currency']);
    }

    public function test_save_redirects_with_success_message_and_does_not_create_duplicate_setting_rows(): void
    {
        $payload = [
            'base_currency' => 'EUR',
            'default_quote_currency' => 'USD',
            'enabled_quote_currencies' => ['TRY', 'USD', 'EUR'],
            'currency_rate_source' => 'tcmb',
            'currency_rate_type' => 'forex_selling',
            'currency_stale_after_days' => 3,
            'currency_refresh_policy' => 'manual',
        ];

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put(route('admin.settings.currency.update'), $payload)
            ->assertRedirect(route('admin.settings.currency'))
            ->assertSessionHas('success', 'Para birimi ve kur ayarları kaydedildi.');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put(route('admin.settings.currency.update'), $payload)
            ->assertRedirect(route('admin.settings.currency'));

        $this->assertSame('EUR', $this->tenant->fresh()->default_currency);
        $this->assertSame(1, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'default_currency')->count());
        $this->assertSame(1, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'default_quote_currency')->count());
        $this->assertSame(1, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'enabled_quote_currencies')->count());
        $this->assertSame(1, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'currency_rate_source')->count());
        $this->assertSame(1, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'currency_rate_type')->count());
        $this->assertSame(1, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'currency_stale_after_days')->count());
        $this->assertSame(1, TenantSetting::query()->where('tenant_account_id', $this->tenant->id)->where('key', 'currency_refresh_policy')->count());
    }

    public function test_post_to_currency_settings_save_endpoint_is_not_allowed(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post(route('admin.settings.currency'), [
                'base_currency' => 'TRY',
                'default_quote_currency' => 'USD',
                'enabled_quote_currencies' => ['TRY', 'USD'],
                'currency_rate_source' => 'tcmb',
                'currency_rate_type' => 'forex_selling',
                'currency_stale_after_days' => 2,
                'currency_refresh_policy' => 'manual',
            ])
            ->assertMethodNotAllowed();
    }

    public function test_refresh_route_uses_fallback_date_and_remains_idempotent(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00');

        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '12072026.xml') => Http::response('', 404),
                str_contains($request->url(), '11072026.xml') => Http::response('', 404),
                str_contains($request->url(), '10072026.xml') => Http::response($this->tcmbXml('10/07/2026', '46.8927', '53.6159'), 200),
                default => Http::response('', 500),
            };
        });

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post(route('admin.settings.currency.refresh-rates'))
            ->assertRedirect(route('admin.settings.currency'))
            ->assertSessionHas('success', 'Kurlar güncellendi. Kullanılan tarih: 2026-07-10');

        $this->assertSame(2, ExchangeRate::query()->count());
        $this->assertDatabaseHas('exchange_rates', ['provider' => 'tcmb', 'rate_type' => 'forex_selling', 'source_currency' => 'USD', 'target_currency' => 'TRY', 'rate_date' => '2026-07-10 00:00:00']);
        $this->assertDatabaseHas('exchange_rates', ['provider' => 'tcmb', 'rate_type' => 'forex_selling', 'source_currency' => 'EUR', 'target_currency' => 'TRY', 'rate_date' => '2026-07-10 00:00:00']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post(route('admin.settings.currency.refresh-rates'))
            ->assertRedirect(route('admin.settings.currency'))
            ->assertSessionHas('success', 'Kurlar güncellendi. Kullanılan tarih: 2026-07-10');

        $this->assertSame(2, ExchangeRate::query()->count());
    }

    public function test_refresh_failure_preserves_existing_rates_and_hides_technical_details(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00');

        $this->seedRate('USD', '2026-07-10', '46.1111');
        $this->seedRate('EUR', '2026-07-10', '52.2222');

        Http::fake(fn () => Http::response('<xml>broken', 200));

        $response = $this->followingRedirects()
            ->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post(route('admin.settings.currency.refresh-rates'));

        $response->assertOk()
            ->assertSee('Kur verisi okunamadı. Son kayıtlı kurlar korunuyor.')
            ->assertDontSee('ExchangeRateProviderException')
            ->assertDontSee('Stack trace')
            ->assertDontSee('C:\\laragon\\www\\prodelya_core');

        $this->assertSame(2, ExchangeRate::query()->count());
        $this->assertSame('46.11110000', (string) ExchangeRate::query()->where('source_currency', 'USD')->value('rate'));
        $this->assertSame('52.22220000', (string) ExchangeRate::query()->where('source_currency', 'EUR')->value('rate'));
    }

    public function test_currency_route_is_not_available_when_multi_currency_module_is_disabled(): void
    {
        $this->enableCurrencySettingsAccess($this->tenant, false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get(route('admin.settings.currency'))
            ->assertForbidden();
    }

    public function test_multi_currency_disabled_tenant_can_only_use_try(): void
    {
        $this->enableCurrencySettingsAccess($this->tenant, false);

        $service = app(TenantCurrencySettingsService::class);
        $settings = $service->effectiveSettings($this->tenant->fresh());

        $this->assertEquals(['TRY'], $settings['enabled_quote_currencies']);
        $this->assertEquals('TRY', $settings['default_quote_currency']);
        $this->assertTrue($settings['multi_currency_enabled'] === false);
    }

    private function createTenantUser(TenantAccount $tenant, string $key, array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::factory()->create([
            'tenant_account_id' => $tenant->id,
            'key' => $key,
            'name' => ucfirst(str_replace('-', ' ', $key)),
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        UserRole::factory()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function enableCurrencySettingsAccess(TenantAccount $tenant, bool $multiCurrencyEnabled): void
    {
        TenantModule::query()->updateOrCreate([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'tenant_settings',
            'feature_key' => null,
        ], ['is_enabled' => true]);

        TenantModule::query()->updateOrCreate([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'multi_currency',
            'feature_key' => null,
        ], ['is_enabled' => $multiCurrencyEnabled]);
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }

    private function assertBrokenTurkishPatternsAreAbsent(string $html): void
    {
        foreach (['y?net', 'Varsay?lan', 'Kullan?labilir', 'Yakla??m', '�', 'Ã'] as $pattern) {
            $this->assertStringNotContainsString($pattern, $html);
        }
    }

    private function seedRate(string $sourceCurrency, string $rateDate, string $rate): void
    {
        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => $sourceCurrency,
            'target_currency' => 'TRY',
            'rate_date' => $rateDate,
            'source_unit' => 1,
            'rate' => $rate,
            'fetched_at' => Carbon::parse($rateDate . ' 15:30:00'),
            'payload_hash' => sha1($sourceCurrency . $rateDate . $rate),
            'meta_json' => ['source_date' => $rateDate],
        ]);
    }

    private function tcmbXml(string $date, string $usd, string $eur): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Tarih_Date Tarih="{$date}" Date="{$date}">
    <Currency CurrencyCode="USD"><Unit>1</Unit><ForexSelling>{$usd}</ForexSelling></Currency>
    <Currency CurrencyCode="EUR"><Unit>1</Unit><ForexSelling>{$eur}</ForexSelling></Currency>
</Tarih_Date>
XML;
    }
}


